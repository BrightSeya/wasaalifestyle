<?php

namespace App\Services;

use App\Enums\Category;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\ScheduleOccurrenceOutcome;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;

class RecommendationEvidence
{
    public function forActivity(User $user, Activity $activity): string
    {
        $outcomes = $this->recentOutcomes($user, $activity->category, $activity->getKey());

        if ($outcomes->isEmpty()) {
            $categoryOutcomes = $this->recentOutcomes($user, $activity->category);
            if ($categoryOutcomes->isEmpty()) {
                return "No recent {$activity->name} outcomes are logged yet. This cold-start suggestion uses ATUS time-use patterns and will personalize as you record outcomes.";
            }

            $completed = $categoryOutcomes->where('status', 'completed')->count();

            return "Across your {$activity->category->value} activities, you completed {$completed} of {$categoryOutcomes->count()} logged sessions in the last 7 days. There is not activity-specific history for {$activity->name} yet.";
        }

        $completed = $outcomes->where('status', 'completed')->count();

        return "Your recent history: {$completed} of {$outcomes->count()} logged {$activity->name} sessions were completed in the last 7 days.";
    }

    public function forCategory(User $user, Category $category): string
    {
        $outcomes = $this->recentOutcomes($user, $category);

        if ($outcomes->isEmpty()) {
            return "No recent {$category->value} activity outcomes are logged yet. This cold-start suggestion uses ATUS time-use patterns and will personalize as you record outcomes.";
        }

        $completed = $outcomes->where('status', 'completed')->count();

        return "Your recent {$category->value} history: {$completed} of {$outcomes->count()} logged activity sessions were completed in the last 7 days.";
    }

    private function recentOutcomes(User $user, Category $category, ?int $activityId = null): Collection
    {
        $from = Carbon::today()->subDays(6);
        $until = Carbon::tomorrow();
        $activityLogs = ActivityLog::query()
            ->join('activities', 'activities.id', '=', 'activity_logs.activity_id')
            ->where('activity_logs.user_id', $user->getKey())
            ->where('activities.category', $category->value)
            ->when($activityId, fn ($query) => $query->where('activity_logs.activity_id', $activityId))
            ->whereIn('activity_logs.status', ['completed', 'missed', 'skipped'])
            ->where('activity_logs.scheduled_for', '>=', $from)
            ->where('activity_logs.scheduled_for', '<', $until)
            ->get(['activity_logs.status']);

        if ($activityId !== null) {
            return $activityLogs;
        }

        $scheduleOutcomes = ScheduleOccurrenceOutcome::query()
            ->join('schedules', 'schedules.id', '=', 'schedule_occurrence_outcomes.schedule_id')
            ->where('schedules.user_id', $user->getKey())
            ->where('schedules.category', $category->value)
            ->whereIn('schedule_occurrence_outcomes.outcome', ['completed', 'missed'])
            ->where('schedule_occurrence_outcomes.occurrence_start', '>=', $from)
            ->where('schedule_occurrence_outcomes.occurrence_start', '<', $until)
            ->get(['schedule_occurrence_outcomes.outcome as status']);

        return $activityLogs->concat($scheduleOutcomes)->values();
    }
}
