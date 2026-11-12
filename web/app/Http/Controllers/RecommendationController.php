<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Recommendation;
use App\Services\ConflictChecker;
use App\Services\ConflictNotificationService;
use App\Services\RecommendationService;
use App\Support\ScheduleFingerprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RecommendationController extends Controller
{
    public function index(Request $request): View
    {
        $recommendations = $request->user()->recommendations()
            ->with(['activity', 'sourceSchedule'])
            ->where('status', 'pending')
            ->orderByDesc('confidence_score')
            ->get();

        return view('recommendations.index', compact('recommendations'));
    }

    public function store(Request $request, RecommendationService $service, Activity $activity): RedirectResponse
    {
        Gate::authorize('update', $activity);

        $result = $service->recommend($request->user(), $activity);

        if (! $result['ok']) {
            return back()->with('error', $result['message']);
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

        return redirect()->route('recommendations.index')->with('status', 'Recommendation dismissed.');
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

            return to_route('recommendations.index')->with('error', 'This commitment changed after those alternatives were generated. Request fresh alternatives from a current alert.');
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

            return to_route('recommendations.index')->with('error', 'This commitment changed after those alternatives were generated. Request fresh alternatives from a current alert.');
        }

        if ($result['status'] === 'conflict') {
            return to_route('recommendations.index')
                ->with('alternative_conflicts', [$recommendation->getKey() => $result['conflicts']]);
        }

        return to_route('recommendations.index')->with('status', 'Your commitment was moved to the selected time.');
    }
}
