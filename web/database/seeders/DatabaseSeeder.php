<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Recommendation;
use App\Models\Schedule;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        Activity::factory(5)->for($user)->create()->each(
            fn (Activity $activity) => ActivityLog::factory(8)->for($activity)->create()
        );

        Schedule::factory(10)->for($user)->create();
        Recommendation::factory(3)->for($user)->create();
        Notification::factory(2)->for($user)->create();
    }
}
