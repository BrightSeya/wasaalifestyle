<?php

namespace App\Http\Controllers;

use App\Enums\Category;
use App\Models\Activity;
use App\Models\Recommendation;
use App\Models\Schedule;
use App\Models\ScheduleOccurrenceOutcome;
use App\Models\User;
use App\Services\ConflictChecker;
use App\Services\ConflictNotificationService;
use App\Services\RecommendationEvidence;
use App\Services\RecommendationService;
use App\Services\ScheduleOccurrences;
use App\Support\Occurrence;
use App\Support\RecurrenceLimits;
use App\Support\UserPreferences;
use App\Support\UserTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RecommendationController extends Controller
{
    public function index(Request $request, RecommendationService $service, RecommendationEvidence $evidence, ConflictChecker $conflicts, ScheduleOccurrences $occurrences): View
    {
        $user = $request->user();
        $timezone = UserTime::timezone($user);
        $now = Carbon::now($timezone);
        $from = $now->copy()->startOfDay();
        $to = $from->copy()->addDays(7);
        $upcomingOccurrences = $occurrences->between($user, $from, $to)
            ->filter(fn (Occurrence $occurrence) => ! $occurrence->schedule->is_all_day
                && $occurrence->start->gt($now)
                && $occurrence->outcome !== 'cancelled')
            ->sortBy(fn (Occurrence $occurrence) => $occurrence->start->getTimestamp())
            ->values();
        $occurrencesByKey = $upcomingOccurrences->keyBy(fn (Occurrence $occurrence) => $this->sourceOccurrenceKey(
            $occurrence->schedule->id,
            $occurrence->start,
        ));
        $sourceRecommendations = $user->recommendations()
            ->whereNotNull('source_schedule_id')
            ->whereNotNull('source_occurrence_start')
            ->where('source_occurrence_start', '>=', UserTime::utc($user, $from))
            ->where('source_occurrence_start', '<', UserTime::utc($user, $to))
            ->get();
        $handledOccurrences = [];
        $replacementScheduleIds = [];
        foreach ($sourceRecommendations as $recommendation) {
            $key = $this->sourceOccurrenceKey(
                (int) $recommendation->source_schedule_id,
                UserTime::local($user, $recommendation->source_occurrence_start),
            );
            $handledOccurrences[$key] = true;
            if ($recommendation->status === 'accepted' && $recommendation->replacement_schedule_id) {
                $replacementScheduleIds[] = (int) $recommendation->replacement_schedule_id;
            }
        }

        $error = null;
        foreach ($upcomingOccurrences as $occurrence) {
            $schedule = $occurrence->schedule;
            $key = $this->sourceOccurrenceKey($schedule->id, $occurrence->start);
            if (isset($handledOccurrences[$key]) || in_array($schedule->id, $replacementScheduleIds, true)) {
                continue;
            }

            $result = $service->recommendForSchedule($user, $schedule, $occurrence->start);
            if (! $result['ok']) {
                if (! str_contains($result['message'], 'No available time window')) {
                    $error = $result['message'];
                }

                continue;
            }

            $duration = (int) $occurrence->start->diffInMinutes($occurrence->end);
            $slot = $this->feasibleSlots(
                $user,
                UserPreferences::fromArray($user->preferences),
                $occurrence->start->copy()->startOfDay(),
                $duration,
                $result['recommendations'],
                $conflicts,
                [$schedule->id],
            )->first(fn (array $candidate) => ! $candidate['start']->equalTo($occurrence->start));
            if (! $slot) {
                continue;
            }

            $user->recommendations()->create([
                'activity_id' => $schedule->activity_id,
                'source_schedule_id' => $schedule->id,
                'source_occurrence_start' => UserTime::utc($user, $occurrence->start),
                'source_schedule_fingerprint' => $this->scheduleFingerprint($schedule),
                'category' => $schedule->category,
                'suggested_start' => UserTime::utc($user, $slot['start']),
                'suggested_end' => UserTime::utc($user, $slot['end']),
                'confidence_score' => $slot['confidence'],
                'is_suitable' => true,
                'status' => 'pending',
                'generated_at' => now(),
            ]);
            $handledOccurrences[$key] = true;
        }

        $pendingRecommendations = $user->recommendations()
            ->whereNotNull('source_schedule_id')
            ->whereNotNull('source_occurrence_start')
            ->where('status', 'pending')
            ->where('source_occurrence_start', '>=', UserTime::utc($user, $from))
            ->where('source_occurrence_start', '<', UserTime::utc($user, $to))
            ->with('sourceSchedule.activity')
            ->get()
            ->filter(function (Recommendation $recommendation) use ($occurrencesByKey, $user): bool {
                $key = $this->sourceOccurrenceKey(
                    (int) $recommendation->source_schedule_id,
                    UserTime::local($user, $recommendation->source_occurrence_start),
                );
                $occurrence = $occurrencesByKey->get($key);
                $schedule = $recommendation->sourceSchedule;
                if (! $occurrence || ! $schedule
                    || $occurrence->outcome !== null
                    || ! is_string($recommendation->source_schedule_fingerprint)
                    || ! hash_equals($recommendation->source_schedule_fingerprint, $this->scheduleFingerprint($schedule))) {
                    $recommendation->update(['status' => 'dismissed']);

                    return false;
                }

                return true;
            })
            ->sortByDesc('confidence_score')
            ->values();
        $suggestions = $pendingRecommendations->map(function (Recommendation $recommendation) use ($user, $evidence) {
            $schedule = $recommendation->sourceSchedule;
            $oldStart = UserTime::local($user, $recommendation->source_occurrence_start);
            $duration = (int) abs($schedule->start_datetime->diffInMinutes($schedule->end_datetime));
            $newStart = UserTime::local($user, $recommendation->suggested_start);

            return [
                'recommendation' => $recommendation,
                'schedule' => $schedule,
                'old_start' => $oldStart,
                'old_end' => $oldStart->copy()->addMinutes($duration),
                'new_start' => $newStart,
                'new_end' => $newStart->copy()->addMinutes($duration),
                'confidence' => (float) $recommendation->confidence_score,
                'evidence' => $evidence->forCategory($user, $schedule->category),
            ];
        });
        $suggestions = $suggestions->sortByDesc('confidence')->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $recommendations = new LengthAwarePaginator($suggestions->forPage($page, 4)->values(), $suggestions->count(), 4, $page, ['path' => route('recommendations.index')]);

        return view('recommendations.index', compact('recommendations', 'error'));
    }

    private function sourceOccurrenceKey(int $scheduleId, Carbon $start): string
    {
        return $scheduleId.'|'.$start->format('Y-m-d H:i:s');
    }

    private function scheduleFingerprint(Schedule $schedule): string
    {
        return hash('sha256', json_encode([
            $schedule->id,
            $schedule->title,
            $schedule->category->value,
            $schedule->start_datetime->format('Y-m-d H:i:s'),
            $schedule->end_datetime->format('Y-m-d H:i:s'),
            $schedule->is_recurring,
            $schedule->recurrence_rule,
            $schedule->recurrence_end_date?->toDateString(),
            $schedule->recurrence_count,
            $schedule->updated_at?->format('Y-m-d H:i:s.u'),
        ], JSON_THROW_ON_ERROR));
    }

    public function previewActivityPlan(Request $request, RecommendationService $service, ConflictChecker $conflicts): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['plan', 'day'])],
            'category' => ['required', Rule::enum(Category::class)],
            'duration_minutes' => ['required', 'integer', 'between:5,600'],
            'frequency' => ['required', 'integer', 'between:1,7'],
            'preferred_time_of_day' => ['nullable', 'date_format:H:i'],
            'weekday' => ['required_if:mode,day', 'nullable', 'integer', 'between:1,7'],
            'activity_id' => ['nullable', 'integer'],
            'exclude_times' => ['nullable', 'array'],
            'exclude_times.*' => ['date_format:H:i'],
        ]);
        $activity = isset($data['activity_id'])
            ? $request->user()->activities()->findOrFail($data['activity_id'])
            : null;
        $excludedScheduleIds = $activity?->schedules()->pluck('id')->all() ?? [];
        $category = Category::from($data['category']);
        $timezone = UserTime::timezone($request->user());
        $today = Carbon::now($timezone)->startOfDay();
        $preferences = UserPreferences::fromArray($request->user()->preferences);

        if ($data['mode'] === 'day') {
            $date = $today->copy();
            $offset = ((int) $data['weekday'] - $today->dayOfWeekIso + 7) % 7;
            $date->addDays($offset);
            $result = $service->recommendForDetails($request->user(), $category, $date, (int) $data['duration_minutes']);
            if (! $result['ok']) {
                if (str_contains($result['message'], 'No available time window')) {
                    return response()->json(['weekday' => (int) $data['weekday'], 'date' => $date->toDateString(), 'options' => []]);
                }

                return response()->json(['message' => $result['message']], 503);
            }

            $excludedTimes = array_fill_keys($data['exclude_times'] ?? [], true);
            $options = $this->feasibleSlots($request->user(), $preferences, $date, (int) $data['duration_minutes'], $result['recommendations'], $conflicts, $excludedScheduleIds)
                ->reject(fn ($slot) => isset($excludedTimes[$slot['start']->format('H:i')]))
                ->take(3)
                ->map(fn ($slot) => $this->formatActivityPlanOption($slot))
                ->values();

            return response()->json(['weekday' => (int) $data['weekday'], 'date' => $date->toDateString(), 'options' => $options]);
        }

        $days = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $date = $today->copy()->addDays($offset);
            $result = $service->recommendForDetails($request->user(), $category, $date, (int) $data['duration_minutes']);
            if (! $result['ok']) {
                if (str_contains($result['message'], 'No available time window')) {
                    continue;
                }

                return response()->json(['message' => $result['message']], 503);
            }

            $options = $this->feasibleSlots($request->user(), $preferences, $date, (int) $data['duration_minutes'], $result['recommendations'], $conflicts, $excludedScheduleIds)
                ->take(3)
                ->map(fn ($slot) => $this->formatActivityPlanOption($slot))
                ->values()
                ->all();
            if ($options !== []) {
                $days[] = [
                    'date' => $date->toDateString(),
                    'weekday' => $date->dayOfWeekIso,
                    'weekday_label' => $date->format('l'),
                    'options' => $options,
                ];
            }
        }

        $frequency = (int) $data['frequency'];
        $days = $this->selectSpreadOutActivityDays($days, min($frequency, count($days)));
        $recommendedSessionCount = count($days);
        $recommendedWeekdays = array_fill_keys(array_column($days, 'weekday'), true);
        $preferredTime = $data['preferred_time_of_day']
            ?? ($activity?->preferred_time_of_day ? substr($activity->preferred_time_of_day, 0, 5) : '07:00');
        for ($offset = 0; count($days) < $frequency && $offset < 7; $offset++) {
            $date = $today->copy()->addDays($offset);
            if (isset($recommendedWeekdays[$date->dayOfWeekIso])) {
                continue;
            }

            $days[] = [
                'date' => $date->toDateString(),
                'weekday' => $date->dayOfWeekIso,
                'weekday_label' => $date->format('l'),
                'start_time' => $preferredTime,
                'options' => [],
                'recommendation_missing' => true,
            ];
            $recommendedWeekdays[$date->dayOfWeekIso] = true;
        }
        usort($days, fn (array $left, array $right) => $left['date'] <=> $right['date']);

        return response()->json([
            'frequency' => $frequency,
            'session_count' => count($days),
            'recommended_session_count' => $recommendedSessionCount,
            'days' => $days,
            'message' => $recommendedSessionCount < $frequency
                ? 'Recommended times are shown where available. Other sessions use your preferred time; adjust them as needed.'
                : 'Choose one recommended time for each day, or ask for more times on that day.',
        ]);
    }

    /**
     * @param  array{start: Carbon, end: Carbon, confidence: float}  $slot
     * @return array{start_time: string, start_time_label: string, end_time_label: string, confidence: float}
     */
    private function formatActivityPlanOption(array $slot): array
    {
        return [
            'start_time' => $slot['start']->format('H:i'),
            'start_time_label' => $slot['start']->format('g:i A'),
            'end_time_label' => $slot['end']->format('g:i A'),
            'confidence' => $slot['confidence'],
        ];
    }

    /**
     * @param  list<array{date: string, weekday: int, weekday_label: string, options: list<array<string, mixed>>}>  $days
     * @return list<array{date: string, weekday: int, weekday_label: string, options: list<array<string, mixed>>}>
     */
    private function selectSpreadOutActivityDays(array $days, int $target): array
    {
        $selected = [];
        while (count($selected) < $target && $days !== []) {
            usort($days, function (array $left, array $right) use ($selected): int {
                $leftConfidence = (float) ($left['options'][0]['confidence'] ?? 0);
                $rightConfidence = (float) ($right['options'][0]['confidence'] ?? 0);
                $leftDistance = $selected === [] ? 0 : min(array_map(fn ($day) => $this->weekdayDistance($left['weekday'], $day['weekday']), $selected));
                $rightDistance = $selected === [] ? 0 : min(array_map(fn ($day) => $this->weekdayDistance($right['weekday'], $day['weekday']), $selected));

                return ($rightConfidence + ($rightDistance * 0.1)) <=> ($leftConfidence + ($leftDistance * 0.1));
            });
            $selected[] = array_shift($days);
        }

        usort($selected, fn (array $left, array $right) => $left['date'] <=> $right['date']);

        return $selected;
    }

    private function weekdayDistance(int $left, int $right): int
    {
        $distance = abs($left - $right);

        return min($distance, 7 - $distance);
    }

    public function previewTask(Request $request, RecommendationService $service, RecommendationEvidence $evidence, ConflictChecker $conflicts): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::enum(Category::class)],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'recurrence' => ['nullable', Rule::in(['none', 'daily', 'weekly'])],
            'recurrence_weekday' => ['nullable', 'integer', 'between:1,7'],
            'recurrence_ends' => ['exclude_unless:recurrence,daily,weekly', 'required', Rule::in(['on_date', 'after'])],
            'recurrence_until' => ['nullable', 'required_if:recurrence_ends,on_date', 'date_format:Y-m-d', 'after_or_equal:date'],
            'recurrence_count' => ['nullable', 'required_if:recurrence_ends,after', 'integer', 'between:1,367'],
        ]);
        if ($data['date'] < Carbon::now(UserTime::timezone($request->user()))->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Choose today or a future date in your time zone.']);
        }
        $recurrence = $data['recurrence'] ?? 'none';
        if ($recurrence !== 'none') {
            $this->validateRecurrenceLimit($request, $data, $recurrence);
        }
        $startTime = Carbon::createFromFormat('H:i', $data['start_time']);
        $endTime = Carbon::createFromFormat('H:i', $data['end_time']);
        $duration = (int) $startTime->diffInMinutes($endTime);
        $requestedDate = Carbon::parse($data['date'], UserTime::timezone($request->user()))->startOfDay();
        $category = Category::from($data['category']);
        $recurrenceWeekday = (int) ($data['recurrence_weekday'] ?? $requestedDate->dayOfWeekIso);
        $recurrenceEnds = $recurrence === 'none' ? null : $data['recurrence_ends'];
        $categoryEvidence = $evidence->forCategory($request->user(), $category);
        $preferences = UserPreferences::fromArray($request->user()->preferences);
        $slots = collect();
        $daysWithSlots = 0;

        for ($dayOffset = 0; $dayOffset < 7 && $daysWithSlots < 3; $dayOffset++) {
            $date = $requestedDate->copy()->addDays($dayOffset);
            if ($recurrence === 'weekly' && $date->dayOfWeekIso !== $recurrenceWeekday) {
                continue;
            }
            $result = $service->recommendForDetails($request->user(), $category, $date, $duration);
            if (! $result['ok']) {
                if (str_contains($result['message'], 'No available time window')) {
                    continue;
                }

                return response()->json(['message' => $result['message']], 503);
            }

            $daySlots = $this->feasibleSlots($request->user(), $preferences, $date, $duration, $result['recommendations'], $conflicts)
                ->filter(fn ($slot) => $this->recurringSlotFits($request->user(), $preferences, $slot['start'], $duration, $recurrence, $recurrenceEnds, $data, $conflicts))
                ->take(3)
                ->map(fn ($slot) => [
                    'date' => $date->toDateString(),
                    'date_label' => $date->format('D, M j'),
                    'start' => $slot['start']->toIso8601String(),
                    'end' => $slot['end']->toIso8601String(),
                    'start_time' => $slot['start']->format('H:i'),
                    'end_time' => $slot['end']->format('H:i'),
                    'confidence' => $slot['confidence'],
                    'evidence' => $categoryEvidence,
                ]);

            if ($daySlots->isNotEmpty()) {
                if ($dayOffset === 0) {
                    return response()->json([
                        'message' => 'Choose a time to update the task form. You can review it before saving.',
                        'recommendations' => $daySlots->values(),
                    ]);
                }

                $daysWithSlots++;
                $slots = $slots->merge($daySlots);
            }
        }

        if ($slots->isEmpty()) {
            return response()->json([
                'message' => 'No suitable times were found in the next seven days. Add available hours or shorten the task.',
                'recommendations' => [],
            ]);
        }

        return response()->json([
            'message' => 'No suitable time was found on that date. These times on other days may work.',
            'recommendations' => $slots->values(),
            'alternative_days' => true,
        ]);
    }

    private function validateRecurrenceLimit(Request $request, array $data, string $recurrence): void
    {
        $first = RecurrenceLimits::firstOccurrence(
            Carbon::parse($data['date'], UserTime::timezone($request->user())),
            $recurrence,
            isset($data['recurrence_weekday']) ? (int) $data['recurrence_weekday'] : null,
        );
        $maximumEndDate = RecurrenceLimits::maximumEndDate($first);

        if ($data['recurrence_ends'] === 'on_date') {
            $lastDate = Carbon::parse($data['recurrence_until'], UserTime::timezone($request->user()));
            if ($lastDate->lt($first)) {
                throw ValidationException::withMessages(['recurrence_until' => 'Choose an end date on or after the first occurrence.']);
            }
            if ($lastDate->gt($maximumEndDate)) {
                throw ValidationException::withMessages(['recurrence_until' => RecurrenceLimits::ONE_YEAR_ERROR]);
            }
        }

        if ($data['recurrence_ends'] === 'after') {
            $lastOccurrence = RecurrenceLimits::lastOccurrence($first, $recurrence, (int) $data['recurrence_count']);
            if ($lastOccurrence->gt($maximumEndDate)) {
                throw ValidationException::withMessages(['recurrence_count' => RecurrenceLimits::ONE_YEAR_ERROR]);
            }
        }
    }

    private function recurringSlotFits(User $user, UserPreferences $preferences, Carbon $first, int $duration, string $recurrence, ?string $ending, array $data, ConflictChecker $conflicts): bool
    {
        if ($recurrence === 'none') {
            return true;
        }
        $step = $recurrence === 'daily' ? 1 : 7;
        $count = $ending === 'after' ? (int) $data['recurrence_count'] : null;
        $lastDate = $ending === 'on_date' ? Carbon::parse($data['recurrence_until'], UserTime::timezone($user))->endOfDay() : null;
        if ($lastDate && $first->gt($lastDate)) {
            return false;
        }
        $limit = $count ?? 2000;
        $durationMinutes = $duration;
        $intervals = [];
        for ($index = 0, $date = $first->copy(); $index < $limit; $index++, $date->addDays($step)) {
            if ($lastDate && $date->gt($lastDate)) {
                break;
            }
            $start = $date->copy();
            $end = $start->copy()->addMinutes($durationMinutes);
            if (! $this->fitsAvailability($preferences, $start->copy()->startOfDay(), $start, $end)) {
                return false;
            }
            $intervals[] = ['start' => $start, 'end' => $end];
        }

        return $intervals !== [] && $conflicts->checkMany($user, $intervals, 'medium')->isEmpty();
    }

    private function fitsAvailability(UserPreferences $preferences, Carbon $date, Carbon $start, Carbon $end): bool
    {
        $day = strtolower($date->format('D'));
        foreach ($preferences->availability[$day] as $range) {
            $rangeStart = $date->copy()->setTimeFromTimeString($range['start']);
            $rangeEnd = $date->copy()->setTimeFromTimeString($range['end']);
            if ($start->greaterThanOrEqualTo($rangeStart) && $end->lessThanOrEqualTo($rangeEnd)) {
                return true;
            }
        }

        return false;
    }

    private function feasibleSlots(User $user, UserPreferences $preferences, Carbon $date, int $duration, array $rankedHours, ConflictChecker $conflicts, array $excludedScheduleIds = []): Collection
    {
        $slots = collect();
        foreach ($rankedHours as $rankedHour) {
            if (! is_array($rankedHour) || ! is_numeric($rankedHour['hour'] ?? null)
                || ! is_numeric($rankedHour['confidence'] ?? null) || ($rankedHour['suitable'] ?? true) === false) {
                continue;
            }
            $hour = (int) $rankedHour['hour'];
            if ($hour < 0 || $hour > 23) {
                continue;
            }

            foreach ([0, 30] as $minute) {
                $start = $date->copy()->setTime($hour, $minute);
                $end = $start->copy()->addMinutes($duration);
                if ($start->lessThanOrEqualTo(now()) || ! $this->fitsAvailability($preferences, $date, $start, $end)) {
                    continue;
                }
                if ($conflicts->check($user, $start, $end, 'medium', excludeScheduleIds: $excludedScheduleIds)->isNotEmpty()) {
                    continue;
                }
                $slots->push(['start' => $start, 'end' => $end, 'confidence' => (float) $rankedHour['confidence']]);
            }
        }

        return $slots;
    }

    public function store(Request $request, RecommendationService $service, RecommendationEvidence $evidence, ConflictChecker $conflicts, Activity $activity): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $activity);

        $localNow = Carbon::now(UserTime::timezone($request->user()));
        $excludedHours = $request->user()->recommendations()->where('activity_id', $activity->id)
            ->where('status', 'accepted')
            ->where('generated_at', '>=', now()->subDays(7))->get(['suggested_start'])
            ->filter(fn ($recommendation) => UserTime::local($request->user(), $recommendation->suggested_start)->dayOfWeekIso === $localNow->dayOfWeekIso)
            ->map(fn ($recommendation) => (int) UserTime::local($request->user(), $recommendation->suggested_start)->format('G'))->unique()->all();
        $result = $service->recommend($request->user(), $activity, excludedHours: $excludedHours);

        if (! $result['ok']) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $result['message']], 503);
            }

            return back()->with('error', $result['message']);
        }

        if ($request->expectsJson()) {
            $date = $localNow->copy()->startOfDay();
            $slots = $this->feasibleSlots(
                $request->user(),
                UserPreferences::fromArray($request->user()->preferences),
                $date,
                $activity->duration_minutes,
                $result['recommendations'],
                $conflicts,
            )->take(4)->map(function ($slot) use ($activity, $request, $evidence) {
                $start = $slot['start'];
                $end = $slot['end'];
                $recommendation = $activity->recommendations()->create([
                    'user_id' => $request->user()->id, 'category' => $activity->category,
                    'suggested_start' => UserTime::utc($request->user(), $start), 'suggested_end' => UserTime::utc($request->user(), $end),
                    'confidence_score' => $slot['confidence'], 'is_suitable' => true,
                    'status' => 'pending', 'generated_at' => now(),
                ]);

                return [
                    'id' => $recommendation->id,
                    'start' => $start->toIso8601String(),
                    'end' => $end->toIso8601String(),
                    'date_label' => $start->format('l, M j'),
                    'start_time_label' => $start->format('g:i A'),
                    'end_time_label' => $end->format('g:i A'),
                    'confidence' => (float) $slot['confidence'],
                    'evidence' => $evidence->forActivity($request->user(), $activity),
                ];
            })->values();

            return response()->json(['activity' => $activity->name, 'recommendations' => $slots]);
        }

        $slots = $this->feasibleSlots(
            $request->user(),
            UserPreferences::fromArray($request->user()->preferences),
            $localNow->copy()->startOfDay(),
            $activity->duration_minutes,
            $result['recommendations'],
            $conflicts,
        );
        foreach ($slots as $slot) {
            $start = $slot['start'];
            $activity->recommendations()->create([
                'user_id' => $request->user()->id,
                'category' => $activity->category,
                'suggested_start' => UserTime::utc($request->user(), $start),
                'suggested_end' => UserTime::utc($request->user(), $slot['end']),
                'confidence_score' => $slot['confidence'],
                'is_suitable' => true,
                'status' => 'pending',
                'generated_at' => now(),
            ]);
        }

        return redirect()->route('recommendations.index')->with('status', 'Recommendations ready.');
    }

    public function chooseActivitySlot(Request $request, Activity $activity, ConflictChecker $conflictChecker, ConflictNotificationService $notifications): JsonResponse
    {
        Gate::authorize('update', $activity);
        $data = $request->validate(['recommendation_id' => ['required', 'integer'], 'confirmed' => ['sometimes', 'boolean']]);
        $recommendation = $activity->recommendations()->where('user_id', $request->user()->id)
            ->whereKey($data['recommendation_id'])->where('status', 'pending')->firstOrFail();
        $start = UserTime::local($request->user(), $recommendation->suggested_start);
        $end = $start->copy()->addMinutes($activity->duration_minutes);
        if (! $this->fitsAvailability(UserPreferences::fromArray($request->user()->preferences), $start->copy()->startOfDay(), $start, $end)) {
            return response()->json([
                'message' => 'This suggestion no longer fits your saved availability. Get a fresh recommendation.',
            ], 409);
        }
        $conflicts = $conflictChecker->check($request->user(), $start, $end, 'medium');

        if ($conflicts->isNotEmpty() && ! $request->boolean('confirmed')) {
            return response()->json(['message' => 'This time overlaps with your schedule.', 'conflicts' => $conflicts->pluck('message')->all()], 409);
        }

        $schedule = $request->user()->schedules()->create([
            'title' => $activity->name,
            'category' => $activity->category,
            'start_datetime' => UserTime::utc($request->user(), $start),
            'end_datetime' => UserTime::utc($request->user(), $end),
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        if ($conflicts->isNotEmpty()) {
            $notifications->create($request->user(), $schedule, $conflicts);
        }
        $recommendation->update(['status' => 'accepted']);

        return response()->json(['message' => 'Added to your schedule.']);
    }

    public function accept(Request $request, ConflictChecker $conflictChecker, ConflictNotificationService $notifications, ScheduleOccurrences $occurrences, Recommendation $recommendation): RedirectResponse
    {
        Gate::authorize('manage', $recommendation);

        if ($recommendation->source_schedule_id !== null) {
            return $this->acceptScheduleChange($request, $conflictChecker, $notifications, $occurrences, $recommendation);
        }

        $conflicts = $conflictChecker->check(
            $recommendation->user,
            UserTime::local($recommendation->user, $recommendation->suggested_start),
            UserTime::local($recommendation->user, $recommendation->suggested_end),
            'medium',
        );

        if ($conflicts->isNotEmpty() && ! $request->boolean('confirmed')) {
            return redirect()->route('recommendations.index')
                ->with('conflicts', [$recommendation->id => $conflicts->pluck('message')->all()]);
        }

        $schedule = $recommendation->user->schedules()->create([
            'title' => $recommendation->activity?->name ?? ucfirst($recommendation->category->value),
            'category' => $recommendation->category,
            'start_datetime' => UserTime::utc($recommendation->user, $recommendation->suggested_start),
            'end_datetime' => UserTime::utc($recommendation->user, $recommendation->suggested_end),
            'priority' => 'medium',
            'status' => 'pending',
        ]);

        if ($conflicts->isNotEmpty()) {
            $notifications->create($recommendation->user, $schedule, $conflicts);
        }

        $recommendation->update(['status' => 'accepted']);

        return redirect()->route('recommendations.index')->with('status', 'Added to your schedule.');
    }

    private function acceptScheduleChange(Request $request, ConflictChecker $conflictChecker, ConflictNotificationService $notifications, ScheduleOccurrences $occurrences, Recommendation $recommendation): RedirectResponse
    {
        $user = $request->user();
        $schedule = $user->schedules()->find($recommendation->source_schedule_id);
        if (! $schedule || $recommendation->status !== 'pending'
            || ! is_string($recommendation->source_schedule_fingerprint)
            || ! $recommendation->source_occurrence_start
            || ! hash_equals($recommendation->source_schedule_fingerprint, $this->scheduleFingerprint($schedule))) {
            $recommendation->update(['status' => 'dismissed']);

            return to_route('recommendations.index')->with('error', 'That recommendation is out of date. Review the latest schedule suggestions.');
        }

        $oldStart = UserTime::local($user, $recommendation->source_occurrence_start);
        $dayStart = $oldStart->copy()->startOfDay();
        $occurrence = $occurrences->between($user, $dayStart, $dayStart->copy()->addDay())
            ->first(fn (Occurrence $candidate) => $candidate->schedule->is($schedule)
                && $candidate->start->equalTo($oldStart));
        if (! $occurrence || $occurrence->outcome !== null || $oldStart->lte(Carbon::now(UserTime::timezone($user)))) {
            $recommendation->update(['status' => 'dismissed']);

            return to_route('recommendations.index')->with('error', 'That occurrence is no longer available to reschedule. Review the latest schedule suggestions.');
        }

        $newStart = UserTime::local($user, $recommendation->suggested_start);
        $duration = (int) abs($occurrence->start->diffInMinutes($occurrence->end));
        $newEnd = $newStart->copy()->addMinutes($duration);
        if ($newStart->toDateString() !== $oldStart->toDateString()
            || ! $this->fitsAvailability(UserPreferences::fromArray($user->preferences), $newStart->copy()->startOfDay(), $newStart, $newEnd)) {
            $recommendation->update(['status' => 'dismissed']);

            return to_route('recommendations.index')->with('error', 'The recommended time no longer fits your availability. Review the latest schedule suggestions.');
        }

        $conflicts = $conflictChecker->check(
            $user,
            $newStart,
            $newEnd,
            $schedule->priority,
            excludeScheduleId: $schedule->id,
        );
        if ($conflicts->isNotEmpty() && ! $request->boolean('confirmed')) {
            return to_route('recommendations.index')
                ->with('conflicts', [$recommendation->id => $conflicts->pluck('message')->all()]);
        }

        DB::transaction(function () use ($user, $schedule, $recommendation, $oldStart, $occurrence, $newStart, $newEnd, $conflicts, $notifications): void {
            if ($schedule->is_recurring) {
                $schedule->occurrenceOutcomes()->updateOrCreate(
                    ['occurrence_start' => UserTime::utc($user, $oldStart)],
                    [
                        'occurrence_end' => UserTime::utc($user, $occurrence->end),
                        'outcome' => 'cancelled',
                        'recorded_at' => now(),
                    ],
                );
                $replacement = $user->schedules()->create([
                    'activity_id' => $schedule->activity_id,
                    'title' => $schedule->title,
                    'description' => $schedule->description,
                    'category' => $schedule->category,
                    'start_datetime' => UserTime::utc($user, $newStart),
                    'end_datetime' => UserTime::utc($user, $newEnd),
                    'priority' => $schedule->priority,
                    'is_recurring' => false,
                    'status' => 'pending',
                ]);
            } else {
                $schedule->update([
                    'start_datetime' => UserTime::utc($user, $newStart),
                    'end_datetime' => UserTime::utc($user, $newEnd),
                ]);
                $replacement = $schedule;
            }

            $recommendation->update([
                'status' => 'accepted',
                'replacement_schedule_id' => $replacement->id,
            ]);
            if ($conflicts->isNotEmpty()) {
                $notifications->create($user, $replacement, $conflicts);
            }
        });

        $message = $schedule->is_recurring
            ? "Updated only the {$oldStart->format('l, M j')} occurrence of {$schedule->title} from {$oldStart->format('g:i A')} to {$newStart->format('g:i A')}. All other occurrences remain unchanged."
            : "Updated {$schedule->title} from {$oldStart->format('D, M j g:i A')} to {$newStart->format('D, M j g:i A')}.";

        return to_route('recommendations.index')->with('status', $message);
    }

    public function dismiss(Recommendation $recommendation): RedirectResponse
    {
        Gate::authorize('manage', $recommendation);

        $recommendation->update(['status' => 'dismissed']);

        return to_route('recommendations.index')->with('status', 'Recommendation dismissed.');
    }
}
