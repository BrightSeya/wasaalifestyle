<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Services\ScheduleOccurrences;
use App\Support\Occurrence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ScheduleOccurrenceOutcomeController extends Controller
{
    public function store(Request $request, Schedule $schedule, ScheduleOccurrences $occurrences): RedirectResponse
    {
        Gate::authorize('update', $schedule);

        $data = $request->validate([
            'occurrence_start' => ['required', 'date'],
            'outcome' => ['required', Rule::in(['completed', 'missed', 'skipped', 'cancelled'])],
        ]);

        $start = Carbon::parse($data['occurrence_start']);
        $dayStart = $start->copy()->startOfDay();
        $occurrence = $occurrences->between($request->user(), $dayStart, $dayStart->copy()->addDay())
            ->first(fn (Occurrence $candidate) => $candidate->schedule->is($schedule)
                && $candidate->start->equalTo($start));

        if (! $occurrence) {
            return back()->withErrors([
                'occurrence_start' => 'That date is not an occurrence of this schedule entry.',
            ]);
        }

        if ($data['outcome'] !== 'cancelled' && ($occurrence->end->isFuture() || $occurrence->outcome === 'cancelled')) {
            return back()->withErrors([
                'outcome' => 'Record an outcome after the scheduled time has ended.',
            ]);
        }

        $schedule->occurrenceOutcomes()->updateOrCreate(
            ['occurrence_start' => $occurrence->start],
            [
                'outcome' => $data['outcome'],
                'recorded_at' => now(),
            ],
        );

        return to_route('schedules.week', ['date' => $occurrence->start->format('Y-m-d')])
            ->with('status', 'Schedule outcome recorded.');
    }
}
