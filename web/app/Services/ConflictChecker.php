<?php

namespace App\Services;

use App\Models\User;
use App\Support\Occurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ConflictChecker
{
    private const PRIORITY_RANK = ['low' => 0, 'medium' => 1, 'high' => 2];

    public function __construct(
        private readonly ScheduleOccurrences $occurrences,
    ) {}

    /**
     * Conflicts a candidate occurrence would have against the user's existing schedule.
     *
     * @return Collection<int, array{type: string, message: string, occurrence: Occurrence}>
     */
    public function check(User $user, Carbon $start, Carbon $end, string $priority, ?int $excludeScheduleId = null, array $excludeScheduleIds = []): Collection
    {
        // Include yesterday's start times because an overnight entry can overlap
        // a candidate even when its occurrence began on the previous calendar day.
        $from = $start->copy()->startOfDay()->subDay();
        $to = $end->copy()->startOfDay()->addDay();

        return $this->conflictsAgainst($this->occurrences->between($user, $from, $to), $start, $end, $priority, $excludeScheduleId, $excludeScheduleIds);
    }

    /**
     * Check a whole recurrence using one occurrence query instead of querying once per date.
     *
     * @param  list<array{start: Carbon, end: Carbon}>  $intervals
     * @return Collection<int, array{type: string, message: string, occurrence: Occurrence, candidate_start: Carbon}>
     */
    public function checkMany(User $user, array $intervals, string $priority, ?int $excludeScheduleId = null, array $excludeScheduleIds = []): Collection
    {
        if ($intervals === []) {
            return collect();
        }
        $starts = collect($intervals)->pluck('start');
        $ends = collect($intervals)->pluck('end');
        $from = $starts->min()->copy()->startOfDay()->subDay();
        $to = $ends->max()->copy()->startOfDay()->addDay();
        $existing = $this->occurrences->between($user, $from, $to);

        return collect($intervals)->flatMap(fn (array $interval) => $this->conflictsAgainst(
            $existing,
            $interval['start'],
            $interval['end'],
            $priority,
            $excludeScheduleId,
            $excludeScheduleIds,
        )->map(fn ($conflict) => $conflict + ['candidate_start' => $interval['start']]))->values();
    }

    private function conflictsAgainst(Collection $occurrences, Carbon $start, Carbon $end, string $priority, ?int $excludeScheduleId, array $excludeScheduleIds): Collection
    {
        return $occurrences
            ->filter(fn (Occurrence $occurrence) => $occurrence->schedule->id !== $excludeScheduleId
                && ! in_array($occurrence->schedule->id, $excludeScheduleIds, true))
            ->reject(fn (Occurrence $occurrence) => $occurrence->outcome === 'cancelled')
            ->filter(fn (Occurrence $occurrence) => $occurrence->start->lt($end) && $occurrence->end->gt($start))
            ->map(function (Occurrence $occurrence) use ($priority) {
                $isPriorityClash = self::PRIORITY_RANK[$priority] < self::PRIORITY_RANK[$occurrence->schedule->priority];

                return [
                    'type' => $isPriorityClash ? 'priority' : 'overlap',
                    'message' => $isPriorityClash
                        ? "Clashes with a higher priority entry: {$occurrence->schedule->title}"
                        : "Overlaps with: {$occurrence->schedule->title}",
                    'occurrence' => $occurrence,
                ];
            })
            ->values();
    }
}
