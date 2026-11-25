<?php

namespace App\Services;

use App\Enums\Category;
use App\Models\User;
use App\Support\UserTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class GoogleCalendarImportService
{
    public function __construct(
        private readonly GoogleCalendarApiClient $calendar,
        private readonly ConflictChecker $conflicts,
    ) {}

    public function preview(User $user, string $dateFrom, string $dateTo): array
    {
        $from = Carbon::createFromFormat('!Y-m-d', $dateFrom, UserTime::timezone($user));
        $to = Carbon::createFromFormat('!Y-m-d', $dateTo, UserTime::timezone($user));
        $eventResult = $this->calendar->listEvents($user, $from, $to);
        $taskResult = $this->calendar->listTasks($user, $from, $to);
        $sourceItems = array_merge(
            array_map(fn (array $event) => ['type' => 'event', 'item' => $event], $eventResult['events']),
            array_map(fn (array $task) => ['type' => 'task', 'item' => $task], $taskResult['tasks']),
        );
        $truncated = $eventResult['truncated'] || $taskResult['truncated'] || count($sourceItems) > 500;
        $sourceItems = array_slice($sourceItems, 0, 500);
        $events = [];
        $unsupported = [];
        $seen = [];

        foreach ($sourceItems as $sourceItem) {
            $item = $sourceItem['item'];
            $type = $sourceItem['type'];
            if ($type === 'event' && ($item['status'] ?? null) === 'cancelled') {
                continue;
            }

            [$mapped, $reason] = $type === 'task'
                ? $this->mapTask($item, $user, UserTime::timezone($user))
                : $this->mapEvent($item, UserTime::timezone($user));
            if ($reason !== null) {
                $unsupported[] = ['title' => $this->itemTitle($item, $type), 'reason' => $reason];

                continue;
            }

            if (isset($seen[$mapped['google_event_id']])) {
                continue;
            }
            $seen[$mapped['google_event_id']] = true;
            $events[] = $mapped;
        }

        return $this->summarize($user, [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'events' => $events,
            'unsupported' => $unsupported,
            'truncated' => $truncated,
        ]);
    }

    private function mapTask(array $task, User $user, string $userTimezone): array
    {
        if (! is_string($task['id'] ?? null) || $task['id'] === '') {
            return [null, 'Google did not provide a task ID.'];
        }
        if (! is_string($task['due'] ?? null) || $task['due'] === '') {
            return [null, 'This Google Task has no due date.'];
        }

        try {
            $dueDate = Carbon::parse($task['due'], 'UTC')->toDateString();
            $start = Carbon::createFromFormat('!Y-m-d', $dueDate, $userTimezone);
            $end = $start->copy()->addDay();
        } catch (Throwable) {
            return [null, 'This Google Task has an invalid due date.'];
        }

        $timezone = config('app.timezone');
        $title = $this->itemTitle($task, 'task');

        return [[
            'google_event_id' => 'task:'.($task['task_list_id'] ?? 'default').':'.$task['id'],
            'title' => Str::limit($title, 255, ''),
            'description' => is_string($task['notes'] ?? null) ? $task['notes'] : null,
            'category' => Category::Work->value,
            'is_all_day' => true,
            'status' => ($task['status'] ?? null) === 'completed' ? 'completed' : 'pending',
            'start_datetime' => UserTime::utc($user, $start)->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'end_datetime' => UserTime::utc($user, $end)->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'timezone' => $userTimezone,
            'display_start' => 'All day · '.$start->format('M j, Y'),
            'display_end' => 'All day',
            'source_type' => 'Google Task',
        ], null];
    }

    public function confirm(User $user, array $preview): array
    {
        $current = $this->summarize($user, $preview);
        if ($current['duplicate_ids'] !== $preview['duplicate_ids'] || $current['conflicts'] !== $preview['conflicts']) {
            return ['confirmed' => false, 'preview' => $current];
        }

        $duplicates = array_fill_keys($current['duplicate_ids'], true);
        $rows = [];
        foreach ($current['events'] as $event) {
            if (isset($duplicates[$event['google_event_id']])) {
                continue;
            }

            $rows[] = [
                'user_id' => $user->id,
                'title' => $event['title'],
                'description' => $event['description'] ?? null,
                'category' => $event['category'],
                'start_datetime' => $event['start_datetime'],
                'end_datetime' => $event['end_datetime'],
                'timezone' => $event['timezone'],
                'google_event_id' => $event['google_event_id'],
                'priority' => 'medium',
                'is_recurring' => false,
                'recurrence_rule' => null,
                'is_all_day' => $event['is_all_day'],
                'status' => $event['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $inserted = DB::transaction(fn () => $rows === [] ? 0 : DB::table('schedules')->insertOrIgnore($rows));

        return [
            'confirmed' => true,
            'imported_count' => $inserted,
            'duplicate_count' => count($current['duplicate_ids']) + count($rows) - $inserted,
            'unsupported_count' => count($current['unsupported']),
        ];
    }

    private function summarize(User $user, array $preview): array
    {
        $eventIds = array_column($preview['events'], 'google_event_id');
        $duplicateIds = $user->schedules()
            ->whereIn('google_event_id', $eventIds)
            ->pluck('google_event_id')
            ->all();
        sort($duplicateIds);
        $duplicateSet = array_fill_keys($duplicateIds, true);
        $conflicts = [];

        foreach ($preview['events'] as $event) {
            if (isset($duplicateSet[$event['google_event_id']])) {
                continue;
            }

            $start = UserTime::local($user, $event['start_datetime']);
            $end = UserTime::local($user, $event['end_datetime']);
            $matches = $this->conflicts->check($user, $start, $end, 'medium')
                ->map(fn (array $conflict) => [
                    'schedule_id' => $conflict['occurrence']->schedule->id,
                    'title' => $conflict['occurrence']->schedule->title,
                    'type' => $conflict['type'],
                    'message' => $conflict['message'],
                ])
                ->values()
                ->all();

            if ($matches !== []) {
                $conflicts[$event['google_event_id']] = $matches;
            }
        }
        ksort($conflicts);

        return array_merge($preview, [
            'duplicate_ids' => $duplicateIds,
            'conflicts' => $conflicts,
        ]);
    }

    /** @return array{?array, ?string} */
    private function mapEvent(array $event, string $userTimezone): array
    {
        if (! is_string($event['id'] ?? null) || $event['id'] === '') {
            return [null, 'Google did not provide an event ID.'];
        }
        $start = $event['start'] ?? [];
        $end = $event['end'] ?? [];
        if (isset($start['date']) || isset($end['date'])) {
            if (! is_string($start['date'] ?? null) || ! is_string($end['date'] ?? null)) {
                return [null, 'The all-day event does not have a supported date range.'];
            }

            try {
                $startDate = Carbon::createFromFormat('!Y-m-d', $start['date'], $userTimezone);
                $endDate = Carbon::createFromFormat('!Y-m-d', $end['date'], $userTimezone);
            } catch (Throwable) {
                return [null, 'The all-day event has an invalid date range.'];
            }
            if ($endDate->lessThanOrEqualTo($startDate)) {
                return [null, 'The event end must be after its start.'];
            }

            return [$this->mappedItem(
                $event,
                $event['id'],
                $startDate,
                $endDate,
                $userTimezone,
                true,
                'pending',
                'Google Calendar event',
                isset($event['description']) && is_string($event['description']) ? $event['description'] : null,
                'All day · '.$startDate->format('M j, Y'),
                $endDate->copy()->subDay()->equalTo($startDate) ? 'All day' : 'Through '.$endDate->copy()->subDay()->format('M j, Y'),
            ), null];
        }
        if (! is_string($start['dateTime'] ?? null) || ! is_string($end['dateTime'] ?? null)) {
            return [null, 'The event does not have a supported timed start and end.'];
        }

        try {
            $startTime = Carbon::parse($start['dateTime']);
            $timezone = $start['timeZone'] ?? $startTime->timezoneName;
            $startTime->setTimezone($timezone);
            $endTime = Carbon::parse($end['dateTime'])->setTimezone($timezone);
        } catch (Throwable) {
            return [null, 'The event has an unsupported or invalid time zone.'];
        }
        if ($endTime->lessThanOrEqualTo($startTime)) {
            return [null, 'The event end must be after its start.'];
        }

        return [$this->mappedItem(
            $event,
            $event['id'],
            $startTime,
            $endTime,
            $timezone,
            false,
            'pending',
            'Google Calendar event',
            isset($event['description']) && is_string($event['description']) ? $event['description'] : null,
            $startTime->copy()->setTimezone($userTimezone)->format('M j, Y g:i A'),
            $endTime->copy()->setTimezone($userTimezone)->format('g:i A'),
        ), null];
    }

    private function mappedItem(
        array $item,
        string $googleId,
        Carbon $start,
        Carbon $end,
        string $timezone,
        bool $isAllDay,
        string $status,
        string $sourceType,
        ?string $description,
        string $displayStart,
        string $displayEnd,
    ): array {
        $storageTimezone = config('app.timezone');

        return [
            'google_event_id' => $googleId,
            'title' => Str::limit($this->itemTitle($item, 'event'), 255, ''),
            'description' => $description,
            'category' => Category::Work->value,
            'is_all_day' => $isAllDay,
            'status' => $status,
            'start_datetime' => $start->copy()->setTimezone($storageTimezone)->format('Y-m-d H:i:s'),
            'end_datetime' => $end->copy()->setTimezone($storageTimezone)->format('Y-m-d H:i:s'),
            'timezone' => $timezone,
            'display_start' => $displayStart,
            'display_end' => $displayEnd,
            'source_type' => $sourceType,
        ];
    }

    private function itemTitle(array $item, string $type): string
    {
        $titleKey = $type === 'task' ? 'title' : 'summary';
        $title = trim((string) ($item[$titleKey] ?? ''));

        if ($title !== '') {
            return $title;
        }

        return $type === 'task' ? 'Untitled Google Task' : 'Untitled Google Calendar event';
    }
}
