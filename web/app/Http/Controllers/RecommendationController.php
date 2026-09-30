<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Recommendation;
use App\Services\RecommendationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RecommendationController extends Controller
{
    public function index(Request $request): View
    {
        $recommendations = $request->user()->recommendations()
            ->with('activity')
            ->where('status', 'pending')
            ->orderByDesc('confidence_score')
            ->get();

        return view('recommendations.index', compact('recommendations'));
    }

    public function store(Request $request, RecommendationService $service, Activity $activity): RedirectResponse
    {
        Gate::authorize('update', $activity);

        $result = $service->recommend($request->user(), $activity);

        if (! $result['ok']) {
            return back()->with('error', $result['message']);
        }

        foreach ($result['recommendations'] as $slot) {
            $start = today()->setHour($slot['hour'])->setMinute(0)->setSecond(0);
            if ($start->lt(now())) {
                $start->addDay();
            }

            $activity->recommendations()->create([
                'user_id' => $request->user()->id,
                'category' => $activity->category,
                'suggested_start' => $start,
                'suggested_end' => $start->copy()->addMinutes($activity->duration_minutes),
                'confidence_score' => $slot['confidence'],
                'status' => 'pending',
                'generated_at' => now(),
            ]);
        }

        return redirect()->route('recommendations.index')->with('status', 'Recommendations ready.');
    }

    public function accept(Recommendation $recommendation): RedirectResponse
    {
        Gate::authorize('manage', $recommendation);

        $recommendation->user->schedules()->create([
            'title' => $recommendation->activity?->name ?? ucfirst($recommendation->category->value),
            'category' => $recommendation->category,
            'start_datetime' => $recommendation->suggested_start,
            'end_datetime' => $recommendation->suggested_end,
            'priority' => 'medium',
            'status' => 'pending',
        ]);

        $recommendation->update(['status' => 'accepted']);

        return redirect()->route('recommendations.index')->with('status', 'Added to your schedule.');
    }

    public function dismiss(Recommendation $recommendation): RedirectResponse
    {
        Gate::authorize('manage', $recommendation);

        $recommendation->update(['status' => 'dismissed']);

        return redirect()->route('recommendations.index')->with('status', 'Recommendation dismissed.');
    }
}
