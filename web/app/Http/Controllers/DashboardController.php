<?php

namespace App\Http\Controllers;

use App\Services\ScheduleOccurrences;
use App\Support\ActivityStats;
use App\Support\UserTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const OVERVIEW_DAYS = 7;

    public function __invoke(Request $request, ScheduleOccurrences $occurrences): View
    {
        $user = $request->user();
        $today = Carbon::now(UserTime::timezone($user))->startOfDay();
        $scheduleDays = collect(range(0, self::OVERVIEW_DAYS - 1))
            ->map(fn (int $offset) => $today->copy()->addDays($offset));
        $upcomingEntries = $occurrences->between(
            $user,
            $today,
            $today->copy()->addDays(self::OVERVIEW_DAYS),
        );
        $upcomingEntries = $upcomingEntries->reject(fn ($entry) => $entry->outcome === 'cancelled')->values();
        $recentLogs = $user->activityLogs()
            ->where('scheduled_for', '>=', UserTime::utc($user, $today->copy()->subDays(self::OVERVIEW_DAYS - 1)))
            ->where('scheduled_for', '<', UserTime::utc($user, $today->copy()->addDay()))
            ->get();
        $recentLogs->each(fn ($log) => $log->setAttribute('scheduled_for', UserTime::local($user, $log->scheduled_for)));

        return view('dashboard', [
            'scheduleDays' => $scheduleDays,
            'entriesByDate' => $upcomingEntries->groupBy(fn ($entry) => $entry->start->toDateString()),
            'upcomingEntryCount' => $upcomingEntries->count(),
            'completionByDay' => ActivityStats::completionByDay($recentLogs, self::OVERVIEW_DAYS, $today),
            'hasActivityHistory' => $recentLogs->isNotEmpty(),
            'recommendationActivityCount' => $user->activities()->count(),
            'unreadNotificationCount' => $user->notifications()->where('is_read', false)->count(),
        ]);
    }
}
