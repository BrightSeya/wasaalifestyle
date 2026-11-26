<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivityRequest;
use App\Models\Activity;
use App\Services\ConflictChecker;
use App\Support\ActivityStats;
use App\Support\Occurrence;
use App\Support\RecurrenceLimits;
use App\Support\UserPreferences;
use App\Support\UserTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ActivityController extends Controller
{
    private const HISTORY_DAYS = 60;

    private const CHART_DAYS = 7;

    public function index(Request $request): View
    {
        $activities = $request->user()->activities()
            ->with(['logs' => fn ($query) => $query->orderByDesc('scheduled_for')->limit(5)])
            ->orderBy('name')
            ->get();
        $activities->each(fn (Activity $activity) => $activity->logs->each(
            fn ($log) => $log->setAttribute('scheduled_for', UserTime::local($request->user(), $log->scheduled_for)),
        ));

        $today = Carbon::now(UserTime::timezone($request->user()))->startOfDay();
        $recentLogs = $request->user()->activityLogs()
            ->where('scheduled_for', '>=', UserTime::utc($request->user(), $today->copy()->subDays(self::HISTORY_DAYS)))
            ->where('scheduled_for', '<', UserTime::utc($request->user(), $today->copy()->addDay()))
            ->get();
        $recentLogs->each(fn ($log) => $log->setAttribute('scheduled_for', UserTime::local($request->user(), $log->scheduled_for)));
        $logsByActivity = $recentLogs->groupBy('activity_id');

        $streaks = $activities->mapWithKeys(fn (Activity $activity) => [
            $activity->id => ActivityStats::streak($logsByActivity->get($activity->id, collect()), $today),
        ]);

        $completionByDay = ActivityStats::completionByDay($recentLogs, self::CHART_DAYS, $today);

        return view('activities.index', compact('activities', 'streaks', 'completionByDay'));
    }

    public function create(): View
    {
        return view('activities.create', ['activity' => new Activity(['frequency' => 3, 'duration_minutes' => 30])]);
    }

    public function store(ActivityRequest $request, ConflictChecker $conflicts): RedirectResponse
    {
        $data = $request->validated();
        $slots = $data['weekly_slots'];
        $plan = $this->prepareWeeklySchedules($request, $data, $slots, $conflicts);

        if ($warning = $this->conflictConfirmationWarning($request, $data, $slots, $plan['conflicts'], $plan['slot_indexes'])) {
            return $warning;
        }

        $this->persistWeeklySchedules($request, $data, $plan['schedules']);

        return redirect()->route('activities.index')->with('status', $this->savedActivityMessage('Activity added.', $plan['conflicts']));
    }

    public function edit(Activity $activity): View
    {
        Gate::authorize('update', $activity);

        return view('activities.edit', compact('activity'));
    }

    public function update(ActivityRequest $request, Activity $activity, ConflictChecker $conflicts): RedirectResponse
    {
        Gate::authorize('update', $activity);

        $data = $request->validated();
        $slots = $data['weekly_slots'];
        $plan = $this->prepareWeeklySchedules(
            $request,
            $data,
            $slots,
            $conflicts,
            $activity->schedules()->pluck('id')->all(),
        );

        if ($warning = $this->conflictConfirmationWarning($request, $data, $slots, $plan['conflicts'], $plan['slot_indexes'], $activity)) {
            return $warning;
        }

        $this->persistWeeklySchedules($request, $data, $plan['schedules'], $activity);

        return redirect()->route('activities.index')->with('status', $this->savedActivityMessage('Activity updated.', $plan['conflicts']));
    }

    /**
     * @param  list<array{weekday: int|string, start_time: string, confidence?: float|string|null}>  $slots
     * @param  list<int>  $excludeScheduleIds
     * @return array{schedules: list<array<string, mixed>>, conflicts: Collection<int, array{type: string, message: string, occurrence: Occurrence, candidate_start: Carbon}>, slot_indexes: array<int, int>}
     */
    private function prepareWeeklySchedules(ActivityRequest $request, array $data, array $slots, ConflictChecker $conflicts, array $excludeScheduleIds = []): array
    {
        $user = $request->user();
        $timezone = UserTime::timezone($user);
        $today = Carbon::now($timezone)->startOfDay();
        $preferences = UserPreferences::fromArray($user->preferences);
        $intervals = [];
        $newSchedules = [];
        $slotIndexesByWeekday = [];
        $duration = (int) $data['duration_minutes'];
        foreach ($slots as $index => $slot) {
            $weekday = (int) $slot['weekday'];
            $slotIndexesByWeekday[$weekday] = $index;
            $daysUntil = ($weekday - $today->dayOfWeekIso + 7) % 7;
            $firstDate = $today->copy()->addDays($daysUntil);
            [$hour, $minute] = array_map('intval', explode(':', $slot['start_time']));
            $first = $firstDate->copy()->setTime($hour, $minute);
            if ($first->lessThanOrEqualTo(Carbon::now($timezone))) {
                $first->addWeek();
            }
            $end = $first->copy()->addMinutes($duration);
            if (! $this->fitsAvailability($preferences, $first->copy()->startOfDay(), $first, $end)) {
                throw ValidationException::withMessages([
                    "weekly_slots.{$index}.start_time" => 'This time does not fit your availability for that weekday.',
                ]);
            }

            $lastDate = RecurrenceLimits::maximumEndDate($first)->startOfDay();
            $occurrenceCount = (int) floor($first->copy()->startOfDay()->diffInDays($lastDate) / 7) + 1;
            for ($occurrence = 0; $occurrence < $occurrenceCount; $occurrence++) {
                $start = $first->copy()->addWeeks($occurrence);
                $intervals[] = ['start' => $start, 'end' => $start->copy()->addMinutes($duration)];
            }

            $newSchedules[] = [
                'title' => $data['name'],
                'category' => $data['category'],
                'start_datetime' => UserTime::utc($user, $first),
                'end_datetime' => UserTime::utc($user, $end),
                'priority' => 'medium',
                'is_recurring' => true,
                'recurrence_rule' => 'weekly',
                'recurrence_count' => $occurrenceCount,
                'status' => 'pending',
            ];
        }

        return [
            'schedules' => $newSchedules,
            'conflicts' => $conflicts->checkMany($user, $intervals, 'medium', excludeScheduleIds: $excludeScheduleIds),
            'slot_indexes' => $slotIndexesByWeekday,
        ];
    }

    /**
     * @param  list<array{weekday: int|string, start_time: string, confidence?: float|string|null}>  $slots
     * @param  Collection<int, array{type: string, message: string, occurrence: Occurrence, candidate_start: Carbon}>  $scheduleConflicts
     * @param  array<int, int>  $slotIndexesByWeekday
     */
    private function conflictConfirmationWarning(
        ActivityRequest $request,
        array $data,
        array $slots,
        Collection $scheduleConflicts,
        array $slotIndexesByWeekday,
        ?Activity $activity = null,
    ): ?RedirectResponse {
        if ($scheduleConflicts->isEmpty()) {
            return null;
        }

        $fingerprint = $this->conflictFingerprint($request, $data, $slots, $scheduleConflicts, $activity);
        $submittedFingerprint = $request->input('activity_conflict_fingerprint');
        if ($request->boolean('confirmed')
            && is_string($submittedFingerprint)
            && hash_equals($fingerprint, $submittedFingerprint)) {
            return null;
        }

        $grouped = $scheduleConflicts->groupBy(function ($conflict) use ($slotIndexesByWeekday) {
            $slotIndex = $slotIndexesByWeekday[$conflict['candidate_start']->dayOfWeekIso];

            return $slotIndex.'|'.$conflict['occurrence']->schedule->id;
        });
        $bySlot = [];
        foreach ($grouped as $items) {
            $ordered = $items->sortBy(fn ($conflict) => $conflict['candidate_start']->getTimestamp());
            $first = $ordered->first();
            $last = $ordered->last();
            $candidateStart = $first['candidate_start'];
            $candidateEnd = $candidateStart->copy()->addMinutes((int) $data['duration_minutes']);
            $occurrence = $first['occurrence'];
            $slotIndex = $slotIndexesByWeekday[$candidateStart->dayOfWeekIso];
            $count = $ordered->pluck('candidate_start')
                ->unique(fn (Carbon $date) => $date->format('Y-m-d H:i:s'))
                ->count();
            $message = $candidateStart->format('D, M j').' '.$candidateStart->format('g:i A').'-'.$candidateEnd->format('g:i A')
                .' overlaps with '.$occurrence->schedule->title.' ('.$occurrence->start->format('g:i A').'-'.$occurrence->end->format('g:i A').')';
            if ($count > 1) {
                $message .= " on {$count} planned occurrences through ".$last['candidate_start']->format('D, M j');
            }

            $bySlot[$slotIndex][] = ['message' => $message.'.'];
        }
        ksort($bySlot);

        return back()->withInput()->with('activity_conflicts', [
            'fingerprint' => $fingerprint,
            'by_slot' => $bySlot,
        ]);
    }

    /**
     * @param  list<array{weekday: int|string, start_time: string, confidence?: float|string|null}>  $slots
     * @param  Collection<int, array{type: string, message: string, occurrence: Occurrence, candidate_start: Carbon}>  $scheduleConflicts
     */
    private function conflictFingerprint(ActivityRequest $request, array $data, array $slots, Collection $scheduleConflicts, ?Activity $activity): string
    {
        $conflictDetails = $scheduleConflicts->map(function ($conflict) {
            $occurrence = $conflict['occurrence'];

            return [
                'type' => $conflict['type'],
                'candidate_start' => $conflict['candidate_start']->format('Y-m-d H:i:sP'),
                'schedule_id' => $occurrence->schedule->id,
                'schedule_title' => $occurrence->schedule->title,
                'occurrence_start' => $occurrence->start->format('Y-m-d H:i:sP'),
                'occurrence_end' => $occurrence->end->format('Y-m-d H:i:sP'),
            ];
        })->sortBy(fn ($conflict) => implode('|', $conflict))->values()->all();
        $payload = json_encode([
            'user_id' => $request->user()->id,
            'activity_id' => $activity?->id,
            'activity' => [
                'name' => $data['name'],
                'category' => $data['category'],
                'duration_minutes' => $data['duration_minutes'],
            ],
            'slots' => collect($slots)->map(fn ($slot) => [
                'weekday' => (int) $slot['weekday'],
                'start_time' => $slot['start_time'],
            ])->values()->all(),
            'conflicts' => $conflictDetails,
        ], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /**
     * @param  list<array<string, mixed>>  $schedules
     */
    private function persistWeeklySchedules(ActivityRequest $request, array $data, array $schedules, ?Activity $activity = null): Activity
    {
        return DB::transaction(function () use ($request, $data, $schedules, $activity): Activity {
            $user = $request->user();
            if ($activity) {
                $today = Carbon::now(UserTime::timezone($user))->startOfDay();
                $activity->update($data);
                $activity->schedules()->update(['recurrence_end_date' => $today->copy()->subDay()->toDateString()]);
            } else {
                $activity = $user->activities()->create($data);
            }

            foreach ($schedules as $schedule) {
                $user->schedules()->create($schedule + ['activity_id' => $activity->id]);
            }

            return $activity;
        });
    }

    /**
     * @param  Collection<int, array{type: string, message: string, occurrence: Occurrence, candidate_start: Carbon}>  $conflicts
     */
    private function savedActivityMessage(string $message, Collection $conflicts): string
    {
        return $conflicts->isNotEmpty()
            ? $message.' Overlapping weekly sessions were confirmed and saved.'
            : $message;
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

    public function destroy(Activity $activity): RedirectResponse
    {
        Gate::authorize('delete', $activity);

        $activity->delete();

        return redirect()->route('activities.index')->with('status', 'Activity deleted.');
    }

    public function log(Request $request, Activity $activity): RedirectResponse
    {
        Gate::authorize('update', $activity);

        $data = $request->validate([
            'status' => ['required', Rule::in(['completed', 'missed', 'skipped'])],
        ]);

        $today = Carbon::now(UserTime::timezone($request->user()))->startOfDay();
        $log = $activity->logs()
            ->where('scheduled_for', '>=', UserTime::utc($request->user(), $today))
            ->where('scheduled_for', '<', UserTime::utc($request->user(), $today->copy()->addDay()))
            ->first()
            ?? $activity->logs()->make(['user_id' => $activity->user_id]);

        $replacement = $activity->schedules()
            ->where('is_recurring', false)
            ->where('start_datetime', '>=', UserTime::utc($request->user(), $today))
            ->where('start_datetime', '<', UserTime::utc($request->user(), $today->copy()->addDay()))
            ->orderBy('start_datetime')
            ->first();
        $weekday = $today->dayOfWeekIso;
        $plannedTime = $replacement
            ? UserTime::local($request->user(), $replacement->start_datetime)->format('H:i')
            : (collect($activity->weekly_slots ?? [])
                ->first(fn ($slot) => (int) ($slot['weekday'] ?? 0) === $weekday)['start_time'] ?? $activity->preferred_time_of_day);
        $log->fill([
            'scheduled_for' => UserTime::utc($request->user(), $today->copy()->setTimeFromTimeString($plannedTime)),
            'status' => $data['status'],
            'logged_at' => $data['status'] === 'completed' ? now() : null,
        ])->save();

        return redirect()->route('activities.index')->with('status', 'Activity logged.');
    }
}
