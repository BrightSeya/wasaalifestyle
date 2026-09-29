<?php

namespace App\Http\Controllers;

use App\Services\ScheduleOccurrences;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduleDayController extends Controller
{
    public function __invoke(Request $request, ScheduleOccurrences $occurrences): View
    {
        $day = $this->dateFromQuery($request);

        $entries = $occurrences->between($request->user(), $day, $day->copy()->addDay());

        return view('schedules.day', compact('day', 'entries'));
    }
}
