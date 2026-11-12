<?php

namespace App\Observers;

use App\Models\Schedule;

class ScheduleObserver
{
    public function updated(Schedule $schedule): void
    {
        $schedule->riskAssessments()->delete();
    }
}
