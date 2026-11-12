<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\ConflictChecker;
use App\Services\RecommendationService;
use App\Support\ScheduleFingerprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CommitmentRiskAlternativeController extends Controller
{
    public function store(
        Request $request,
        Notification $notification,
        RecommendationService $recommendations,
        ConflictChecker $conflicts,
    ): RedirectResponse {
        $alert = $request->user()->notifications()
            ->with('riskAssessment.schedule')
            ->findOrFail($notification->getKey());

        abort_unless($alert->isCommitmentRiskAlert() && $alert->dismissed_at === null, 404);

        $assessment = $alert->riskAssessment;
        abort_unless($assessment && $assessment->schedule, 404);
        $schedule = $request->user()->schedules()->findOrFail($assessment->schedule_id);
        $occurrenceStart = $assessment->occurrence_start;

        if (! $occurrenceStart->isFuture()
            || ! $schedule->updated_at->equalTo($assessment->schedule_updated_at)
            || $schedule->occurrenceOutcomes()->where('occurrence_start', $occurrenceStart)->exists()) {
            return back()->with('error', 'This alert is no longer eligible for alternative times. Review the current schedule.');
        }

        $pending = $request->user()->recommendations()
            ->where('source_schedule_id', $schedule->getKey())
            ->where('source_occurrence_start', $occurrenceStart)
            ->where('status', 'pending')
            ->exists();
        if ($pending) {
            return to_route('recommendations.index')->with('status', 'You already have pending alternatives for this commitment.');
        }

        $result = $recommendations->recommendForSchedule($request->user(), $schedule, $occurrenceStart);
        if (! $result['ok']) {
            return back()->with('error', $result['message']);
        }

        $duration = (int) abs($schedule->start_datetime->diffInMinutes($schedule->end_datetime));
        $stored = 0;
        foreach ($result['recommendations'] as $slot) {
            if (! is_array($slot)
                || ! is_numeric($slot['hour'] ?? null)
                || (int) $slot['hour'] < 0
                || (int) $slot['hour'] > 23
                || ! is_numeric($slot['confidence'] ?? null)
                || (float) $slot['confidence'] < 0
                || (float) $slot['confidence'] > 1) {
                continue;
            }

            $start = $occurrenceStart->copy()->startOfDay()->setTime((int) $slot['hour'], 0);
            if (! $start->isFuture() || $start->equalTo($occurrenceStart)) {
                continue;
            }

            $end = $start->copy()->addMinutes($duration);
            if ($conflicts->check($request->user(), $start, $end, $schedule->priority, $schedule->getKey())->isNotEmpty()) {
                continue;
            }

            $request->user()->recommendations()->create([
                'activity_id' => null,
                'source_schedule_id' => $schedule->getKey(),
                'source_occurrence_start' => $occurrenceStart,
                'source_schedule_updated_at' => $schedule->updated_at,
                'source_schedule_fingerprint' => ScheduleFingerprint::for($schedule),
                'category' => $schedule->category,
                'suggested_start' => $start,
                'suggested_end' => $end,
                'confidence_score' => $slot['confidence'],
                'is_suitable' => is_bool($slot['suitable'] ?? null) ? $slot['suitable'] : null,
                'status' => 'pending',
                'generated_at' => now(),
            ]);
            $stored++;
        }

        if ($stored === 0) {
            return back()->with('error', 'No conflict-free future alternatives were available for this commitment.');
        }

        return to_route('recommendations.index')->with('status', 'Conflict-checked alternatives are ready. Choose one to move this commitment.');
    }
}
