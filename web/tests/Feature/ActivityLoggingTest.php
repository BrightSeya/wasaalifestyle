<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_log_an_activity(): void
    {
        $activity = Activity::factory()->create();

        $this->post(route('activities.log', $activity), ['status' => 'completed'])
            ->assertRedirect('/login');
    }

    public function test_user_can_log_an_activity_as_completed(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create(['preferred_time_of_day' => '06:30:00']);

        $this->actingAs($user)->post(route('activities.log', $activity), ['status' => 'completed'])
            ->assertRedirect(route('activities.index'))
            ->assertSessionHas('status', 'Activity logged.');

        $log = ActivityLog::sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($activity->id, $log->activity_id);
        $this->assertSame('completed', $log->status);
        $this->assertTrue($log->scheduled_for->isToday());
        $this->assertSame('06:30', $log->scheduled_for->format('H:i'));
        $this->assertNotNull($log->logged_at);
    }

    public function test_missed_and_skipped_logs_have_no_logged_at(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        $this->actingAs($user)->post(route('activities.log', $activity), ['status' => 'missed']);

        $this->assertNull(ActivityLog::sole()->logged_at);
    }

    public function test_logging_twice_in_one_day_updates_the_existing_log(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        $this->actingAs($user)->post(route('activities.log', $activity), ['status' => 'missed']);
        $this->actingAs($user)->post(route('activities.log', $activity), ['status' => 'completed']);

        $log = ActivityLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertNotNull($log->logged_at);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        $this->actingAs($user)->post(route('activities.log', $activity), ['status' => 'done'])
            ->assertSessionHasErrors('status');

        $this->assertSame(0, ActivityLog::count());
    }

    public function test_user_cannot_log_another_users_activity(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->create();

        $this->actingAs($user)->post(route('activities.log', $activity), ['status' => 'completed'])
            ->assertForbidden();

        $this->assertSame(0, ActivityLog::count());
    }

    public function test_index_shows_recent_logs_for_each_activity(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();
        ActivityLog::factory()->for($activity)->create([
            'user_id' => $user->id,
            'scheduled_for' => now()->subDay(),
            'status' => 'missed',
        ]);

        $this->actingAs($user)->get('/activities')->assertOk()->assertSee('Missed', false);
    }
}
