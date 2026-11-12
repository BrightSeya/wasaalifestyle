<?php

namespace App\Http\Controllers;

use App\Models\ScheduleOccurrenceOutcome;
use App\Services\ScheduleOccurrences;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduleDayController extends Controller
{
    public function __invoke(Request $request, ScheduleOccurrences $occurrences): View
    {
        $day = $this->dateFromQuery($request);

        $entries = $occurrences->between($request->user(), $day, $day->copy()->addDay());
        $scheduleIds = $entries->map(fn ($entry) => $entry->schedule->id)->unique();
        $occurrenceOutcomes = ScheduleOccurrenceOutcome::query()
            ->whereIn('schedule_id', $scheduleIds)
            ->where('occurrence_start', '>=', $day)
            ->where('occurrence_start', '<', $day->copy()->addDay())
            ->get()
            ->keyBy(fn (ScheduleOccurrenceOutcome $outcome) => $outcome->schedule_id.'|'.$outcome->occurrence_start->format('Y-m-d H:i:s'));

        return view('schedules.day', compact('day', 'entries', 'occurrenceOutcomes'));
    }
}
