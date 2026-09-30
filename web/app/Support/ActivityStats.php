<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pure functions over activity logs, so streak and completion-chart math can be
 * unit tested against a fixed "today" instead of the real clock.
 */
class ActivityStats
{
    /**
     * Consecutive days up to today with a completed log. A missed or skipped day
     * breaks the streak; an unlogged day breaks it too, unless that day is today
     * (today may simply not be logged yet).
     *
     * @param  Collection<int, \App\Models\ActivityLog>  $logs
     */
    public static function streak(Collection $logs, ?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $byDate = $logs->keyBy(fn ($log) => $log->scheduled_for->toDateString());

        $streak = 0;
        $date = $today->copy();

        while (true) {
            $log = $byDate->get($date->toDateString());

            if ($log?->status === 'completed') {
                $streak++;
            } elseif ($log !== null || ! $date->isSameDay($today)) {
                break;
            }

            $date = $date->copy()->subDay();
        }

        return $streak;
    }

    /**
     * Percentage of logs completed on each of the last $days days (oldest first),
     * across every activity in $logs.
     *
     * @param  Collection<int, \App\Models\ActivityLog>  $logs
     * @return list<array{date: string, label: string, percentage: int}>
     */
    public static function completionByDay(Collection $logs, int $days, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $start = $today->copy()->subDays($days - 1);

        $byDate = $logs->groupBy(fn ($log) => $log->scheduled_for->toDateString());

        return collect(range(0, $days - 1))->map(function (int $offset) use ($start, $byDate) {
            $date = $start->copy()->addDays($offset);
            $dayLogs = $byDate->get($date->toDateString(), collect());
            $total = $dayLogs->count();
            $completed = $dayLogs->where('status', 'completed')->count();

            return [
                'date' => $date->toDateString(),
                'label' => $date->format('D'),
                'percentage' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            ];
        })->all();
    }
}
