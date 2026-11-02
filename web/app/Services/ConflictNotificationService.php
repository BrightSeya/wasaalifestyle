<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Collection;

class ConflictNotificationService
{
    /**
     * @param Collection<int, array{type: string, message: string}> $conflicts
     */
    public function create(User $user, Schedule $schedule, Collection $conflicts): Notification
    {
        return $user->notifications()->create([
            'schedule_id' => $schedule->id,
            'type' => 'conflict_alert',
            'message' => 'You confirmed a schedule entry with conflicts: '.
                $conflicts->pluck('message')->implode('; '),
        ]);
    }
}
