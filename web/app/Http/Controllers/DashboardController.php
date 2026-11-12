<?php

namespace App\Http\Controllers;

use App\Services\ScheduleOccurrences;
use App\Support\ActivityStats;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const OVERVIEW_DAYS = 7;

    public function __invoke(Request $request, ScheduleOccurrences $occurrences): View
    {
        $user = $request->user();
        $today = today();
        $scheduleDays = collect(range(0, self::OVERVIEW_DAYS - 1))
            ->map(fn (int $offset) => $today->copy()->addDays($offset));
        $upcomingEntries = $occurrences->between(
            $user,
            $today,
            $today->copy()->addDays(self::OVERVIEW_DAYS),
        );
        $upcomingEntries = $upcomingEntries->reject(fn ($entry) => $entry->outcome === 'cancelled')->values();
        $recentLogs = $user->activityLogs()
            ->where('scheduled_for', '>=', $today->copy()->subDays(self::OVERVIEW_DAYS - 1))
            ->where('scheduled_for', '<', $today->copy()->addDay())
            ->get();

        return view('dashboard', [
            'scheduleDays' => $scheduleDays,
            'entriesByDate' => $upcomingEntries->groupBy(fn ($entry) => $entry->start->toDateString()),
            'upcomingEntryCount' => $upcomingEntries->count(),
            'completionByDay' => ActivityStats::completionByDay($recentLogs, self::OVERVIEW_DAYS, $today),
            'hasActivityHistory' => $recentLogs->isNotEmpty(),
            'pendingRecommendationCount' => $user->recommendations()->where('status', 'pending')->count(),
            'unreadNotificationCount' => $user->notifications()->where('is_read', false)->count(),
        ]);
    }
}
