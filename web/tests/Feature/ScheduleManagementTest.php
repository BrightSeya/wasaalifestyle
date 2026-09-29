<?php

namespace Tests\Feature;

use App\Enums\Category;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Study Session',
            'description' => 'Chapter 4',
            'category' => 'work',
            'date' => now()->addDay()->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '15:30',
            'priority' => 'high',
            'recurrence' => 'none',
        ], $overrides);
    }

    public function test_guests_cannot_access_schedules(): void
    {
        $this->get('/schedules')->assertRedirect('/login');
        $this->post('/schedules', $this->validData())->assertRedirect('/login');
    }

    public function test_index_lists_only_the_users_upcoming_entries(): void
    {
        $user = User::factory()->create();
        Schedule::factory()->for($user)->create(['title' => 'My Run', 'start_datetime' => now()->addDay(), 'end_datetime' => now()->addDay()->addHour()]);
        Schedule::factory()->for($user)->create(['title' => 'Old Meeting', 'start_datetime' => now()->subWeek(), 'end_datetime' => now()->subWeek()->addHour()]);
        Schedule::factory()->create(['title' => 'Someone Else']);

        $this->actingAs($user)->get('/schedules')
            ->assertOk()
            ->assertSee('My Run')
            ->assertDontSee('Old Meeting')
            ->assertDontSee('Someone Else');
    }

    public function test_create_page_renders(): void
    {
        $this->actingAs(User::factory()->create())->get('/schedules/create')
            ->assertOk()
            ->assertSee('Create New Task');
    }

    public function test_user_can_create_an_entry(): void
    {
        $user = User::factory()->create();
        $date = now()->addDay()->format('Y-m-d');

        $this->actingAs($user)->post('/schedules', $this->validData())
            ->assertRedirect(route('schedules.index'))
            ->assertSessionHas('status', 'Entry created.');

        $schedule = $user->schedules()->sole();
        $this->assertSame('Study Session', $schedule->title);
        $this->assertSame(Category::Work, $schedule->category);
        $this->assertSame("{$date} 14:00", $schedule->start_datetime->format('Y-m-d H:i'));
        $this->assertSame("{$date} 15:30", $schedule->end_datetime->format('Y-m-d H:i'));
        $this->assertSame('high', $schedule->priority);
        $this->assertFalse($schedule->is_recurring);
        $this->assertNull($schedule->recurrence_rule);
        $this->assertSame('pending', $schedule->fresh()->status);
    }

    public function test_recurrence_is_stored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/schedules', $this->validData(['recurrence' => 'weekly']));

        $schedule = $user->schedules()->sole();
        $this->assertTrue($schedule->is_recurring);
        $this->assertSame('weekly', $schedule->recurrence_rule);
    }

    public function test_entry_cannot_be_created_for_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post('/schedules', $this->validData(['user_id' => $other->id]));

        $this->assertSame(0, $other->schedules()->count());
        $this->assertSame(1, $user->schedules()->count());
    }

    public function test_validation_errors(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/schedules', $this->validData(['end_time' => '13:00']))
            ->assertSessionHasErrors('end_time');
        $this->actingAs($user)->post('/schedules', $this->validData(['category' => 'gaming']))
            ->assertSessionHasErrors('category');
        $this->actingAs($user)->post('/schedules', $this->validData(['title' => '', 'priority' => 'urgent', 'recurrence' => 'monthly']))
            ->assertSessionHasErrors(['title', 'priority', 'recurrence']);

        $this->assertSame(0, Schedule::count());
    }

    public function test_user_can_edit_and_update_their_entry(): void
    {
        $user = User::factory()->create();
        $schedule = Schedule::factory()->for($user)->create();

        $this->actingAs($user)->get(route('schedules.edit', $schedule))->assertOk()->assertSee($schedule->title);

        $this->actingAs($user)->put(route('schedules.update', $schedule), $this->validData(['title' => 'Updated']))
            ->assertRedirect(route('schedules.index'));

        $this->assertSame('Updated', $schedule->fresh()->title);
    }

    public function test_user_can_delete_their_entry(): void
    {
        $user = User::factory()->create();
        $schedule = Schedule::factory()->for($user)->create();

        $this->actingAs($user)->delete(route('schedules.destroy', $schedule))
            ->assertRedirect(route('schedules.index'));

        $this->assertModelMissing($schedule);
    }

    public function test_user_cannot_touch_another_users_entry(): void
    {
        $user = User::factory()->create();
        $schedule = Schedule::factory()->create();

        $this->actingAs($user)->get(route('schedules.edit', $schedule))->assertForbidden();
        $this->actingAs($user)->put(route('schedules.update', $schedule), $this->validData())->assertForbidden();
        $this->actingAs($user)->delete(route('schedules.destroy', $schedule))->assertForbidden();

        $this->assertModelExists($schedule);
        $this->assertNotSame('Study Session', $schedule->fresh()->title);
    }
}
