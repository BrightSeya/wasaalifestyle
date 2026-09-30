<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivityRequest;
use App\Models\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function index(Request $request): View
    {
        $activities = $request->user()->activities()
            ->with(['logs' => fn ($query) => $query->orderByDesc('scheduled_for')->limit(5)])
            ->orderBy('name')
            ->get();

        return view('activities.index', compact('activities'));
    }

    public function create(): View
    {
        return view('activities.create', ['activity' => new Activity(['frequency' => 3, 'duration_minutes' => 30])]);
    }

    public function store(ActivityRequest $request): RedirectResponse
    {
        $request->user()->activities()->create($request->validated());

        return redirect()->route('activities.index')->with('status', 'Activity added.');
    }

    public function edit(Activity $activity): View
    {
        Gate::authorize('update', $activity);

        return view('activities.edit', compact('activity'));
    }

    public function update(ActivityRequest $request, Activity $activity): RedirectResponse
    {
        Gate::authorize('update', $activity);

        $activity->update($request->validated());

        return redirect()->route('activities.index')->with('status', 'Activity updated.');
    }

    public function destroy(Activity $activity): RedirectResponse
    {
        Gate::authorize('delete', $activity);

        $activity->delete();

        return redirect()->route('activities.index')->with('status', 'Activity deleted.');
    }

    public function log(Request $request, Activity $activity): RedirectResponse
    {
        Gate::authorize('update', $activity);

        $data = $request->validate([
            'status' => ['required', Rule::in(['completed', 'missed', 'skipped'])],
        ]);

        $log = $activity->logs()->whereDate('scheduled_for', today())->first()
            ?? $activity->logs()->make(['user_id' => $activity->user_id]);

        $log->fill([
            'scheduled_for' => today()->setTimeFromTimeString($activity->preferred_time_of_day),
            'status' => $data['status'],
            'logged_at' => $data['status'] === 'completed' ? now() : null,
        ])->save();

        return redirect()->route('activities.index')->with('status', 'Activity logged.');
    }
}
