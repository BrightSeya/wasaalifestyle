<?php

namespace App\Http\Controllers;

use App\Enums\Category;
use App\Models\Activity;
use App\Models\Recommendation;
use App\Services\ConflictChecker;
use App\Services\ConflictNotificationService;
use App\Services\RecommendationEvidence;
use App\Services\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RecommendationController extends Controller
{
    public function index(Request $request, RecommendationService $service, RecommendationEvidence $evidence, ConflictChecker $conflicts): View
    {
        $suggestions = collect();
        $suggestedStartTimes = [];
        $error = null;

        foreach ($request->user()->activities()->orderBy('name')->get() as $activity) {
            $date = now()->startOfDay();
            $excludedHours = $request->user()->recommendations()
                ->where('activity_id', $activity->id)
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

    public function previewTask(Request $request, RecommendationService $service, RecommendationEvidence $evidence, ConflictChecker $conflicts): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::enum(Category::class)],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);
        $date = Carbon::parse($data['date']);
        $startTime = Carbon::createFromFormat('H:i', $data['start_time']);
        $endTime = Carbon::createFromFormat('H:i', $data['end_time']);
        $duration = (int) $startTime->diffInMinutes($endTime);
        $excludedHours = $request->user()->recommendations()
            ->whereNull('activity_id')->where('category', $data['category'])
            ->where('generated_at', '>=', now()->subDays(7))
            ->get(['suggested_start'])
            ->filter(fn ($recommendation) => $recommendation->suggested_start->dayOfWeekIso === $date->dayOfWeekIso)
            ->map(fn ($recommendation) => (int) $recommendation->suggested_start->format('G'))->unique()->all();
        $result = $service->recommendForDetails($request->user(), Category::from($data['category']), $date, $duration, excludedHours: $excludedHours);

        if (! $result['ok']) {
            $status = str_contains($result['message'], 'No available time window') ? 422 : 503;

            return response()->json(['message' => $result['message']], $status);
        }

        $slots = collect();
        foreach ($result['recommendations'] as $slot) {
            if (! is_array($slot) || ! is_numeric($slot['hour'] ?? null) || ! is_numeric($slot['confidence'] ?? null) || ($slot['suitable'] ?? true) === false) {
                continue;
            }

            $hour = (int) $slot['hour'];
            if ($hour < 0 || $hour > 23) {
                continue;
            }

            $start = $date->copy()->setTime($hour, 0);
            if ($start->lessThanOrEqualTo(now())) {
                continue;
            }

            $end = $start->copy()->addMinutes($duration);
            if ($conflicts->check($request->user(), $start, $end, 'medium')->isNotEmpty()) {
                continue;
            }

            $recommendation = $request->user()->recommendations()->create([
                'category' => $data['category'], 'suggested_start' => $start, 'suggested_end' => $end,
                'confidence_score' => $slot['confidence'], 'is_suitable' => $slot['suitable'] ?? null,
                'status' => 'pending', 'generated_at' => now(),
            ]);
            $slots->push(['id' => $recommendation->id, 'start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'confidence' => (float) $slot['confidence'], 'evidence' => $evidence->forCategory($request->user(), Category::from($data['category']))]);

            if ($slots->count() === 3) {
                break;
            }
        }

        return response()->json(['recommendations' => $slots->values()]);
    }

    public function store(Request $request, RecommendationService $service, RecommendationEvidence $evidence, Activity $activity): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $activity);

        $excludedHours = $request->user()->recommendations()->where('activity_id', $activity->id)
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

    public function chooseActivitySlot(Request $request, Activity $activity, ConflictChecker $conflictChecker, ConflictNotificationService $notifications): JsonResponse
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

        return to_route('recommendations.index')->with('status', 'Recommendation dismissed.');
    }

}
