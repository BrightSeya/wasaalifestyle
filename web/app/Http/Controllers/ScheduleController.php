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
            ->where('end_datetime', '>=', now()->startOfDay())
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
        $request->user()->schedules()->create($request->scheduleData());

        return redirect()->route('schedules.index')->with('status', 'Entry created.');
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

        return redirect()->route('schedules.index')->with('status', 'Entry updated.');
    }

    public function destroy(Schedule $schedule): RedirectResponse
    {
        Gate::authorize('delete', $schedule);

        $schedule->delete();

        return redirect()->route('schedules.index')->with('status', 'Entry deleted.');
    }
}
