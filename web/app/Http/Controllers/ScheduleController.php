<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScheduleRequest;
use App\Models\Schedule;
use App\Models\ScheduleOccurrenceOutcome;
use App\Services\ConflictChecker;
use App\Services\ConflictNotificationService;
use App\Support\Occurrence;
use App\Support\UserTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function index(Request $request): View
    {
        $schedules = $request->user()->schedules()
            ->where(fn ($query) => $query->where('end_datetime', '>=', now()->startOfDay())->orWhere('is_recurring', true))
            ->orderBy('start_datetime')
            ->paginate(20);

        return view('schedules.index', compact('schedules'));
    }

    public function create(): View
    {
        return view('schedules.create', ['schedule' => new Schedule(['priority' => 'medium'])]);
    }

    public function store(ScheduleRequest $request, ConflictChecker $conflictChecker, ConflictNotificationService $notifications): RedirectResponse
    {
        $data = $request->scheduleData();

        $conflicts = $this->conflictsForSchedule($request, $data, $conflictChecker);

        $permanentRecurringConflict = $conflicts->first(fn ($conflict) => $conflict['occurrence']->schedule->is_recurring
            && ! $conflict['occurrence']->schedule->recurrence_end_date
            && ! $conflict['occurrence']->schedule->recurrence_count);
        if ($data['is_recurring'] && $permanentRecurringConflict) {
            return back()->withInput()->withErrors(['start_time' => 'This time overlaps with a repeating schedule pattern. Choose a different time or repeat day.']);
        }

        if ($conflicts->isNotEmpty() && ! $request->boolean('confirmed')) {
            return back()->withInput()->with('conflicts', $conflicts->map(fn ($conflict) => $conflict['message'].(! empty($conflict['candidate_start']) ? ' on '.$conflict['candidate_start']->format('D, M j') : ''))->unique()->all());
        }

        $recommendationConfidence = $request->validated('recommendation_confidence');
        $storageData = $this->toStorageTimes($request, $data);
        $schedule = DB::transaction(function () use ($request, $storageData, $recommendationConfidence, $conflicts) {
            $schedule = $request->user()->schedules()->create($storageData);
            if ($schedule->is_recurring) {
                $conflicts->pluck('candidate_start')->filter()->unique(fn ($date) => $date->format('Y-m-d H:i:s'))->each(function (Carbon $start) use ($schedule, $request) {
                    $storedStart = UserTime::utc($request->user(), $start);
                    ScheduleOccurrenceOutcome::query()->updateOrCreate(
                        ['schedule_id' => $schedule->id, 'occurrence_start' => $storedStart],
                        ['occurrence_end' => $storedStart->copy()->addMinutes($schedule->start_datetime->diffInMinutes($schedule->end_datetime)), 'outcome' => 'cancelled', 'recorded_at' => now()],
                    );
                });
            } else {
                $conflicts->filter(fn ($conflict) => $conflict['occurrence']->schedule->is_recurring)
                    ->each(function ($conflict) use ($request) {
                        $occurrence = $conflict['occurrence'];
                        $storedStart = UserTime::utc($request->user(), $occurrence->start);
                        ScheduleOccurrenceOutcome::query()->updateOrCreate(
                            ['schedule_id' => $occurrence->schedule->id, 'occurrence_start' => $storedStart],
                            ['occurrence_end' => UserTime::utc($request->user(), $occurrence->end), 'outcome' => 'cancelled', 'recorded_at' => now()],
                        );
                    });
            }
            if ($recommendationConfidence !== null) {
                $request->user()->recommendations()->create([
                    'category' => $schedule->category,
                    'suggested_start' => $schedule->start_datetime,
                    'suggested_end' => $schedule->end_datetime,
                    'confidence_score' => $recommendationConfidence,
                    'is_suitable' => true,
                    'status' => 'accepted',
                    'generated_at' => now(),
                ]);
            }

            return $schedule;
        });

        if ($conflicts->isNotEmpty() && ! $schedule->is_recurring) {
            $remainingConflicts = $conflicts->reject(fn ($conflict) => $conflict['occurrence']->schedule->is_recurring);
            if ($remainingConflicts->isNotEmpty()) {
                $notifications->create($request->user(), $schedule, $remainingConflicts);
            }
        }

        return $this->backToWeek($schedule)->with('status', 'Entry created.');
    }

    /** @return Collection<int, array{type:string,message:string,occurrence:Occurrence,candidate_start:Carbon}> */
    private function conflictsForSchedule(ScheduleRequest $request, array $data, ConflictChecker $checker): Collection
    {
        $first = $data['start_datetime']->copy();
        $duration = (int) $first->diffInMinutes($data['end_datetime']);
        if (! $data['is_recurring']) {
            return $checker->check($request->user(), $first, $data['end_datetime'], $data['priority'])
                ->map(fn ($conflict) => $conflict + ['candidate_start' => $first]);
        }

        $step = $data['recurrence_rule'] === 'daily' ? 1 : 7;
        $count = $data['recurrence_count'];
        $until = $data['recurrence_end_date'] ? Carbon::parse($data['recurrence_end_date'], UserTime::timezone($request->user()))->endOfDay() : null;
        $limit = $count ?? ($until ? 2000 : 7);
        if (! $count && ! $until) {
            $existing = $request->user()->schedules()->get(['start_datetime', 'recurrence_rule', 'recurrence_end_date', 'recurrence_count', 'is_recurring']);
            $horizon = $existing->map(function (Schedule $schedule) use ($request) {
                $localStart = UserTime::local($request->user(), $schedule->start_datetime);
                if (! $schedule->is_recurring) {
                    return $localStart;
                }
                if ($schedule->recurrence_end_date) {
                    return Carbon::parse($schedule->recurrence_end_date->toDateString(), UserTime::timezone($request->user()))->endOfDay();
                }
                if ($schedule->recurrence_count) {
                    $step = $schedule->recurrence_rule === 'daily' ? 1 : 7;

                    return $localStart->copy()->addDays(($schedule->recurrence_count - 1) * $step);
                }
                $step = $schedule->recurrence_rule === 'daily' ? 1 : 7;

                return $localStart->copy()->addDays($step * 7);
            })->filter()->max();
            if ($horizon) {
                $days = max(0, $first->copy()->startOfDay()->diffInDays(Carbon::parse($horizon, UserTime::timezone($request->user()))->startOfDay()));
                $limit = min(20000, (int) floor($days / $step) + 8);
            }
        }

        $intervals = [];
        for ($index = 0, $start = $first->copy(); $index < $limit; $index++, $start->addDays($step)) {
            if ($until && $start->gt($until)) {
                break;
            }
            $intervals[] = ['start' => $start->copy(), 'end' => $start->copy()->addMinutes($duration)];
        }

        return $checker->checkMany($request->user(), $intervals, $data['priority'])
            ->unique(fn ($conflict) => $conflict['candidate_start']->format('Y-m-d H:i:s').'|'.$conflict['occurrence']->schedule->id.'|'.$conflict['occurrence']->start->format('Y-m-d H:i:s'))->values();
    }

    private function toStorageTimes(ScheduleRequest $request, array $data): array
    {
        $data['start_datetime'] = UserTime::utc($request->user(), $data['start_datetime']);
        $data['end_datetime'] = UserTime::utc($request->user(), $data['end_datetime']);

        return $data;
    }

    public function edit(Schedule $schedule): View|RedirectResponse
    {
        Gate::authorize('update', $schedule);

        if ($schedule->activity_id && $schedule->is_recurring) {
            return redirect()->route('activities.edit', $schedule->activity_id)
                ->with('status', 'Edit this routine from its activity page to keep its weekly plan in sync.');
        }

        return view('schedules.edit', compact('schedule'));
    }

    public function update(ScheduleRequest $request, ConflictChecker $conflictChecker, ConflictNotificationService $notifications, Schedule $schedule): RedirectResponse
    {
        Gate::authorize('update', $schedule);

        if ($schedule->activity_id && $schedule->is_recurring) {
            return redirect()->route('activities.edit', $schedule->activity_id)
                ->with('status', 'Edit this routine from its activity page to keep its weekly plan in sync.');
        }

        $data = $request->scheduleData();

        $conflicts = $conflictChecker->check(
            $request->user(),
            $data['start_datetime'],
            $data['end_datetime'],
            $data['priority'],
            excludeScheduleId: $schedule->id,
        );

        if ($conflicts->isNotEmpty() && ! $request->boolean('confirmed')) {
            return back()->withInput()->with('conflicts', $conflicts->pluck('message')->all());
        }

        DB::transaction(fn () => $schedule->update($this->toStorageTimes($request, $data)));

        if ($conflicts->isNotEmpty()) {
            $notifications->create($request->user(), $schedule, $conflicts);
        }

        return $this->backToWeek($schedule)->with('status', 'Entry updated.');
    }

    public function destroy(Schedule $schedule): RedirectResponse
    {
        Gate::authorize('delete', $schedule);

        if ($schedule->activity_id && $schedule->is_recurring) {
            return redirect()->route('activities.edit', $schedule->activity_id)
                ->with('status', 'Edit this routine from its activity page to keep its weekly plan in sync.');
        }

        $schedule->delete();

        return $this->backToWeek($schedule)->with('status', 'Entry deleted.');
    }

    private function backToWeek(Schedule $schedule): RedirectResponse
    {
        return redirect()->route('schedules.week', [
            'date' => $schedule->start_datetime->copy()->setTimezone($schedule->user->timezone)->format('Y-m-d'),
        ]);
    }
}
