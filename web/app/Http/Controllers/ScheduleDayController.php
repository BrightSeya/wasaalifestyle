<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduleDayController extends Controller
{
    public function __invoke(Request $request): View
    {
        $day = $this->dateFromQuery($request);

        $entries = $request->user()->schedules()
            ->where('start_datetime', '>=', $day)
            ->where('start_datetime', '<', $day->copy()->addDay())
            ->orderBy('start_datetime')
            ->get();

        return view('schedules.day', compact('day', 'entries'));
    }
}
