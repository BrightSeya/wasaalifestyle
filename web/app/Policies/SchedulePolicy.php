<?php

namespace App\Policies;

use App\Models\Schedule;
use App\Models\User;

class SchedulePolicy
{
    public function update(User $user, Schedule $schedule): bool
    {
        return $schedule->user()->is($user);
    }

    public function delete(User $user, Schedule $schedule): bool
    {
        return $schedule->user()->is($user);
    }
}
