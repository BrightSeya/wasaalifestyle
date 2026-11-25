<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivityRequest;
use App\Models\Activity;
use App\Models\ScheduleOccurrenceOutcome;
use App\Services\ConflictChecker;
use App\Support\ActivityStats;
use App\Support\RecurrenceLimits;
use App\Support\UserPreferences;
use App\Support\UserTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
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
        [$activity, $skippedOccurrences] = DB::transaction(function () use ($request, $data, $slots, $conflicts) {
            $activity = $request->user()->activities()->create($data);
            $skippedOccurrences = $this->replaceWeeklySchedules($request, $activity, $slots, $conflicts);

            return [$activity, $skippedOccurrences];
        });

        return redirect()->route('activities.index')->with('status', $this->savedActivityMessage('Activity added.', $skippedOccurrences));
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
        $skippedOccurrences = DB::transaction(function () use ($request, $activity, $data, $slots, $conflicts): int {
            $activity->update($data);

            return $this->replaceWeeklySchedules($request, $activity, $slots, $conflicts);
        });

        return redirect()->route('activities.index')->with('status', $this->savedActivityMessage('Activity updated.', $skippedOccurrences));
    }

    /**
     * @param  list<array{weekday: int|string, start_time: string, confidence?: float|string|null}>  $slots
     */
    private function replaceWeeklySchedules(ActivityRequest $request, Activity $activity, array $slots, ConflictChecker $conflicts): int
    {
        $user = $request->user();
        $timezone = UserTime::timezone($user);
        $today = Carbon::now($timezone)->startOfDay();
        $activity->schedules()->update(['recurrence_end_date' => $today->copy()->subDay()->toDateString()]);

        $preferences = UserPreferences::fromArray($user->preferences);
        $intervals = [];
        $newSchedules = [];
        $slotIndexesByWeekday = [];
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
            $end = $first->copy()->addMinutes($activity->duration_minutes);
            if (! $this->fitsAvailability($preferences, $first->copy()->startOfDay(), $first, $end)) {
                throw ValidationException::withMessages([
                    "weekly_slots.{$index}.start_time" => 'This time does not fit your availability for that weekday.',
                ]);
            }

            $lastDate = RecurrenceLimits::maximumEndDate($first)->startOfDay();
            $occurrenceCount = (int) floor($first->copy()->startOfDay()->diffInDays($lastDate) / 7) + 1;
            for ($occurrence = 0; $occurrence < $occurrenceCount; $occurrence++) {
                $start = $first->copy()->addWeeks($occurrence);
                $intervals[] = ['start' => $start, 'end' => $start->copy()->addMinutes($activity->duration_minutes)];
            }

            $newSchedules[] = [
                'activity_id' => $activity->id,
                'title' => $activity->name,
                'category' => $activity->category,
                'start_datetime' => UserTime::utc($user, $first),
                'end_datetime' => UserTime::utc($user, $end),
                'priority' => 'medium',
                'is_recurring' => true,
                'recurrence_rule' => 'weekly',
                'recurrence_count' => $occurrenceCount,
                'status' => 'pending',
            ];
        }

        $scheduleConflicts = $conflicts->checkMany($user, $intervals, 'medium');
        if ($scheduleConflicts->isNotEmpty()) {
            $repeatedConflicts = $scheduleConflicts->groupBy(fn ($conflict) => $conflict['occurrence']->schedule->id)
                ->filter(fn ($items) => $items->first()['occurrence']->schedule->is_recurring
                    && $items->pluck('candidate_start')->unique(fn (Carbon $date) => $date->format('Y-m-d H:i:s'))->count() > 1)
                ->flatten(1);
            $errors = [];
            foreach ($repeatedConflicts as $conflict) {
                $weekday = $conflict['candidate_start']->dayOfWeekIso;
                $index = $slotIndexesByWeekday[$weekday] ?? null;
                if ($index === null) {
                    continue;
                }

                $errors["weekly_slots.{$index}.start_time"] = "This time conflicts with {$conflict['occurrence']->schedule->title} on {$conflict['candidate_start']->format('D, M j')}. Choose another time.";
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        foreach ($newSchedules as $schedule) {
            $createdSchedule = $user->schedules()->create($schedule);
            $weekday = UserTime::local($user, $createdSchedule->start_datetime)->dayOfWeekIso;
            $skippedStarts = $scheduleConflicts
                ->filter(fn ($conflict) => $conflict['candidate_start']->dayOfWeekIso === $weekday)
                ->pluck('candidate_start')
                ->unique(fn (Carbon $date) => $date->format('Y-m-d H:i:s'));
            foreach ($skippedStarts as $start) {
                $storedStart = UserTime::utc($user, $start);
                $createdSchedule->occurrenceOutcomes()->updateOrCreate(
                    ['occurrence_start' => $storedStart],
                    [
                        'occurrence_end' => UserTime::utc($user, $start->copy()->addMinutes($activity->duration_minutes)),
                        'outcome' => 'cancelled',
                        'recorded_at' => now(),
                    ],
                );
            }
        }

        return $scheduleConflicts->pluck('candidate_start')
            ->unique(fn (Carbon $date) => $date->format('Y-m-d H:i:s'))
            ->count();
    }

    private function savedActivityMessage(string $message, int $skippedOccurrences): string
    {
        return $skippedOccurrences > 0
            ? $message.' Conflicting weekly occurrences were skipped; the remaining sessions stay scheduled.'
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
