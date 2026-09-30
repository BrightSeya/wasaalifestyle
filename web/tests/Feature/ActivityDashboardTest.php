<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_activities_page_shows_a_streak_for_each_activity(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();
        ActivityLog::factory()->for($activity)->create([
            'user_id' => $user->id,
            'scheduled_for' => now(),
            'status' => 'completed',
        ]);
        ActivityLog::factory()->for($activity)->create([
            'user_id' => $user->id,
            'scheduled_for' => now()->subDay(),
            'status' => 'completed',
        ]);

        $this->actingAs($user)->get('/activities')
            ->assertOk()
            ->assertSee('2 days');
    }

    public function test_activities_page_includes_the_completion_chart_data(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();
        ActivityLog::factory()->for($activity)->create([
            'user_id' => $user->id,
            'scheduled_for' => now(),
            'status' => 'completed',
        ]);

        $this->actingAs($user)->get('/activities')
            ->assertOk()
            ->assertSee('Activity Completion History')
            ->assertSee('id="completion-chart"', false)
            ->assertSee('&quot;percentage&quot;:100', false);
    }
}
