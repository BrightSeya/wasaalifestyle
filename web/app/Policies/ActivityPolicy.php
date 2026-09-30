<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;

class ActivityPolicy
{
    public function update(User $user, Activity $activity): bool
    {
        return $activity->user()->is($user);
    }

    public function delete(User $user, Activity $activity): bool
    {
        return $activity->user()->is($user);
    }
}
