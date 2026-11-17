<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ScheduleOccurrenceOutcome;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Build privacy-preserving, decayed outcome counts for one user's recommendation request.
 */
class UserRecommendationFeedback
{
    private const LOOKBACK_DAYS = 365;
    private const HALF_LIFE_DAYS = 180;

    /** @return array<string, array{category_completed: float, category_total: float, other_completed: float, other_total: float}> */
    public function for(User $user, string $category, Carbon $asOf): array
    {
        $now = $asOf->copy();
        $cutoff = $now->copy()->subDays(self::LOOKBACK_DAYS);
        $bins = [];

        $activityLogs = ActivityLog::query()
            ->join('activities', 'activities.id', '=', 'activity_logs.activity_id')
            ->where('activity_logs.user_id', $user->getKey())
            ->whereIn('activity_logs.status', ['completed', 'missed'])
            ->where('activity_logs.scheduled_for', '>=', $cutoff)
            ->where('activity_logs.scheduled_for', '<', $now)
            ->get([
                'activities.category as feedback_category',
                'activity_logs.scheduled_for as feedback_at',
                'activity_logs.status as feedback_status',
            ]);

        foreach ($activityLogs as $log) {
            $this->add($bins, $category, $log->feedback_category, Carbon::parse($log->feedback_at), $log->feedback_status, $now);
        }

        $scheduleOutcomes = ScheduleOccurrenceOutcome::query()
            ->join('schedules', 'schedules.id', '=', 'schedule_occurrence_outcomes.schedule_id')
            ->where('schedules.user_id', $user->getKey())
            ->whereIn('schedule_occurrence_outcomes.outcome', ['completed', 'missed'])
            ->where('schedule_occurrence_outcomes.occurrence_start', '>=', $cutoff)
            ->where('schedule_occurrence_outcomes.occurrence_start', '<', $now)
            ->where('schedule_occurrence_outcomes.recorded_at', '<', $now)
            ->get([
                'schedules.category as feedback_category',
                'schedule_occurrence_outcomes.occurrence_start as feedback_at',
                'schedule_occurrence_outcomes.outcome as feedback_status',
            ]);

        foreach ($scheduleOutcomes as $outcome) {
            $this->add($bins, $category, $outcome->feedback_category, Carbon::parse($outcome->feedback_at), $outcome->feedback_status, $now);
        }

        return $bins;
    }

    /** @param array<string, array{category_completed: float, category_total: float, other_completed: float, other_total: float}> $bins */
    private function add(array &$bins, string $targetCategory, string $eventCategory, Carbon $at, string $status, Carbon $now): void
    {
        $key = ($at->dayOfWeekIso - 1).':'.$at->hour;
        $bins[$key] ??= [
            'category_completed' => 0.0,
            'category_total' => 0.0,
            'other_completed' => 0.0,
            'other_total' => 0.0,
        ];

        // Recent feedback carries more weight, allowing the model to follow changing routines.
        $ageDays = max(0, $at->diffInSeconds($now) / 86400);
        $weight = 2 ** (-$ageDays / self::HALF_LIFE_DAYS);
        $prefix = $eventCategory === $targetCategory ? 'category' : 'other';
        $bins[$key][$prefix.'_total'] += $weight;
        if ($status === 'completed') {
            $bins[$key][$prefix.'_completed'] += $weight;
        }
    }
}
