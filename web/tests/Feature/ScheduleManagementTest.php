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
            ->assertSee('Create New Task')
            ->assertSee('On a date')
            ->assertSee('After a number of occurrences')
            ->assertDontSee('<option value="never"', false);
    }

    public function test_user_can_create_an_entry(): void
    {
        $user = User::factory()->create();
        $date = now()->addDay()->format('Y-m-d');

        $this->actingAs($user)->post('/schedules', $this->validData())
            ->assertRedirect(route('schedules.week', ['date' => $date]))
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

    public function test_schedule_times_are_stored_in_utc_and_shown_in_the_users_time_zone(): void
    {
        $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);
        $date = now()->addDay()->format('Y-m-d');

        $this->actingAs($user)->post('/schedules', $this->validData([
            'date' => $date,
            'start_time' => '14:00',
            'end_time' => '15:00',
        ]))->assertRedirect(route('schedules.week', ['date' => $date]));

        $schedule = $user->schedules()->sole();
        $this->assertSame("{$date} 05:00", $schedule->start_datetime->format('Y-m-d H:i'));
        $this->actingAs($user)->get(route('schedules.edit', $schedule))
            ->assertOk()
            ->assertSee('value="14:00"', false)
            ->assertSee('value="15:00"', false);
    }

    public function test_recurrence_is_stored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/schedules', $this->validData([
            'recurrence' => 'weekly',
            'recurrence_ends' => 'after',
            'recurrence_count' => 5,
        ]));

        $schedule = $user->schedules()->sole();
        $this->assertTrue($schedule->is_recurring);
        $this->assertSame('weekly', $schedule->recurrence_rule);
        $this->assertSame(5, $schedule->recurrence_count);
    }

    public function test_confirming_a_recurring_task_skips_only_the_conflicting_occurrence(): void
    {
        $user = User::factory()->create();
        $firstDate = now()->addDays(2)->startOfDay();
        $conflictingDate = $firstDate->copy()->addDay();
        Schedule::factory()->for($user)->create([
            'start_datetime' => $conflictingDate->copy()->setTime(14, 30),
            'end_datetime' => $conflictingDate->copy()->setTime(15, 0),
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);

        $this->actingAs($user)->post('/schedules', $this->validData([
            'date' => $firstDate->toDateString(),
            'recurrence' => 'daily',
            'recurrence_ends' => 'after',
            'recurrence_count' => 3,
        ]))->assertSessionHas('conflicts');

        $this->actingAs($user)->post('/schedules', $this->validData([
            'date' => $firstDate->toDateString(),
            'recurrence' => 'daily',
            'recurrence_ends' => 'after',
            'recurrence_count' => 3,
            'confirmed' => '1',
        ]))->assertSessionHas('status', 'Entry created.');

        $recurring = $user->schedules()->where('is_recurring', true)->sole();
        $this->assertDatabaseHas('schedule_occurrence_outcomes', [
            'schedule_id' => $recurring->id,
            'occurrence_start' => $conflictingDate->copy()->setTime(14, 0)->format('Y-m-d H:i:s'),
            'outcome' => 'cancelled',
        ]);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_finite_recurring_task_rejects_a_clash_with_an_existing_infinite_pattern(): void
    {
        $user = User::factory()->create();
        $firstDate = now()->addDays(2)->startOfDay();
        Schedule::factory()->for($user)->create([
            'start_datetime' => $firstDate->copy()->setTime(14, 30),
            'end_datetime' => $firstDate->copy()->setTime(15, 0),
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
        ]);

        $this->actingAs($user)->post('/schedules', $this->validData([
            'date' => $firstDate->toDateString(),
            'recurrence' => 'daily',
            'recurrence_ends' => 'after',
            'recurrence_count' => 10,
        ]))->assertSessionHasErrors('start_time');

        $this->assertSame(1, $user->schedules()->count());
    }

    public function test_recurring_tasks_require_a_finite_end(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/schedules', $this->validData([
            'recurrence' => 'daily',
        ]))->assertSessionHasErrors('recurrence_ends');
        $this->actingAs($user)->post('/schedules', $this->validData([
            'recurrence' => 'daily',
            'recurrence_ends' => 'never',
        ]))->assertSessionHasErrors('recurrence_ends');

        $this->assertSame(0, $user->schedules()->count());
    }

    public function test_recurring_task_end_date_and_occurrence_count_cannot_exceed_one_year(): void
    {
        $user = User::factory()->create();
        $date = '2027-01-04';

        $this->actingAs($user)->post('/schedules', $this->validData([
            'date' => $date,
            'recurrence' => 'daily',
            'recurrence_ends' => 'on_date',
            'recurrence_until' => '2028-01-05',
        ]))->assertSessionHasErrors('recurrence_until');
        $this->actingAs($user)->post('/schedules', $this->validData([
            'date' => $date,
            'recurrence' => 'daily',
            'recurrence_ends' => 'after',
            'recurrence_count' => 367,
        ]))->assertSessionHasErrors('recurrence_count');
        $this->actingAs($user)->post('/schedules', $this->validData([
            'date' => $date,
            'recurrence' => 'weekly',
            'recurrence_weekday' => 1,
            'recurrence_ends' => 'after',
            'recurrence_count' => 54,
        ]))->assertSessionHasErrors('recurrence_count');

        $this->actingAs($user)->post('/schedules', $this->validData([
            'date' => $date,
            'recurrence' => 'daily',
            'recurrence_ends' => 'on_date',
            'recurrence_until' => '2028-01-04',
        ]))->assertSessionHas('status', 'Entry created.');
        $this->assertSame('2028-01-04', $user->schedules()->sole()->recurrence_end_date->toDateString());
    }

    public function test_editing_an_existing_unbounded_series_requires_choosing_a_finite_end(): void
    {
        $user = User::factory()->create();
        $schedule = Schedule::factory()->for($user)->create([
            'is_recurring' => true,
            'recurrence_rule' => 'daily',
            'recurrence_end_date' => null,
            'recurrence_count' => null,
        ]);

        $this->actingAs($user)->get(route('schedules.edit', $schedule))
            ->assertOk()
            ->assertSee('Choose an end')
            ->assertDontSee('<option value="never"', false);
        $this->actingAs($user)->put(route('schedules.update', $schedule), $this->validData([
            'recurrence' => 'daily',
        ]))->assertSessionHasErrors('recurrence_ends');

        $this->assertNull($schedule->fresh()->recurrence_count);
        $this->assertNull($schedule->fresh()->recurrence_end_date);
    }

    public function test_adding_a_one_off_task_skips_only_the_conflicting_occurrence_of_a_never_series(): void
    {
        $user = User::factory()->create();
        $date = now()->addDays(2)->startOfDay();
        $series = Schedule::factory()->for($user)->create([
            'start_datetime' => $date->copy()->setTime(14, 0),
            'end_datetime' => $date->copy()->setTime(15, 0),
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
        ]);

        $this->actingAs($user)->post('/schedules', $this->validData([
            'date' => $date->toDateString(),
            'start_time' => '14:30',
            'end_time' => '15:00',
        ]))->assertSessionHas('conflicts');

        $this->actingAs($user)->post('/schedules', $this->validData([
            'date' => $date->toDateString(),
            'start_time' => '14:30',
            'end_time' => '15:00',
            'confirmed' => '1',
        ]))->assertSessionHas('status', 'Entry created.');

        $this->assertDatabaseHas('schedule_occurrence_outcomes', [
            'schedule_id' => $series->id,
            'occurrence_start' => $date->copy()->setTime(14, 0)->format('Y-m-d H:i:s'),
            'outcome' => 'cancelled',
        ]);
        $this->assertDatabaseCount('notifications', 0);
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
            ->assertRedirect(route('schedules.week', ['date' => now()->addDay()->format('Y-m-d')]));

        $this->assertSame('Updated', $schedule->fresh()->title);
    }

    public function test_user_can_delete_their_entry(): void
    {
        $user = User::factory()->create();
        $schedule = Schedule::factory()->for($user)->create();

        $this->actingAs($user)->delete(route('schedules.destroy', $schedule))
            ->assertRedirect(route('schedules.week', ['date' => $schedule->start_datetime->format('Y-m-d')]));

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

    public function test_creating_a_conflicting_entry_requires_confirmation(): void
    {
        $user = User::factory()->create();
        Schedule::factory()->for($user)->create([
            'start_datetime' => now()->addDay()->setTime(14, 30),
            'end_datetime' => now()->addDay()->setTime(15, 0),
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);

        $this->actingAs($user)->post('/schedules', $this->validData())
            ->assertRedirect()
            ->assertSessionHas('conflicts');

        $this->assertSame(1, $user->schedules()->count());
    }

    public function test_confirming_a_conflicting_entry_creates_it_anyway(): void
    {
        $user = User::factory()->create();
        Schedule::factory()->for($user)->create([
            'start_datetime' => now()->addDay()->setTime(14, 30),
            'end_datetime' => now()->addDay()->setTime(15, 0),
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);

        $this->actingAs($user)->post('/schedules', $this->validData(['confirmed' => '1']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Entry created.');

        $this->assertSame(2, $user->schedules()->count());
        $notification = $user->notifications()->sole();
        $this->assertSame('conflict_alert', $notification->type);
        $this->assertSame($user->schedules()->latest('id')->first()->id, $notification->schedule_id);
        $this->assertStringContainsString('Overlaps with:', $notification->message);
    }

    public function test_updating_to_a_conflicting_time_requires_confirmation(): void
    {
        $user = User::factory()->create();
        $schedule = Schedule::factory()->for($user)->create(['title' => 'Mine']);
        Schedule::factory()->for($user)->create([
            'start_datetime' => now()->addDay()->setTime(14, 30),
            'end_datetime' => now()->addDay()->setTime(15, 0),
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);

        $this->actingAs($user)->put(route('schedules.update', $schedule), $this->validData())
            ->assertRedirect()
            ->assertSessionHas('conflicts');

        $this->assertNotSame('Study Session', $schedule->fresh()->title);
    }

    public function test_confirming_a_conflicting_update_creates_a_linked_notification(): void
    {
        $user = User::factory()->create();
        $schedule = Schedule::factory()->for($user)->create(['title' => 'My task']);
        Schedule::factory()->for($user)->create([
            'start_datetime' => now()->addDay()->setTime(14, 30),
            'end_datetime' => now()->addDay()->setTime(15, 0),
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);

        $this->actingAs($user)->put(route('schedules.update', $schedule), $this->validData(['confirmed' => '1']))
            ->assertSessionHas('status', 'Entry updated.');

        $notification = $user->notifications()->sole();
        $this->assertSame($schedule->id, $notification->schedule_id);
        $this->assertSame('conflict_alert', $notification->type);
    }
}
