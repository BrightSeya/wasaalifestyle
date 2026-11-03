<?php

namespace App\Services;

use App\Exceptions\GoogleCalendarImportException;
use App\Exceptions\GoogleCalendarReauthorizationRequired;
use App\Exceptions\GoogleCalendarServiceUnavailable;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class GoogleCalendarApiClient
{
    private const EVENTS_ENDPOINT = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    private const PAGE_SIZE = 250;

    private const MAX_EVENTS = 500;

    public function __construct(private readonly GoogleCalendarOAuthService $oauth) {}

    /** @return array{events: list<array>, truncated: bool} */
    public function listEvents(User $user, Carbon $from, Carbon $to): array
    {
        $connection = $user->googleCalendarConnection()->first();
        if (! $connection) {
            throw new GoogleCalendarReauthorizationRequired;
        }

        $timeMin = $from->copy()->startOfDay()->toRfc3339String();
        $timeMax = $to->copy()->addDay()->startOfDay()->toRfc3339String();
        $events = [];
        $pageToken = null;
        $refreshedAfterUnauthorized = false;

        do {
            $query = [
                'timeMin' => $timeMin,
                'timeMax' => $timeMax,
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => min(self::PAGE_SIZE, self::MAX_EVENTS - count($events)),
                'fields' => 'items(id,status,summary,start,end),nextPageToken',
            ];
            if ($pageToken) {
                $query['pageToken'] = $pageToken;
            }

            $response = $this->requestPage($connection->access_token, $query);
            if ($response->status() === 401 && ! $refreshedAfterUnauthorized) {
                $refreshedAfterUnauthorized = true;
                $token = $this->oauth->validAccessToken($connection, forceRefresh: true);
                $response = $this->requestPage($token, $query);
            }

            if ($response->status() === 401) {
                $connection->delete();
                throw new GoogleCalendarReauthorizationRequired;
            }
            if ($response->status() === 403) {
                throw new GoogleCalendarImportException('Google Calendar denied event access. Reconnect with read-only event permission.');
            }
            $items = $response->json('items', []);
            if (! $response->successful() || ! is_array($items)) {
                throw new GoogleCalendarServiceUnavailable;
            }

            foreach ($items as $event) {
                if (is_array($event)) {
                    $events[] = $event;
                }
            }

            $pageToken = $response->json('nextPageToken');
        } while ($pageToken && count($events) < self::MAX_EVENTS);

        return [
            'events' => array_slice($events, 0, self::MAX_EVENTS),
            'truncated' => (bool) $pageToken,
        ];
    }

    private function requestPage(string $accessToken, array $query): Response
    {
        try {
            return Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(10)
                ->get(self::EVENTS_ENDPOINT, $query);
        } catch (ConnectionException) {
            throw new GoogleCalendarServiceUnavailable;
        }
    }
}
