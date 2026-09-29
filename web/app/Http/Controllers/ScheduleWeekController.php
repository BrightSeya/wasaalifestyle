<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ScheduleWeekController extends Controller
{
    private const DEFAULT_START_HOUR = 6;

    private const DEFAULT_END_HOUR = 22;

    public function __invoke(Request $request): View
    {
        $input = $request->query('date');
        $day = is_string($input) && Carbon::canBeCreatedFromFormat($input, 'Y-m-d')
            ? Carbon::createFromFormat('Y-m-d', $input)
            : now();

        $weekStart = $day->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEnd = $weekStart->copy()->addWeek();

        $entries = $request->user()->schedules()
            ->where('start_datetime', '>=', $weekStart)
            ->where('start_datetime', '<', $weekEnd)
            ->orderBy('start_datetime')
            ->get();

        $days = collect(range(0, 6))->map(fn (int $offset) => $weekStart->copy()->addDays($offset));
        $entriesByDay = $entries->groupBy(fn ($entry) => $entry->start_datetime->format('Y-m-d'));

        // Stretch the grid when an entry falls outside the default hours.
        $startHour = min(self::DEFAULT_START_HOUR, $entries->min(fn ($e) => $e->start_datetime->hour) ?? self::DEFAULT_START_HOUR);
        $endHour = min(24, max(
            self::DEFAULT_END_HOUR,
            $entries->max(fn ($e) => $e->end_datetime->hour + ($e->end_datetime->minute > 0 ? 1 : 0)) ?? self::DEFAULT_END_HOUR,
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
