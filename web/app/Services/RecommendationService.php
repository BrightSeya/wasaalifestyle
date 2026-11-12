<?php

namespace App\Services;

use App\Enums\Category;
use App\Models\Activity;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class RecommendationService
{
    private const DEFAULT_GAP_HOURS = 24.0;

    public function __construct(
        private readonly ScheduleOccurrences $occurrences,
    ) {}

    /**
     * Ask the slot-recommender API for the top-3 suitable time slots for this activity,
     * on the given day (defaults to today).
     *
     * @return array{ok: true, recommendations: array}|array{ok: false, message: string}
     */
    public function recommend(User $user, Activity $activity, ?Carbon $date = null): array
    {
        $date = ($date ?? now())->copy();
        $day = $date->copy()->startOfDay();

        return $this->requestRecommendations($user, [
            'category' => $activity->category->value,
            'day_of_week' => $day->dayOfWeekIso - 1,
            'duration_min' => $activity->duration_minutes,
            'schedule_density' => $this->scheduleDensity($user, $day),
            'gap_since_last_same_category_hrs' => $this->gapSinceLastSameCategory($user, $activity, $date),
        ]);
    }

    /**
     * Ask the same slot-ranking service for alternatives to one exact commitment occurrence.
     *
     * @return array{ok: true, recommendations: array}|array{ok: false, message: string}
     */
    public function recommendForSchedule(User $user, Schedule $schedule, Carbon $date): array
    {
        $day = $date->copy()->startOfDay();
        $duration = (int) abs($schedule->start_datetime->diffInMinutes($schedule->end_datetime));

        return $this->requestRecommendations($user, [
            'category' => $schedule->category->value,
            'day_of_week' => $day->dayOfWeekIso - 1,
            'duration_min' => $duration,
            'schedule_density' => $this->scheduleDensity($user, $day),
            'gap_since_last_same_category_hrs' => self::DEFAULT_GAP_HOURS,
        ]);
    }

    /** @param array<string, int|float|string> $payload
     * @return array{ok: true, recommendations: array}|array{ok: false, message: string}
     */
    private function requestRecommendations(User $user, array $payload): array
    {
        try {
            $response = Http::baseUrl(config('services.slot_recommender.url'))
                ->timeout(5)
                ->post('/predict', $payload);
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'The recommendation service is unavailable. Please try again shortly.'];
        }

        if ($response->failed()) {
            return ['ok' => false, 'message' => $response->json('error') ?? 'The recommendation service returned an error.'];
        }

        return ['ok' => true, 'recommendations' => $response->json('recommendations')];
    }

    private function scheduleDensity(User $user, Carbon $date): int
    {
        return $this->occurrences->between($user, $date, $date->copy()->addDay())
            ->reject(fn ($occurrence) => $occurrence->outcome === 'cancelled')
            ->count();
    }

    /**
     * Hours since the user last completed an activity of the same category, or 24 if none.
     * Uses scheduled_for (when the activity happened), not logged_at (when it was recorded),
     * and only counts completed logs: a missed activity did not actually occur.
     */
    private function gapSinceLastSameCategory(User $user, Activity $activity, Carbon $date): float
    {
        $lastLog = $user->activityLogs()
            ->whereHas('activity', fn ($query) => $query->where('category', $activity->category->value))
            ->where('status', 'completed')
            ->where('scheduled_for', '<', $date)
            ->orderByDesc('scheduled_for')
            ->first();

        if (! $lastLog) {
            return self::DEFAULT_GAP_HOURS;
        }

        return round($lastLog->scheduled_for->floatDiffInHours($date), 2);
    }
}
