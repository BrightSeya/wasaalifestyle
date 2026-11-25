<?php

namespace App\Services;

use App\Exceptions\GoogleCalendarImportException;
use App\Exceptions\GoogleCalendarReauthorizationRequired;
use App\Exceptions\GoogleCalendarServiceUnavailable;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class GoogleCalendarApiClient
{
    private const EVENTS_ENDPOINT = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    private const TASK_LISTS_ENDPOINT = 'https://tasks.googleapis.com/tasks/v1/users/@me/lists';

    private const TASKS_ENDPOINT = 'https://tasks.googleapis.com/tasks/v1/lists/';

    private const PAGE_SIZE = 100;

    private const MAX_ITEMS = 500;

    public function __construct(private readonly GoogleCalendarOAuthService $oauth) {}

    /** @return array{events: list<array>, truncated: bool} */
    public function listEvents(User $user, Carbon $from, Carbon $to): array
    {
        $connection = $this->connection($user);
        $timeMin = $from->copy()->startOfDay()->toRfc3339String();
        $timeMax = $to->copy()->addDay()->startOfDay()->toRfc3339String();

        return $this->collectPages(
            $connection,
            self::EVENTS_ENDPOINT,
            [
                'timeMin' => $timeMin,
                'timeMax' => $timeMax,
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'fields' => 'items(id,status,summary,description,start,end),nextPageToken',
            ],
            'events',
        );
    }

    /** @return array{tasks: list<array>, truncated: bool} */
    public function listTasks(User $user, Carbon $from, Carbon $to): array
    {
        $connection = $this->connection($user);
        if (! $this->oauth->hasRequiredReadScopes($connection)) {
            throw new GoogleCalendarReauthorizationRequired;
        }

        $dueMin = Carbon::createFromFormat('!Y-m-d', $from->toDateString(), 'UTC')->toRfc3339String();
        $dueMax = Carbon::createFromFormat('!Y-m-d', $to->toDateString(), 'UTC')->endOfDay()->toRfc3339String();
        $tasks = [];
        $truncated = false;
        $listPageToken = null;
        $refreshedAfterUnauthorized = false;

        do {
            $query = ['maxResults' => self::PAGE_SIZE, 'fields' => 'items(id,title),nextPageToken'];
            if ($listPageToken) {
                $query['pageToken'] = $listPageToken;
            }

            $response = $this->authorizedPage(
                $connection,
                self::TASK_LISTS_ENDPOINT,
                $query,
                $refreshedAfterUnauthorized,
                'Google Tasks access was denied. Check that the Google Tasks API is enabled for this project.',
            );
            foreach ($response->json('items', []) as $taskList) {
                if (! is_array($taskList) || ! is_string($taskList['id'] ?? null)) {
                    continue;
                }

                $taskPageToken = null;
                do {
                    $taskQuery = [
                        'dueMin' => $dueMin,
                        'dueMax' => $dueMax,
                        'showCompleted' => 'true',
                        'showHidden' => 'true',
                        'maxResults' => self::PAGE_SIZE,
                        'fields' => 'items(id,title,notes,status,due),nextPageToken',
                    ];
                    if ($taskPageToken) {
                        $taskQuery['pageToken'] = $taskPageToken;
                    }

                    $taskResponse = $this->authorizedPage(
                        $connection,
                        self::TASKS_ENDPOINT.rawurlencode($taskList['id']).'/tasks',
                        $taskQuery,
                        $refreshedAfterUnauthorized,
                        'Google Tasks access was denied. Check that the Google Tasks API is enabled for this project.',
                    );
                    foreach ($taskResponse->json('items', []) as $task) {
                        if (is_array($task)) {
                            $tasks[] = $task + [
                                'task_list_id' => $taskList['id'],
                                'task_list_title' => $taskList['title'] ?? 'Google Tasks',
                            ];
                            if (count($tasks) >= self::MAX_ITEMS) {
                                $truncated = true;
                                break 2;
                            }
                        }
                    }

                    $taskPageToken = $taskResponse->json('nextPageToken');
                    if ($taskPageToken && count($tasks) >= self::MAX_ITEMS) {
                        $truncated = true;
                        break 2;
                    }
                } while ($taskPageToken);
            }

            $listPageToken = $response->json('nextPageToken');
            if ($listPageToken && count($tasks) >= self::MAX_ITEMS) {
                $truncated = true;
                break;
            }
        } while ($listPageToken);

        return ['tasks' => array_slice($tasks, 0, self::MAX_ITEMS), 'truncated' => $truncated];
    }

    private function connection(User $user): GoogleCalendarConnection
    {
        $connection = $user->googleCalendarConnection()->first();
        if (! $connection) {
            throw new GoogleCalendarReauthorizationRequired;
        }

        return $connection;
    }

    private function collectPages(
        GoogleCalendarConnection $connection,
        string $endpoint,
        array $query,
        string $key,
    ): array {
        $items = [];
        $pageToken = null;
        $refreshedAfterUnauthorized = false;

        do {
            $pageQuery = $query + ['maxResults' => self::PAGE_SIZE];
            if ($pageToken) {
                $pageQuery['pageToken'] = $pageToken;
            }
            $response = $this->authorizedPage(
                $connection,
                $endpoint,
                $pageQuery,
                $refreshedAfterUnauthorized,
                'Google Calendar denied event access. Reconnect with read-only event permission.',
            );
            foreach ($response->json('items', []) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $pageToken = $response->json('nextPageToken');
            if ($pageToken && count($items) >= self::MAX_ITEMS) {
                break;
            }
        } while ($pageToken);

        return [$key => array_slice($items, 0, self::MAX_ITEMS), 'truncated' => (bool) $pageToken];
    }

    private function authorizedPage(
        GoogleCalendarConnection $connection,
        string $endpoint,
        array $query,
        bool &$refreshedAfterUnauthorized,
        string $forbiddenMessage,
    ): Response {
        $response = $this->requestPage($connection->access_token, $endpoint, $query);
        if ($response->status() === 401 && ! $refreshedAfterUnauthorized) {
            $refreshedAfterUnauthorized = true;
            $token = $this->oauth->validAccessToken($connection, forceRefresh: true);
            $response = $this->requestPage($token, $endpoint, $query);
        }

        if ($response->status() === 401) {
            $connection->delete();
            throw new GoogleCalendarReauthorizationRequired;
        }
        if ($response->status() === 403) {
            throw new GoogleCalendarImportException($forbiddenMessage);
        }
        if (! $response->successful() || ! is_array($response->json('items', []))) {
            throw new GoogleCalendarServiceUnavailable;
        }

        return $response;
    }

    private function requestPage(string $accessToken, string $endpoint, array $query): Response
    {
        try {
            return Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(10)
                ->get($endpoint, $query);
        } catch (ConnectionException) {
            throw new GoogleCalendarServiceUnavailable;
        }
    }
}
