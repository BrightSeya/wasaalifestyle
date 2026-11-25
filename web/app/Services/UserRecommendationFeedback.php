<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ScheduleOccurrenceOutcome;
use App\Models\User;
use App\Support\UserTime;
use Illuminate\Support\Carbon;

/**
 * Build privacy-preserving, decayed outcome counts for one user's recommendation request.
 */
class UserRecommendationFeedback
{
    private const LOOKBACK_DAYS = 365;

    private const HALF_LIFE_DAYS = 180;

    private const MAX_TRAINING_EXAMPLES = 2000;

    /**
     * @return array{
     *     user_feedback: array<string, array{category_completed: float, category_total: float, other_completed: float, other_total: float}>,
     *     personal_history: list<array{category: string, day_of_week: int, hour: int, duration_min: int, schedule_density: int, gap_since_last_same_category_hrs: float, label: int, weight: float, occurred_at: int}>
     * }
     */
    public function recommendationData(User $user, string $category, Carbon $asOf): array
    {
        $now = $asOf->copy();
        $cutoff = $now->copy()->subDays(self::LOOKBACK_DAYS);

        $activityLogs = ActivityLog::query()
            ->join('activities', 'activities.id', '=', 'activity_logs.activity_id')
            ->where('activity_logs.user_id', $user->getKey())
            ->whereIn('activity_logs.status', ['completed', 'missed'])
            ->where('activity_logs.scheduled_for', '>=', UserTime::utc($user, $cutoff))
            ->where('activity_logs.scheduled_for', '<', UserTime::utc($user, $now))
            ->get([
                'activities.category as feedback_category',
                'activities.duration_minutes as feedback_duration',
                'activity_logs.scheduled_for as feedback_at',
                'activity_logs.status as feedback_status',
            ]);

        $scheduleOutcomes = ScheduleOccurrenceOutcome::query()
            ->join('schedules', 'schedules.id', '=', 'schedule_occurrence_outcomes.schedule_id')
            ->where('schedules.user_id', $user->getKey())
            ->whereIn('schedule_occurrence_outcomes.outcome', ['completed', 'missed'])
            ->where('schedule_occurrence_outcomes.occurrence_start', '>=', UserTime::utc($user, $cutoff))
            ->where('schedule_occurrence_outcomes.occurrence_start', '<', UserTime::utc($user, $now))
            ->where('schedule_occurrence_outcomes.recorded_at', '<', $now)
            ->get([
                'schedules.category as feedback_category',
                'schedules.start_datetime as scheduled_start',
                'schedules.end_datetime as scheduled_end',
                'schedule_occurrence_outcomes.occurrence_start as feedback_at',
                'schedule_occurrence_outcomes.occurrence_end as feedback_end',
                'schedule_occurrence_outcomes.outcome as feedback_status',
            ]);

        $events = [];
        foreach ($activityLogs as $log) {
            $at = UserTime::local($user, Carbon::parse($log->feedback_at));
            $events[] = [
                'category' => (string) $log->feedback_category,
                'at' => $at,
                'duration_min' => max(1, (int) $log->feedback_duration),
                'status' => (string) $log->feedback_status,
            ];
        }
        foreach ($scheduleOutcomes as $outcome) {
            $start = UserTime::local($user, Carbon::parse($outcome->feedback_at));
            $duration = $outcome->feedback_end
                ? abs((int) Carbon::parse($outcome->feedback_at)->diffInMinutes(Carbon::parse($outcome->feedback_end)))
                : abs((int) Carbon::parse($outcome->scheduled_start)->diffInMinutes(Carbon::parse($outcome->scheduled_end)));
            $events[] = [
                'category' => (string) $outcome->feedback_category,
                'at' => $start,
                'duration_min' => max(1, $duration),
                'status' => (string) $outcome->feedback_status,
            ];
        }

        $uniqueEvents = [];
        foreach ($events as $event) {
            $key = implode('|', [
                $event['category'],
                $event['at']->copy()->utc()->format('Y-m-d H:i:s'),
                $event['status'],
            ]);
            $uniqueEvents[$key] ??= $event;
        }
        $events = collect($uniqueEvents)->sortBy(fn ($event) => $event['at']->getTimestamp())->values();

        $bins = [];
        $dailyCounts = $events->countBy(fn ($event) => $event['at']->toDateString());
        $previousByCategory = [];
        $trainingExamples = [];

        foreach ($events as $event) {
            /** @var Carbon $at */
            $at = $event['at'];
            $dayHour = ($at->dayOfWeekIso - 1).':'.$at->hour;
            $ageDays = max(0, $at->diffInSeconds($now) / 86400);
            $weight = 2 ** (-$ageDays / self::HALF_LIFE_DAYS);
            $prefix = $event['category'] === $category ? 'category' : 'other';

            $bins[$dayHour] ??= [
                'category_completed' => 0.0,
                'category_total' => 0.0,
                'other_completed' => 0.0,
                'other_total' => 0.0,
            ];
            $bins[$dayHour][$prefix.'_total'] += $weight;
            if ($event['status'] === 'completed') {
                $bins[$dayHour][$prefix.'_completed'] += $weight;
            }

            $previous = $previousByCategory[$event['category']] ?? null;
            $gapHours = $previous
                ? round($previous->diffInSeconds($at) / 3600, 2)
                : 24.0;
            $previousByCategory[$event['category']] = $at;

            $trainingExamples[] = [
                'category' => $event['category'],
                'day_of_week' => $at->dayOfWeekIso - 1,
                'hour' => $at->hour,
                'duration_min' => $event['duration_min'],
                'schedule_density' => max(0, (int) $dailyCounts[$at->toDateString()] - 1),
                'gap_since_last_same_category_hrs' => $gapHours,
                'label' => $event['status'] === 'completed' ? 1 : 0,
                'weight' => $weight,
                'occurred_at' => $at->getTimestamp(),
            ];
        }

        return [
            'user_feedback' => $bins,
            'personal_history' => array_slice($trainingExamples, -self::MAX_TRAINING_EXAMPLES),
        ];
    }

    /** @return array<string, array{category_completed: float, category_total: float, other_completed: float, other_total: float}> */
    public function for(User $user, string $category, Carbon $asOf): array
    {
        return $this->recommendationData($user, $category, $asOf)['user_feedback'];
    }
}
