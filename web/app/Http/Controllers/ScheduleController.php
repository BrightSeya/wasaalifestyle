<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScheduleRequest;
use App\Models\Schedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function store(ScheduleRequest $request): RedirectResponse
    {
        $schedule = $request->user()->schedules()->create($request->scheduleData());

        return $this->backToWeek($schedule)->with('status', 'Entry created.');
    }

    public function edit(Schedule $schedule): View
    {
        Gate::authorize('update', $schedule);

        return view('schedules.edit', compact('schedule'));
    }

    public function update(ScheduleRequest $request, Schedule $schedule): RedirectResponse
    {
        Gate::authorize('update', $schedule);

        $schedule->update($request->scheduleData());

        return $this->backToWeek($schedule)->with('status', 'Entry updated.');
    }

    public function destroy(Schedule $schedule): RedirectResponse
    {
        Gate::authorize('delete', $schedule);

        $schedule->delete();

        return $this->backToWeek($schedule)->with('status', 'Entry deleted.');
    }

    private function backToWeek(Schedule $schedule): RedirectResponse
    {
        return redirect()->route('schedules.week', ['date' => $schedule->start_datetime->format('Y-m-d')]);
    }
}
