<?php

namespace App\Http\Controllers;

use App\Services\ScheduleOccurrences;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ScheduleWeekController extends Controller
{
    private const DEFAULT_START_HOUR = 6;

    private const DEFAULT_END_HOUR = 22;

    public function __invoke(Request $request, ScheduleOccurrences $occurrences): View
    {
        $weekStart = $this->dateFromQuery($request)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addWeek();

        $entries = $occurrences->between($request->user(), $weekStart, $weekEnd);

        $days = collect(range(0, 6))->map(fn (int $offset) => $weekStart->copy()->addDays($offset));
        $entriesByDay = $entries->groupBy(fn ($entry) => $entry->start->format('Y-m-d'));

        // Stretch the grid when an entry falls outside the default hours.
        $startHour = min(self::DEFAULT_START_HOUR, $entries->min(fn ($e) => $e->start->hour) ?? self::DEFAULT_START_HOUR);
        $endHour = min(24, max(
            self::DEFAULT_END_HOUR,
            $entries->max(fn ($e) => $e->end->hour + ($e->end->minute > 0 ? 1 : 0)) ?? self::DEFAULT_END_HOUR,
        ));

        return view('schedules.week', [
            'weekStart' => $weekStart,
            'days' => $days,
            'entriesByDay' => $entriesByDay,
            'startHour' => $startHour,
            'endHour' => $endHour,
        ]);
    }
}
