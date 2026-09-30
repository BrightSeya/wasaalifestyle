<?php

namespace App\Policies;

use App\Models\Recommendation;
use App\Models\User;

class RecommendationPolicy
{
    public function manage(User $user, Recommendation $recommendation): bool
    {
        return $recommendation->user()->is($user);
    }
}
