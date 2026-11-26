<?php

namespace App\Http\Controllers;

use App\Models\ScheduleOccurrenceOutcome;
use App\Services\ScheduleOccurrenceLayout;
use App\Services\ScheduleOccurrences;
use App\Support\UserTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ScheduleWeekController extends Controller
{
    private const DEFAULT_START_HOUR = 6;

    private const DEFAULT_END_HOUR = 22;

    public function __invoke(Request $request, ScheduleOccurrences $occurrences, ScheduleOccurrenceLayout $layout): View
    {
        $weekStart = $this->dateFromQuery($request)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addWeek();
        $weekStartUtc = UserTime::utc($request->user(), $weekStart);
        $weekEndUtc = UserTime::utc($request->user(), $weekEnd);

        $entries = $occurrences->between($request->user(), $weekStart, $weekEnd);
        $scheduleIds = $entries->map(fn ($entry) => $entry->schedule->id)->unique();
        $occurrenceOutcomes = ScheduleOccurrenceOutcome::query()
            ->whereIn('schedule_id', $scheduleIds)
            ->where('occurrence_start', '>=', $weekStartUtc)
            ->where('occurrence_start', '<', $weekEndUtc)
            ->get()
            ->keyBy(fn (ScheduleOccurrenceOutcome $outcome) => $outcome->schedule_id.'|'.$outcome->occurrence_start->copy()->setTimezone(UserTime::timezone($request->user()))->format('Y-m-d H:i:s'));

        $days = collect(range(0, 6))->map(fn (int $offset) => $weekStart->copy()->addDays($offset));
        $allDayEntriesByDay = $entries->filter(fn ($entry) => $entry->schedule->is_all_day)
            ->groupBy(fn ($entry) => $entry->start->format('Y-m-d'));
        $timedEntries = $entries->reject(fn ($entry) => $entry->schedule->is_all_day)->values();
        $entriesByDay = $timedEntries
            ->groupBy(fn ($entry) => $entry->start->format('Y-m-d'))
            ->map(fn ($dayEntries) => $layout->assignLanes($dayEntries));

        // Stretch the grid when an entry falls outside the default hours.
        $startHour = min(self::DEFAULT_START_HOUR, $timedEntries->min(fn ($e) => $e->start->hour) ?? self::DEFAULT_START_HOUR);
        $endHour = min(24, max(
            self::DEFAULT_END_HOUR,
            $timedEntries->max(fn ($e) => $e->end->hour + ($e->end->minute > 0 ? 1 : 0)) ?? self::DEFAULT_END_HOUR,
        ));

        return view('schedules.week', [
            'weekStart' => $weekStart,
            'days' => $days,
            'entriesByDay' => $entriesByDay,
            'allDayEntriesByDay' => $allDayEntriesByDay,
            'occurrenceOutcomes' => $occurrenceOutcomes,
            'startHour' => $startHour,
            'endHour' => $endHour,
        ]);
    }
}
