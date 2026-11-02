<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Recommendation;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_shows_the_users_upcoming_and_recurring_entries(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $tomorrow = today()->addDay()->setTime(10, 0);

        Schedule::factory()->for($user)->create([
            'title' => 'My upcoming task',
            'start_datetime' => $tomorrow,
            'end_datetime' => $tomorrow->copy()->addHour(),
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);
        Schedule::factory()->for($user)->create([
            'title' => 'My weekly routine',
            'start_datetime' => today()->subWeek()->setTime(8, 0),
            'end_datetime' => today()->subWeek()->setTime(9, 0),
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
        ]);
        Schedule::factory()->for($otherUser)->create([
            'title' => 'Another user task',
            'start_datetime' => $tomorrow->copy()->setTime(12, 0),
            'end_datetime' => $tomorrow->copy()->setTime(13, 0),
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('My upcoming task')
            ->assertSee('My weekly routine')
            ->assertDontSee('Another user task')
            ->assertSee('aria-label="2 schedule entries in the next 7 days"', false);
    }

    public function test_dashboard_shows_pending_recommendations_unread_notifications_and_completion_chart(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();
        ActivityLog::factory()->for($activity)->create([
            'user_id' => $user->id,
            'scheduled_for' => today()->setTime(9, 0),
            'status' => 'completed',
        ]);
        $otherActivity = Activity::factory()->for($otherUser)->create();
        ActivityLog::factory()->for($otherActivity)->create([
            'user_id' => $otherUser->id,
            'scheduled_for' => today()->setTime(9, 0),
            'status' => 'completed',
        ]);
        Recommendation::factory()->for($user)->create(['status' => 'pending']);
        Recommendation::factory()->count(2)->for($otherUser)->create(['status' => 'pending']);
        Notification::factory()->for($user)->create(['is_read' => false]);
        Notification::factory()->count(2)->for($otherUser)->create(['is_read' => false]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-label="1 pending recommendations"', false)
            ->assertSee('aria-label="1 unread notifications"', false)
            ->assertSee('id="dashboard-completion-chart"', false)
            ->assertSee('&quot;percentage&quot;:100', false);
    }

    public function test_dashboard_shows_empty_states_without_schedule_or_activity_history(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No scheduled entries in the next seven days.')
            ->assertSee('No activity history yet. Log an activity to start your progress chart.')
            ->assertSee('aria-label="0 pending recommendations"', false)
            ->assertSee('aria-label="0 unread notifications"', false)
            ->assertSee('id="dashboard-completion-chart"', false);
    }
}
