<?php

namespace App\Services;

use App\Models\User;
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

    public function preview(User $user, string $dateFrom, string $dateTo, string $category): array
    {
        $from = Carbon::createFromFormat('!Y-m-d', $dateFrom, config('app.timezone'));
        $to = Carbon::createFromFormat('!Y-m-d', $dateTo, config('app.timezone'));
        $result = $this->calendar->listEvents($user, $from, $to);
        $events = [];
        $unsupported = [];
        $seen = [];

        foreach ($result['events'] as $event) {
            if (($event['status'] ?? null) === 'cancelled') {
                continue;
            }

            [$mapped, $reason] = $this->mapEvent($event, $category);
            if ($reason !== null) {
                $unsupported[] = ['title' => $this->eventTitle($event), 'reason' => $reason];

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
            'category' => $category,
            'events' => $events,
            'unsupported' => $unsupported,
            'truncated' => $result['truncated'],
        ]);
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
                'category' => $event['category'],
                'start_datetime' => $event['start_datetime'],
                'end_datetime' => $event['end_datetime'],
                'timezone' => $event['timezone'],
                'google_event_id' => $event['google_event_id'],
                'priority' => 'medium',
                'is_recurring' => false,
                'recurrence_rule' => null,
                'status' => 'pending',
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

            $start = Carbon::parse($event['start_datetime'], config('app.timezone'));
            $end = Carbon::parse($event['end_datetime'], config('app.timezone'));
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
    private function mapEvent(array $event, string $category): array
    {
        $start = $event['start'] ?? [];
        $end = $event['end'] ?? [];
        if (isset($start['date']) || isset($end['date'])) {
            return [null, 'All-day events are not supported yet.'];
        }
        if (! is_string($event['id'] ?? null) || $event['id'] === '') {
            return [null, 'Google did not provide an event ID.'];
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
        if ($startTime->toDateString() !== $endTime->toDateString()) {
            return [null, 'Multi-day timed events are not supported yet.'];
        }

        $storageTimezone = config('app.timezone');
        $title = $this->eventTitle($event);

        return [[
            'google_event_id' => $event['id'],
            'title' => Str::limit($title, 255, ''),
            'category' => $category,
            'start_datetime' => $startTime->copy()->setTimezone($storageTimezone)->format('Y-m-d H:i:s'),
            'end_datetime' => $endTime->copy()->setTimezone($storageTimezone)->format('Y-m-d H:i:s'),
            'timezone' => $timezone,
            'display_start' => $startTime->format('M j, Y g:i A'),
            'display_end' => $endTime->format('g:i A'),
        ], null];
    }

    private function eventTitle(array $event): string
    {
        $summary = trim((string) ($event['summary'] ?? ''));

        return $summary !== '' ? $summary : 'Untitled Google Calendar event';
    }
}
