<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\ScheduleOccurrenceOutcome;
use App\Models\User;
use App\Support\Occurrence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ScheduleOccurrences
{
    private const STEP_DAYS = ['daily' => 1, 'weekly' => 7];

    /**
     * Every occurrence of the user's entries that starts in [$from, $to), sorted by start time.
     * $from and $to are expected to be at midnight.
     *
     * @return Collection<int, Occurrence>
     */
    public function between(User $user, Carbon $from, Carbon $to): Collection
    {
        $schedules = $user->schedules()
            ->where(function (Builder $query) use ($from, $to) {
                $query->where(fn (Builder $q) => $q->where('is_recurring', false)
                    ->where('start_datetime', '>=', $from)
                    ->where('start_datetime', '<', $to))
                    ->orWhere(fn (Builder $q) => $q->where('is_recurring', true)
                        ->where('start_datetime', '<', $to));
            })
            ->get();

        $outcomes = ScheduleOccurrenceOutcome::query()
            ->whereIn('schedule_id', $schedules->modelKeys())
            ->where('occurrence_start', '>=', $from)
            ->where('occurrence_start', '<', $to)
            ->get()
            ->keyBy(fn (ScheduleOccurrenceOutcome $outcome) => $outcome->schedule_id.'|'.$outcome->occurrence_start->format('Y-m-d H:i:s'));

        return $schedules
            ->flatMap(fn (Schedule $schedule) => $this->expand($schedule, $from, $to, $outcomes))
            ->sortBy(fn (Occurrence $occurrence) => $occurrence->start->getTimestamp())
            ->values();
    }

    /**
     * @return list<Occurrence>
     */
    private function expand(Schedule $schedule, Carbon $from, Carbon $to, Collection $outcomes): array
    {
        $step = self::STEP_DAYS[$schedule->recurrence_rule] ?? null;

        if (! $schedule->is_recurring || $step === null) {
            $inRange = $schedule->start_datetime->gte($from) && $schedule->start_datetime->lt($to);

            return $inRange ? [$this->makeOccurrence($schedule, $schedule->start_datetime, $schedule->end_datetime, $outcomes)] : [];
        }

        $duration = (int) abs($schedule->start_datetime->diffInMinutes($schedule->end_datetime));
        $first = $schedule->start_datetime->copy();

        // Jump straight to the first occurrence on or after $from instead of looping from the original date.
        if ($first->copy()->startOfDay()->lt($from)) {
            $daysBehind = (int) round($first->copy()->startOfDay()->diffInDays($from));
            $first->addDays((int) ceil($daysBehind / $step) * $step);
        }

        $occurrences = [];
        for ($start = $first; $start->lt($to); $start = $start->copy()->addDays($step)) {
            $occurrences[] = $this->makeOccurrence($schedule, $start->copy(), $start->copy()->addMinutes($duration), $outcomes);
        }

        return $occurrences;
    }

    private function makeOccurrence(Schedule $schedule, Carbon $start, Carbon $end, Collection $outcomes): Occurrence
    {
        $key = $schedule->id.'|'.$start->format('Y-m-d H:i:s');

        return new Occurrence($schedule, $start, $end, $outcomes->get($key)?->outcome);
    }
}
