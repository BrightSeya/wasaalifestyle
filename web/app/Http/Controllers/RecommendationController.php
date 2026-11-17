<?php

namespace App\Http\Controllers;

use App\Enums\Category;
use App\Models\Activity;
use App\Models\Recommendation;
use App\Services\ConflictChecker;
use App\Services\ConflictNotificationService;
use App\Services\RecommendationService;
use App\Services\RecommendationEvidence;
use App\Support\ScheduleFingerprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RecommendationController extends Controller
{
    public function index(Request $request, RecommendationService $service, RecommendationEvidence $evidence, ConflictChecker $conflicts): View
    {
        if ($request->query('type') === 'alerts') {
            $recommendations = $request->user()->recommendations()
                ->with(['activity', 'sourceSchedule'])
                ->whereNotNull('source_schedule_id')
                ->where('status', 'pending')
                ->orderByDesc('confidence_score')
                ->paginate(4)
                ->withQueryString();

            return view('recommendations.index', ['recommendations' => $recommendations, 'error' => null, 'type' => 'alerts']);
        }

        $suggestions = collect();
        $suggestedStartTimes = [];
        $error = null;

        foreach ($request->user()->activities()->orderBy('name')->get() as $activity) {
            $date = now()->startOfDay();
            $excludedHours = $request->user()->recommendations()
                ->where('activity_id', $activity->id)
                ->whereNull('source_schedule_id')
                ->where('generated_at', '>=', now()->subDays(7))
                ->get(['suggested_start'])
                ->filter(fn ($recommendation) => $recommendation->suggested_start->dayOfWeekIso === $date->dayOfWeekIso)
                ->map(fn ($recommendation) => (int) $recommendation->suggested_start->format('G'))
                ->unique()->all();
            $result = $service->recommend($request->user(), $activity, $date, $excludedHours);
            if (! $result['ok']) {
                $error = $result['message'];
                continue;
            }

            $preferredHour = (int) substr((string) $activity->preferred_time_of_day, 0, 2);
            foreach ($result['recommendations'] as $slot) {
                if (! is_array($slot) || ! is_numeric($slot['hour'] ?? null) || ! is_numeric($slot['confidence'] ?? null)) {
                    continue;
                }
                $hour = (int) $slot['hour'];
                if ($hour < 0 || $hour > 23 || $hour === $preferredHour || ($slot['suitable'] ?? true) === false) {
                    continue;
                }
                $start = $date->copy()->setTime($hour, 0);
                if ($start->lessThanOrEqualTo(now())) {
                    $start->addDay();
                }
                $slotKey = $start->format('Y-m-d H:i');
                if (isset($suggestedStartTimes[$slotKey])) {
                    continue;
                }
                $end = $start->copy()->addMinutes($activity->duration_minutes);
                if ($conflicts->check($request->user(), $start, $end, 'medium')->isNotEmpty()) {
                    continue;
                }
                $suggestedStartTimes[$slotKey] = true;
                $activity->recommendations()->create([
                    'user_id' => $request->user()->id,
                    'category' => $activity->category,
                    'suggested_start' => $start,
                    'suggested_end' => $end,
                    'confidence_score' => $slot['confidence'],
                    'is_suitable' => $slot['suitable'] ?? null,
                    'status' => 'pending',
                    'generated_at' => now(),
                ]);
                $suggestions->push(['activity' => $activity, 'start' => $start, 'end' => $end, 'confidence' => (float) $slot['confidence'], 'evidence' => $evidence->forActivity($request->user(), $activity)]);
                break;
            }
        }

        $suggestions = $suggestions->sortByDesc('confidence')->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $recommendations = new LengthAwarePaginator($suggestions->forPage($page, 4)->values(), $suggestions->count(), 4, $page, ['path' => route('recommendations.index')]);

        return view('recommendations.index', compact('recommendations', 'error') + ['type' => 'activities']);
    }

    public function previewTask(Request $request, RecommendationService $service, RecommendationEvidence $evidence, ConflictChecker $conflicts): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', \Illuminate\Validation\Rule::enum(Category::class)],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);
        $date = Carbon::parse($data['date']);
        $startTime = Carbon::createFromFormat('H:i', $data['start_time']);
        $endTime = Carbon::createFromFormat('H:i', $data['end_time']);
        $duration = (int) $startTime->diffInMinutes($endTime);
        $excludedHours = $request->user()->recommendations()
            ->whereNull('activity_id')->whereNull('source_schedule_id')->where('category', $data['category'])
            ->where('generated_at', '>=', now()->subDays(7))
            ->get(['suggested_start'])
            ->filter(fn ($recommendation) => $recommendation->suggested_start->dayOfWeekIso === $date->dayOfWeekIso)
            ->map(fn ($recommendation) => (int) $recommendation->suggested_start->format('G'))->unique()->all();
        $result = $service->recommendForDetails($request->user(), Category::from($data['category']), $date, $duration, excludedHours: $excludedHours);

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 503);
        }

        $slots = collect($result['recommendations'])->filter(fn ($slot) => is_array($slot) && is_numeric($slot['hour'] ?? null) && is_numeric($slot['confidence'] ?? null))
            ->map(function ($slot) use ($date, $duration, $request, $data, $evidence) {
                $start = $date->copy()->setTime((int) $slot['hour'], 0);
                if ($start->lessThanOrEqualTo(now())) {
                    $start->addDay();
                }
                $end = $start->copy()->addMinutes($duration);
                $recommendation = $request->user()->recommendations()->create([
                    'category' => $data['category'], 'suggested_start' => $start, 'suggested_end' => $end,
                    'confidence_score' => $slot['confidence'], 'is_suitable' => $slot['suitable'] ?? null,
                    'status' => 'pending', 'generated_at' => now(),
                ]);
                return ['id' => $recommendation->id, 'start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'confidence' => (float) $slot['confidence'], 'evidence' => $evidence->forCategory($request->user(), Category::from($data['category']))];
            })->take(4)->values();

        return response()->json(['recommendations' => $slots]);
    }

    public function store(Request $request, RecommendationService $service, RecommendationEvidence $evidence, Activity $activity): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $activity);

        $excludedHours = $request->user()->recommendations()->where('activity_id', $activity->id)->whereNull('source_schedule_id')
            ->where('generated_at', '>=', now()->subDays(7))->get(['suggested_start'])
            ->filter(fn ($recommendation) => $recommendation->suggested_start->dayOfWeekIso === now()->dayOfWeekIso)
            ->map(fn ($recommendation) => (int) $recommendation->suggested_start->format('G'))->unique()->all();
        $result = $service->recommend($request->user(), $activity, excludedHours: $excludedHours);

        if (! $result['ok']) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $result['message']], 503);
            }
            return back()->with('error', $result['message']);
        }

        if ($request->expectsJson()) {
            $date = now()->startOfDay();
            $slots = collect($result['recommendations'])->filter(fn ($slot) => is_array($slot) && is_numeric($slot['hour'] ?? null) && is_numeric($slot['confidence'] ?? null))
                ->map(function ($slot) use ($date, $activity, $request, $evidence) {
                    $start = $date->copy()->setTime((int) $slot['hour'], 0);
                    if ($start->lessThanOrEqualTo(now())) {
                        $start->addDay();
                    }
                    $end = $start->copy()->addMinutes($activity->duration_minutes);
                    $recommendation = $activity->recommendations()->create([
                        'user_id' => $request->user()->id, 'category' => $activity->category,
                        'suggested_start' => $start, 'suggested_end' => $end,
                        'confidence_score' => $slot['confidence'], 'is_suitable' => $slot['suitable'] ?? null,
                        'status' => 'pending', 'generated_at' => now(),
                    ]);
                    return ['id' => $recommendation->id, 'start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'confidence' => (float) $slot['confidence'], 'evidence' => $evidence->forActivity($request->user(), $activity)];
                })->take(4)->values();

            return response()->json(['activity' => $activity->name, 'recommendations' => $slots]);
        }

        foreach ($result['recommendations'] as $slot) {
            $start = today()->setHour($slot['hour'])->setMinute(0)->setSecond(0);
            if ($start->lt(now())) {
                $start->addDay();
            }

            $activity->recommendations()->create([
                'user_id' => $request->user()->id,
                'category' => $activity->category,
                'suggested_start' => $start,
                'suggested_end' => $start->copy()->addMinutes($activity->duration_minutes),
                'confidence_score' => $slot['confidence'],
                'is_suitable' => is_bool($slot['suitable'] ?? null) ? $slot['suitable'] : null,
                'status' => 'pending',
                'generated_at' => now(),
            ]);
        }

        return redirect()->route('recommendations.index')->with('status', 'Recommendations ready.');
    }

    public function chooseActivitySlot(Request $request, Activity $activity, ConflictChecker $conflictChecker, ConflictNotificationService $notifications): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('update', $activity);
        $data = $request->validate(['recommendation_id' => ['required', 'integer'], 'confirmed' => ['sometimes', 'boolean']]);
        $recommendation = $activity->recommendations()->where('user_id', $request->user()->id)
            ->whereKey($data['recommendation_id'])->where('status', 'pending')->firstOrFail();
        $start = $recommendation->suggested_start;
        $end = $start->copy()->addMinutes($activity->duration_minutes);
        $conflicts = $conflictChecker->check($request->user(), $start, $end, 'medium');

        if ($conflicts->isNotEmpty() && ! $request->boolean('confirmed')) {
            return response()->json(['message' => 'This time overlaps with your schedule.', 'conflicts' => $conflicts->pluck('message')->all()], 409);
        }

        $schedule = $request->user()->schedules()->create([
            'title' => $activity->name,
            'category' => $activity->category,
            'start_datetime' => $start,
            'end_datetime' => $end,
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        if ($conflicts->isNotEmpty()) {
            $notifications->create($request->user(), $schedule, $conflicts);
        }
        $recommendation->update(['status' => 'accepted']);

        return response()->json(['message' => 'Added to your schedule.']);
    }

    public function accept(Request $request, ConflictChecker $conflictChecker, ConflictNotificationService $notifications, Recommendation $recommendation): RedirectResponse
    {
        Gate::authorize('manage', $recommendation);

        if ($recommendation->source_schedule_id !== null) {
            return $this->acceptScheduleAlternative($request, $recommendation, $conflictChecker);
        }

        $conflicts = $conflictChecker->check(
            $recommendation->user,
            $recommendation->suggested_start,
            $recommendation->suggested_end,
            'medium',
        );

        if ($conflicts->isNotEmpty() && ! $request->boolean('confirmed')) {
            return redirect()->route('recommendations.index')
                ->with('conflicts', [$recommendation->id => $conflicts->pluck('message')->all()]);
        }

        $schedule = $recommendation->user->schedules()->create([
            'title' => $recommendation->activity?->name ?? ucfirst($recommendation->category->value),
            'category' => $recommendation->category,
            'start_datetime' => $recommendation->suggested_start,
            'end_datetime' => $recommendation->suggested_end,
            'priority' => 'medium',
            'status' => 'pending',
        ]);

        if ($conflicts->isNotEmpty()) {
            $notifications->create($recommendation->user, $schedule, $conflicts);
        }

        $recommendation->update(['status' => 'accepted']);

        return redirect()->route('recommendations.index')->with('status', 'Added to your schedule.');
    }

    public function dismiss(Recommendation $recommendation): RedirectResponse
    {
        Gate::authorize('manage', $recommendation);

        $recommendation->update(['status' => 'dismissed']);

        return to_route('recommendations.index', $recommendation->source_schedule_id ? ['type' => 'alerts'] : [])->with('status', 'Recommendation dismissed.');
    }

    private function acceptScheduleAlternative(
        Request $request,
        Recommendation $recommendation,
        ConflictChecker $conflictChecker,
    ): RedirectResponse {
        $sourceSchedule = $request->user()->schedules()->findOrFail($recommendation->source_schedule_id);
        $occurrenceStart = $recommendation->source_occurrence_start;

        if (! $occurrenceStart
            || ! $recommendation->source_schedule_updated_at
            || ! $sourceSchedule->updated_at->equalTo($recommendation->source_schedule_updated_at)
            || ! hash_equals((string) $recommendation->source_schedule_fingerprint, ScheduleFingerprint::for($sourceSchedule))
            || $sourceSchedule->occurrenceOutcomes()->where('occurrence_start', $occurrenceStart)->exists()
            || ! $recommendation->suggested_start->isFuture()) {
            $recommendation->update(['status' => 'dismissed']);

            return to_route('recommendations.index', ['type' => 'alerts'])->with('error', 'This commitment changed after those alternatives were generated. Request fresh alternatives from a current alert.');
        }

        $result = DB::transaction(function () use ($request, $recommendation, $conflictChecker, $sourceSchedule, $occurrenceStart) {
            $schedule = $request->user()->schedules()
                ->whereKey($sourceSchedule->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $schedule->updated_at->equalTo($recommendation->source_schedule_updated_at)
                || ! hash_equals((string) $recommendation->source_schedule_fingerprint, ScheduleFingerprint::for($schedule))
                || $schedule->occurrenceOutcomes()->where('occurrence_start', $occurrenceStart)->exists()) {
                return ['status' => 'stale'];
            }

            $conflicts = $conflictChecker->check(
                $request->user(),
                $recommendation->suggested_start,
                $recommendation->suggested_end,
                $schedule->priority,
                $schedule->getKey(),
            );
            if ($conflicts->isNotEmpty()) {
                return ['status' => 'conflict', 'conflicts' => $conflicts->pluck('message')->all()];
            }

            if ($schedule->is_recurring) {
                $duration = (int) abs($schedule->start_datetime->diffInMinutes($schedule->end_datetime));
                $occurrenceEnd = $occurrenceStart->copy()->addMinutes($duration);
                $schedule->occurrenceOutcomes()->create([
                    'occurrence_start' => $occurrenceStart,
                    'occurrence_end' => $occurrenceEnd,
                    'outcome' => 'cancelled',
                    'recorded_at' => now(),
                ]);
                $schedule->user->schedules()->create([
                    'title' => $schedule->title,
                    'description' => $schedule->description,
                    'category' => $schedule->category,
                    'start_datetime' => $recommendation->suggested_start,
                    'end_datetime' => $recommendation->suggested_end,
                    'priority' => $schedule->priority,
                    'status' => 'pending',
                    'timezone' => $schedule->timezone,
                ]);
                $schedule->riskAssessments()->where('occurrence_start', $occurrenceStart)->delete();
            } else {
                $schedule->update([
                    'start_datetime' => $recommendation->suggested_start,
                    'end_datetime' => $recommendation->suggested_end,
                ]);
            }

            $recommendation->update(['status' => 'accepted']);

            return ['status' => 'accepted'];
        });

        if ($result['status'] === 'stale') {
            $recommendation->update(['status' => 'dismissed']);

            return to_route('recommendations.index', ['type' => 'alerts'])->with('error', 'This commitment changed after those alternatives were generated. Request fresh alternatives from a current alert.');
        }

        if ($result['status'] === 'conflict') {
            return to_route('recommendations.index', ['type' => 'alerts'])
                ->with('alternative_conflicts', [$recommendation->getKey() => $result['conflicts']]);
        }

        return to_route('recommendations.index', ['type' => 'alerts'])->with('status', 'Your commitment was moved to the selected time.');
    }
}
