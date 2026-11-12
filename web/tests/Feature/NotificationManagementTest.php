<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_only_their_notifications_newest_first(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $older = Notification::factory()->for($user)->create([
            'message' => 'Older notice',
            'created_at' => now()->subHour(),
        ]);
        Notification::factory()->for($otherUser)->create(['message' => 'Private notice']);
        $newer = Notification::factory()->for($user)->create([
            'message' => 'Newest notice',
            'type' => 'conflict_alert',
            'created_at' => now(),
        ]);

        $this->actingAs($user)->get(route('notifications.index'))
            ->assertOk()
            ->assertSeeInOrder(['Newest notice', 'Older notice'])
            ->assertDontSee('Private notice')
            ->assertSee('Conflict Alert');

        $this->assertNotSame($older->id, $newer->id);
    }

    public function test_user_can_mark_a_notification_as_read(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->for($user)->create(['is_read' => false]);

        $this->actingAs($user)->patch(route('notifications.read', $notification))
            ->assertRedirect()
            ->assertSessionHas('status', 'Notification marked as read.');

        $this->assertTrue($notification->fresh()->is_read);
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $notification = Notification::factory()->for($otherUser)->create(['is_read' => false]);

        $this->actingAs($user)->patch(route('notifications.read', $notification))
            ->assertNotFound();

        $this->assertFalse($notification->fresh()->is_read);
    }

    public function test_user_can_mark_all_their_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->for($user)->create(['is_read' => false]);
        $otherNotification = Notification::factory()->create(['is_read' => false]);

        $this->actingAs($user)->post(route('notifications.read-all'))
            ->assertRedirect()
            ->assertSessionHas('status', 'All notifications marked as read.');

        $this->assertTrue($notification->fresh()->is_read);
        $this->assertFalse($otherNotification->fresh()->is_read);
    }

    public function test_sidebar_shows_the_unread_notification_count(): void
    {
        $user = User::factory()->create();
        Notification::factory(2)->for($user)->create(['is_read' => false]);
        Notification::factory()->for($user)->create(['is_read' => true]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('aria-label="2 unread notifications"', false);
    }

    public function test_user_can_dismiss_their_commitment_alert_and_it_is_hidden(): void
    {
        $user = User::factory()->create();
        $schedule = Schedule::factory()->for($user)->create();
        $assessment = $schedule->riskAssessments()->create([
            'occurrence_start' => now()->addHour(),
            'probability' => 0.8,
            'model_version' => 'test-model',
            'assessed_at' => now(),
            'schedule_updated_at' => $schedule->updated_at,
        ]);
        $notification = Notification::factory()->for($user)->create([
            'schedule_id' => $schedule->id,
            'risk_assessment_id' => $assessment->id,
        ]);

        $this->actingAs($user)->patch(route('notifications.dismiss', $notification))
            ->assertRedirect()
            ->assertSessionHas('status', 'Commitment alert dismissed.');

        $this->assertTrue($notification->fresh()->is_read);
        $this->assertNotNull($notification->fresh()->dismissed_at);
        $this->actingAs($user)->get(route('notifications.index'))->assertDontSee($notification->message);
    }

    public function test_user_cannot_dismiss_another_users_commitment_alert(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $schedule = Schedule::factory()->for($owner)->create();
        $assessment = $schedule->riskAssessments()->create([
            'occurrence_start' => now()->addHour(),
            'probability' => 0.8,
            'model_version' => 'test-model',
            'assessed_at' => now(),
            'schedule_updated_at' => $schedule->updated_at,
        ]);
        $notification = Notification::factory()->for($owner)->create([
            'risk_assessment_id' => $assessment->id,
            'message' => 'Private commitment-risk alert',
        ]);

        $this->actingAs($user)->patch(route('notifications.dismiss', $notification))->assertNotFound();
        $this->assertNull($notification->fresh()->dismissed_at);
        $this->actingAs($user)->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('Private commitment-risk alert');
    }
}
