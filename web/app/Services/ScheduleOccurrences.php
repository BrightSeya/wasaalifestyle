<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\ScheduleOccurrenceOutcome;
use App\Models\User;
use App\Support\Occurrence;
use App\Support\UserTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ScheduleOccurrences
{
    private const STEP_DAYS = ['daily' => 1, 'weekly' => 7];

    /**
     * Every occurrence overlapping [$from, $to), sorted by start time. One-off multi-day
     * entries are split into daily segments so day and week views can render them correctly.
     * $from and $to are expected to be at midnight.
     *
     * @return Collection<int, Occurrence>
     */
    public function between(User $user, Carbon $from, Carbon $to): Collection
    {
        $fromUtc = UserTime::utc($user, $from);
        $toUtc = UserTime::utc($user, $to);
        $timezone = UserTime::timezone($user);
        $schedules = $user->schedules()
            ->where(function (Builder $query) use ($fromUtc, $toUtc) {
                $query->where(fn (Builder $q) => $q->where('is_recurring', false)
                    ->where('start_datetime', '<', $toUtc)
                    ->where('end_datetime', '>', $fromUtc))
                    ->orWhere(fn (Builder $q) => $q->where('is_recurring', true)
                        ->where('start_datetime', '<', $toUtc));
            })
            ->get();

        $outcomes = ScheduleOccurrenceOutcome::query()
            ->whereIn('schedule_id', $schedules->modelKeys())
            ->where('occurrence_start', '>=', $fromUtc)
            ->where('occurrence_start', '<', $toUtc)
            ->get()
            ->keyBy(fn (ScheduleOccurrenceOutcome $outcome) => $outcome->schedule_id.'|'.$outcome->occurrence_start->copy()->setTimezone($timezone)->format('Y-m-d H:i:s'));

        return $schedules
            ->flatMap(fn (Schedule $schedule) => $this->expand($schedule, $from, $to, $outcomes, $timezone))
            ->sortBy(fn (Occurrence $occurrence) => $occurrence->start->getTimestamp())
            ->values();
    }

    /**
     * @return list<Occurrence>
     */
    private function expand(Schedule $schedule, Carbon $from, Carbon $to, Collection $outcomes, string $timezone): array
    {
        $step = self::STEP_DAYS[$schedule->recurrence_rule] ?? null;

        if (! $schedule->is_recurring || $step === null) {
            $start = $schedule->start_datetime->copy()->setTimezone($timezone);
            $end = $schedule->end_datetime->copy()->setTimezone($timezone);
            $firstDay = $start->copy()->startOfDay()->max($from);
            $lastDay = $end->copy()->startOfDay();
            if (! $end->isStartOfDay()) {
                $lastDay->addDay();
            }
            $lastDay = $lastDay->min($to);
            $occurrences = [];
            for ($day = $firstDay; $day->lt($lastDay); $day = $day->copy()->addDay()) {
                $dayEnd = $day->copy()->addDay();
                $segmentStart = $start->gt($day) ? $start->copy() : $day->copy();
                $segmentEnd = $end->lt($dayEnd) ? $end->copy() : $dayEnd;
                if ($segmentEnd->gt($segmentStart)) {
                    $occurrences[] = $this->makeOccurrence($schedule, $segmentStart, $segmentEnd, $outcomes);
                }
            }

            return $occurrences;
        }

        $duration = (int) abs($schedule->start_datetime->diffInMinutes($schedule->end_datetime));
        $first = $schedule->start_datetime->copy()->setTimezone($timezone);
        $stepIndex = 0;

        // Jump straight to the first occurrence on or after $from instead of looping from the original date.
        if ($first->copy()->startOfDay()->lt($from)) {
            $daysBehind = (int) round($first->copy()->startOfDay()->diffInDays($from));
            $stepIndex = (int) ceil($daysBehind / $step);
            $first->addDays($stepIndex * $step);
        }

        $occurrences = [];
        for ($start = $first; $start->lt($to); $start = $start->copy()->addDays($step)) {
            if ($schedule->recurrence_end_date && $start->toDateString() > $schedule->recurrence_end_date->toDateString()) {
                break;
            }
            if ($schedule->recurrence_count && $stepIndex >= $schedule->recurrence_count) {
                break;
            }
            $occurrences[] = $this->makeOccurrence($schedule, $start->copy(), $start->copy()->addMinutes($duration), $outcomes);
            $stepIndex++;
        }

        return $occurrences;
    }

    private function makeOccurrence(Schedule $schedule, Carbon $start, Carbon $end, Collection $outcomes): Occurrence
    {
        $key = $schedule->id.'|'.$start->format('Y-m-d H:i:s');

        return new Occurrence($schedule, $start, $end, $outcomes->get($key)?->outcome);
    }
}
