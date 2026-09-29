<?php

namespace Tests\Feature;

use App\Enums\Category;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Recommendation;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_default_to_active_non_admin(): void
    {
        $user = User::factory()->create()->fresh();

        $this->assertSame('user', $user->role);
        $this->assertSame('active', $user->status);
        $this->assertFalse($user->isAdmin());
        $this->assertFalse($user->isSuspended());
    }

    public function test_role_and_status_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();

        $user->update(['role' => 'admin', 'status' => 'suspended']);

        $this->assertSame('user', $user->fresh()->role);
        $this->assertSame('active', $user->fresh()->status);
    }

    public function test_user_has_many_of_each_related_record(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        Schedule::factory(2)->for($user)->create();
        ActivityLog::factory(3)->for($activity)->create();
        Recommendation::factory()->for($user)->create();
        Notification::factory()->for($user)->create();

        $this->assertCount(2, $user->schedules);
        $this->assertCount(1, $user->activities);
        $this->assertCount(3, $user->activityLogs);
        $this->assertCount(1, $user->recommendations);
        $this->assertCount(1, $user->notifications);
        $this->assertCount(3, $activity->logs);
    }

    public function test_activity_log_belongs_to_the_activity_owner(): void
    {
        $log = ActivityLog::factory()->create();

        $this->assertSame($log->activity->user_id, $log->user_id);
    }

    public function test_category_is_cast_to_enum(): void
    {
        $activity = Activity::factory()->create(['category' => 'exercise']);

        $this->assertSame(Category::Exercise, $activity->fresh()->category);
    }

    public function test_preferences_are_stored_as_json(): void
    {
        $user = User::factory()->create(['preferences' => ['peak_start' => '09:00', 'peak_end' => '17:00']]);

        $this->assertSame('09:00', $user->fresh()->preferences['peak_start']);
    }

    public function test_notification_can_reference_a_schedule(): void
    {
        $schedule = Schedule::factory()->create();
        $notification = Notification::factory()->for($schedule->user)->create([
            'schedule_id' => $schedule->id,
            'type' => 'conflict_alert',
        ]);

        $this->assertTrue($notification->schedule->is($schedule));
        $this->assertCount(1, $schedule->notifications);
    }

    public function test_deleting_a_user_cascades_to_their_records(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();
        ActivityLog::factory()->for($activity)->create();
        $schedule = Schedule::factory()->for($user)->create();
        Recommendation::factory()->for($user)->create(['activity_id' => $activity->id]);
        Notification::factory()->for($user)->create(['schedule_id' => $schedule->id]);

        $user->delete();

        $this->assertDatabaseCount('schedules', 0);
        $this->assertDatabaseCount('activities', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('recommendations', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_deleting_an_activity_removes_its_logs(): void
    {
        $log = ActivityLog::factory()->create();

        $log->activity->delete();

        $this->assertModelMissing($log);
    }
}
