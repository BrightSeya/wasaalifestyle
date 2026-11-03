<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Schedule;
use App\Models\User;
use App\Services\AdminAnalyticsService;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function __invoke(AdminAnalyticsService $analytics): View
    {
        $counts = [
            'users' => User::count(),
            'active_users' => User::where('status', 'active')->count(),
            'suspended_users' => User::where('status', 'suspended')->count(),
            'schedules' => Schedule::count(),
            'activities' => Activity::count(),
        ];

        return view('admin.analytics', [
            'counts' => $counts,
            'metrics' => $analytics->modelMetrics(),
            'health' => $analytics->recommenderHealth(),
        ]);
    }
}
