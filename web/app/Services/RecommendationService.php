<?php

namespace App\Services;

use App\Enums\Category;
use App\Models\Activity;
use App\Models\Schedule;
use App\Models\User;
use App\Support\UserPreferences;
use App\Support\UserTime;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class RecommendationService
{
    private const DEFAULT_GAP_HOURS = 24.0;

    public function __construct(
        private readonly ScheduleOccurrences $occurrences,
        private readonly UserRecommendationFeedback $feedback,
    ) {}

    /**
     * Ask the slot-recommender API to rank every available hour for this activity,
     * on the given day (defaults to today).
     *
     * @return array{ok: true, recommendations: array}|array{ok: false, message: string}
     */
    public function recommend(User $user, Activity $activity, ?Carbon $date = null, array $excludedHours = []): array
    {
        $date = ($date ?? Carbon::now(UserTime::timezone($user)))->copy()->setTimezone(UserTime::timezone($user));

        return $this->recommendForDetails(
            $user,
            $activity->category,
            $date,
            $activity->duration_minutes,
            $this->gapSinceLastSameCategory($user, $activity, $date),
            $excludedHours,
        );
    }

    public function recommendForDetails(User $user, Category $category, Carbon $date, int $duration, float $gapHours = self::DEFAULT_GAP_HOURS, array $excludedHours = []): array
    {
        $day = $date->copy()->startOfDay();
        $preferences = UserPreferences::fromArray($user->preferences);

        return $this->requestRecommendations($user, [
            'category' => $category->value,
            'day_of_week' => $day->dayOfWeekIso - 1,
            'duration_min' => $duration,
            'schedule_density' => $this->scheduleDensity($user, $day),
            'gap_since_last_same_category_hrs' => $gapHours,
            'exclude_hours' => array_values(array_unique(array_map('intval', $excludedHours))),
            'top_k' => 24,
            ...$this->preferenceHours($preferences, $day, $duration),
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
        $preferences = UserPreferences::fromArray($user->preferences);

        return $this->requestRecommendations($user, [
            'category' => $schedule->category->value,
            'day_of_week' => $day->dayOfWeekIso - 1,
            'duration_min' => $duration,
            'schedule_density' => $this->scheduleDensity($user, $day),
            'gap_since_last_same_category_hrs' => self::DEFAULT_GAP_HOURS,
            'top_k' => 24,
            ...$this->preferenceHours($preferences, $day, $duration),
        ]);
    }

    /** Translate custom availability ranges into hours containing at least one feasible half-hour start. */
    private function preferenceHours(UserPreferences $preferences, Carbon $day, int $duration): array
    {
        $dayKey = strtolower($day->format('D'));
        $available = [];
        foreach ($preferences->availability[$dayKey] as $range) {
            [$startHour, $startMinute] = array_map('intval', explode(':', $range['start']));
            [$endHour, $endMinute] = array_map('intval', explode(':', $range['end']));
            $start = ($startHour * 60) + $startMinute;
            $end = ($endHour * 60) + $endMinute;

            for ($candidate = (int) (ceil($start / 30) * 30); $candidate + $duration <= $end; $candidate += 30) {
                $available[] = intdiv($candidate, 60);
            }
        }

        return [
            'available_hours' => array_values(array_unique($available)),
            'preferred_hours' => range($preferences->peakStartHour, $preferences->peakEndHour - 1),
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array{ok: true, recommendations: array}|array{ok: false, message: string}
     */
    private function requestRecommendations(User $user, array $payload): array
    {
        if (UserPreferences::fromArray($user->preferences)->personalizeRecommendations) {
            $recommendationData = $this->feedback->recommendationData(
                $user,
                (string) $payload['category'],
                Carbon::now(UserTime::timezone($user)),
            );
            $payload['user_feedback'] = $recommendationData['user_feedback'];
            $payload['personal_history'] = $recommendationData['personal_history'];
        }

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
            ->where('scheduled_for', '<', UserTime::utc($user, $date))
            ->orderByDesc('scheduled_for')
            ->first();

        if (! $lastLog) {
            return self::DEFAULT_GAP_HOURS;
        }

        return round(UserTime::local($user, $lastLog->scheduled_for)->floatDiffInHours($date), 2);
    }
}
