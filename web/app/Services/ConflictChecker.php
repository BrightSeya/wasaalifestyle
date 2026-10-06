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
    public function check(User $user, Carbon $start, Carbon $end, string $priority, ?int $excludeScheduleId = null): Collection
    {
        $from = $start->copy()->startOfDay();
        $to = $end->copy()->startOfDay()->addDay();

        return $this->occurrences->between($user, $from, $to)
            ->filter(fn (Occurrence $occurrence) => $occurrence->schedule->id !== $excludeScheduleId)
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
