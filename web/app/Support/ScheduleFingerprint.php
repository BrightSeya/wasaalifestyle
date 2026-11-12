<?php

namespace App\Support;

use App\Models\Schedule;

final class ScheduleFingerprint
{
    public static function for(Schedule $schedule): string
    {
        $original = $schedule->getRawOriginal();
        $fields = [
            'user_id', 'title', 'description', 'category', 'start_datetime', 'end_datetime',
            'priority', 'is_recurring', 'recurrence_rule', 'status', 'google_event_id', 'timezone',
        ];
        $snapshot = [];
        foreach ($fields as $field) {
            $value = $original[$field] ?? null;
            $snapshot[$field] = match ($field) {
                'user_id' => $value === null ? null : (int) $value,
                'is_recurring' => (bool) $value,
                default => $value === null ? null : (string) $value,
            };
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
