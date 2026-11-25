<?php

namespace App\Http\Controllers;

use App\Models\ScheduleOccurrenceOutcome;
use App\Services\ScheduleOccurrences;
use App\Support\UserTime;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduleDayController extends Controller
{
    public function __invoke(Request $request, ScheduleOccurrences $occurrences): View
    {
        $day = $this->dateFromQuery($request);
        $dayUtc = UserTime::utc($request->user(), $day);
        $nextDayUtc = UserTime::utc($request->user(), $day->copy()->addDay());

        $entries = $occurrences->between($request->user(), $day, $day->copy()->addDay());
        $scheduleIds = $entries->map(fn ($entry) => $entry->schedule->id)->unique();
        $occurrenceOutcomes = ScheduleOccurrenceOutcome::query()
            ->whereIn('schedule_id', $scheduleIds)
            ->where('occurrence_start', '>=', $dayUtc)
            ->where('occurrence_start', '<', $nextDayUtc)
            ->get()
            ->keyBy(fn (ScheduleOccurrenceOutcome $outcome) => $outcome->schedule_id.'|'.$outcome->occurrence_start->copy()->setTimezone(UserTime::timezone($request->user()))->format('Y-m-d H:i:s'));

        return view('schedules.day', compact('day', 'entries', 'occurrenceOutcomes'));
    }
}
