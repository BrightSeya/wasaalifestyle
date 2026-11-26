<?php

namespace Tests\Feature;

use App\Enums\Category;
use App\Models\Activity;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActivityManagementTest extends TestCase
{
    use RefreshDatabase;

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Morning Run',
            'category' => 'exercise',
            'preferred_time_of_day' => '06:30',
            'frequency' => 5,
            'duration_minutes' => 45,
            'weekly_slots' => collect(range(1, 5))
                ->map(fn ($weekday) => ['weekday' => $weekday, 'start_time' => '06:30', 'confidence' => 0.8])
                ->all(),
        ], $overrides);
    }

    public function test_guests_cannot_access_activities(): void
    {
        $this->get('/activities')->assertRedirect('/login');
        $this->post('/activities', $this->validData())->assertRedirect('/login');
    }

    public function test_index_lists_only_the_users_activities(): void
    {
        $user = User::factory()->create();
        Activity::factory()->for($user)->create(['name' => 'My Activity']);
        Activity::factory()->create(['name' => 'Someone Else']);

        $this->actingAs($user)->get('/activities')
            ->assertOk()
            ->assertSee('My Activity')
            ->assertDontSee('Someone Else');
    }

    public function test_user_can_create_an_activity(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $user = User::factory()->create();

        $this->actingAs($user)->post('/activities', $this->validData())
            ->assertRedirect(route('activities.index'))
            ->assertSessionHas('status', 'Activity added.');

        $activity = $user->activities()->sole();
        $this->assertSame('Morning Run', $activity->name);
        $this->assertSame(Category::Exercise, $activity->category);
        $this->assertSame('06:30', substr($activity->preferred_time_of_day, 0, 5));
        $this->assertSame(5, $activity->frequency);
        $this->assertSame(45, $activity->duration_minutes);
        $this->assertSame(5, $activity->schedules()->count());
        $this->assertSame([1, 2, 3, 4, 5], $activity->schedules()->get()->map(
            fn (Schedule $schedule) => $schedule->start_datetime->copy()->setTimezone('UTC')->dayOfWeekIso,
        )->sort()->values()->all());
        $schedules = $activity->schedules()->get();
        $this->assertTrue($schedules->every(
            fn (Schedule $schedule) => $schedule->activity_id === $activity->id
                && $schedule->is_recurring
                && $schedule->recurrence_rule === 'weekly'
                && $schedule->recurrence_count <= 53,
        ));
        foreach ($schedules as $schedule) {
            $first = $schedule->start_datetime->copy()->setTimezone('UTC');
            $last = $first->copy()->addWeeks($schedule->recurrence_count - 1);
            $this->assertLessThanOrEqual($first->copy()->addYear()->getTimestamp(), $last->getTimestamp());
        }
    }

    public function test_once_per_week_activity_creates_one_recurring_session(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $user = User::factory()->create();

        $this->actingAs($user)->post('/activities', $this->validData([
            'frequency' => 1,
            'weekly_slots' => [
                ['weekday' => 1, 'start_time' => '06:30', 'confidence' => 0.8],
            ],
        ]))->assertRedirect(route('activities.index'));

        $activity = $user->activities()->sole();
        $schedule = $activity->schedules()->sole();
        $this->assertSame(1, $activity->frequency);
        $this->assertSame(1, count($activity->weekly_slots));
        $this->assertTrue($schedule->is_recurring);
        $this->assertSame('weekly', $schedule->recurrence_rule);
    }

    public function test_activity_cannot_be_created_for_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post('/activities', $this->validData(['user_id' => $other->id]));

        $this->assertSame(0, $other->activities()->count());
        $this->assertSame(1, $user->activities()->count());
    }

    public function test_validation_errors(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/activities', $this->validData(['category' => 'gaming']))
            ->assertSessionHasErrors('category');
        $this->actingAs($user)->post('/activities', $this->validData(['frequency' => 8]))
            ->assertSessionHasErrors('frequency');
        $this->actingAs($user)->post('/activities', $this->validData(['name' => '', 'duration_minutes' => 0]))
            ->assertSessionHasErrors(['name', 'duration_minutes']);

        $this->assertSame(0, Activity::count());
    }

    public function test_user_can_edit_and_update_their_activity(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        $this->actingAs($user)->get(route('activities.edit', $activity))->assertOk()->assertSee($activity->name);

        $this->actingAs($user)->put(route('activities.update', $activity), $this->validData(['name' => 'Updated']))
            ->assertRedirect(route('activities.index'));

        $this->assertSame('Updated', $activity->fresh()->name);
        $this->assertSame(5, $activity->schedules()->count());
    }

    public function test_updating_an_activity_replaces_its_active_weekly_schedule_series(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create([
            'frequency' => 1,
            'weekly_slots' => [['weekday' => 1, 'start_time' => '06:30', 'confidence' => 0.8]],
        ]);
        $oldSchedule = $user->schedules()->create([
            'activity_id' => $activity->id,
            'title' => $activity->name,
            'category' => $activity->category,
            'start_datetime' => Carbon::parse('2026-11-23 06:30:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-11-23 07:15:00', 'UTC'),
            'priority' => 'medium',
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
            'recurrence_count' => 53,
            'status' => 'pending',
        ]);

        $this->actingAs($user)->put(route('activities.update', $activity), $this->validData(['frequency' => 5]))
            ->assertRedirect(route('activities.index'));

        $this->assertSame('2026-11-22', $oldSchedule->fresh()->recurrence_end_date->toDateString());
        $this->assertSame(5, $activity->fresh()->schedules()->whereNull('recurrence_end_date')->count());
        $this->assertSame(6, $activity->fresh()->schedules()->count());
        $this->assertCount(5, $activity->fresh()->weekly_slots);
    }

    public function test_weekly_plan_requires_a_different_day_for_each_session(): void
    {
        $user = User::factory()->create();
        $slots = $this->validData()['weekly_slots'];
        $slots[1]['weekday'] = $slots[0]['weekday'];

        $this->actingAs($user)->post('/activities', $this->validData(['weekly_slots' => $slots]))
            ->assertSessionHasErrors('weekly_slots');

        $this->assertSame(0, Activity::count());
        $this->assertSame(0, Schedule::count());
    }

    public function test_a_conflicting_occurrence_requires_confirmation_and_is_saved_without_cancellation(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $user = User::factory()->create();
        $meeting = $user->schedules()->create([
            'title' => 'One-off meeting',
            'category' => 'work',
            'start_datetime' => Carbon::parse('2026-11-23 06:30:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-11-23 07:15:00', 'UTC'),
            'priority' => 'medium',
            'is_recurring' => false,
            'status' => 'pending',
        ]);

        $this->actingAs($user)->from(route('activities.create'))->post('/activities', $this->validData())
            ->assertRedirect(route('activities.create'))
            ->assertSessionHas('activity_conflicts.by_slot.0.0.message');

        $fingerprint = session('activity_conflicts.fingerprint');
        $this->assertSame(0, $user->activities()->count());
        $this->assertSame(1, $user->schedules()->count());

        $this->get(route('activities.create'))
            ->assertOk()
            ->assertSee('Some sessions overlap with your schedule.')
            ->assertSee('One-off meeting')
            ->assertSee('data-plan-conflict', false)
            ->assertSee('Confirm these overlapping times');

        $this->actingAs($user)->post('/activities', $this->validData([
            'confirmed' => '1',
            'activity_conflict_fingerprint' => $fingerprint,
        ]))->assertRedirect(route('activities.index'))
            ->assertSessionHas('status', 'Activity added. Overlapping weekly sessions were confirmed and saved.');

        $activity = $user->activities()->sole();
        $mondaySchedule = $activity->schedules()->get()->first(
            fn (Schedule $schedule) => $schedule->start_datetime->copy()->setTimezone('UTC')->dayOfWeekIso === 1,
        );
        $this->assertNotNull($mondaySchedule);
        $this->assertSame(0, $mondaySchedule->occurrenceOutcomes()->count());
        $this->assertSame(5, $activity->schedules()->count());
        $this->assertSame('One-off meeting', $meeting->fresh()->title);
    }

    public function test_rescheduling_a_conflicting_weekday_saves_without_overlap_confirmation(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $user = User::factory()->create();
        $user->schedules()->create([
            'title' => 'One-off meeting',
            'category' => 'work',
            'start_datetime' => Carbon::parse('2026-11-23 06:30:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-11-23 07:15:00', 'UTC'),
            'priority' => 'medium',
            'is_recurring' => false,
            'status' => 'pending',
        ]);
        $data = $this->validData();
        $data['weekly_slots'][0]['start_time'] = '08:00';

        $this->actingAs($user)->post('/activities', $data)
            ->assertRedirect(route('activities.index'))
            ->assertSessionHas('status', 'Activity added.');

        $weekdays = $user->activities()->sole()->schedules()->get()
            ->map(fn (Schedule $schedule) => $schedule->start_datetime->copy()->setTimezone('UTC')->dayOfWeekIso)
            ->sort()
            ->values()
            ->all();
        $this->assertSame([1, 2, 3, 4, 5], $weekdays);
    }

    public function test_repeating_conflicts_are_listed_and_can_be_confirmed(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $user = User::factory()->create();
        $user->schedules()->create([
            'title' => 'Weekly meeting',
            'category' => 'work',
            'start_datetime' => Carbon::parse('2026-11-23 06:30:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-11-23 07:15:00', 'UTC'),
            'priority' => 'medium',
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
            'recurrence_count' => 3,
            'status' => 'pending',
        ]);

        $this->actingAs($user)->from(route('activities.create'))->post('/activities', $this->validData())
            ->assertRedirect(route('activities.create'))
            ->assertSessionHas('activity_conflicts.by_slot.0.0.message');

        $fingerprint = session('activity_conflicts.fingerprint');
        $this->assertSame(0, $user->activities()->count());

        $this->actingAs($user)->post('/activities', $this->validData([
            'confirmed' => '1',
            'activity_conflict_fingerprint' => $fingerprint,
        ]))->assertRedirect(route('activities.index'))
            ->assertSessionHas('status', 'Activity added. Overlapping weekly sessions were confirmed and saved.');

        $this->assertSame(5, $user->activities()->sole()->schedules()->count());
        $this->assertSame(0, $user->activities()->sole()->schedules()
            ->withCount('occurrenceOutcomes')
            ->get()
            ->sum('occurrence_outcomes_count'));
    }

    public function test_updating_a_weekly_activity_does_not_replace_its_plan_before_conflict_confirmation(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create([
            'name' => 'Existing routine',
            'frequency' => 1,
            'weekly_slots' => [['weekday' => 1, 'start_time' => '06:30', 'confidence' => 0.8]],
        ]);
        $oldSchedule = $user->schedules()->create([
            'activity_id' => $activity->id,
            'title' => $activity->name,
            'category' => $activity->category,
            'start_datetime' => Carbon::parse('2026-11-23 06:30:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-11-23 07:15:00', 'UTC'),
            'priority' => 'medium',
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
            'recurrence_count' => 53,
            'status' => 'pending',
        ]);
        $user->schedules()->create([
            'title' => 'Weekly meeting',
            'category' => 'work',
            'start_datetime' => Carbon::parse('2026-11-24 06:30:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-11-24 07:15:00', 'UTC'),
            'priority' => 'medium',
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
            'recurrence_count' => 3,
            'status' => 'pending',
        ]);
        $data = $this->validData([
            'name' => 'Updated routine',
            'frequency' => 1,
            'weekly_slots' => [['weekday' => 2, 'start_time' => '06:30', 'confidence' => 0.8]],
        ]);

        $this->actingAs($user)->from(route('activities.edit', $activity))->put(route('activities.update', $activity), $data)
            ->assertRedirect(route('activities.edit', $activity))
            ->assertSessionHas('activity_conflicts.by_slot.0.0.message');
        $fingerprint = session('activity_conflicts.fingerprint');

        $this->assertSame('Existing routine', $activity->fresh()->name);
        $this->assertNull($oldSchedule->fresh()->recurrence_end_date);

        $this->actingAs($user)->put(route('activities.update', $activity), $data + [
            'confirmed' => '1',
            'activity_conflict_fingerprint' => $fingerprint,
        ])->assertRedirect(route('activities.index'))
            ->assertSessionHas('status', 'Activity updated. Overlapping weekly sessions were confirmed and saved.');

        $this->assertSame('Updated routine', $activity->fresh()->name);
        $this->assertSame('2026-11-22', $oldSchedule->fresh()->recurrence_end_date->toDateString());
        $this->assertSame(1, $activity->fresh()->schedules()->whereNull('recurrence_end_date')->count());
    }

    public function test_changed_conflicts_require_a_fresh_confirmation(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $user = User::factory()->create();
        $meeting = $user->schedules()->create([
            'title' => 'Weekly meeting',
            'category' => 'work',
            'start_datetime' => Carbon::parse('2026-11-23 06:30:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-11-23 07:15:00', 'UTC'),
            'priority' => 'medium',
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
            'recurrence_count' => 3,
            'status' => 'pending',
        ]);

        $this->actingAs($user)->from(route('activities.create'))->post('/activities', $this->validData())
            ->assertRedirect(route('activities.create'))
            ->assertSessionHas('activity_conflicts.by_slot.0.0.message');
        $staleFingerprint = session('activity_conflicts.fingerprint');

        $meeting->update(['title' => 'Updated weekly meeting']);

        $this->actingAs($user)->from(route('activities.create'))->post('/activities', $this->validData([
            'confirmed' => '1',
            'activity_conflict_fingerprint' => $staleFingerprint,
        ]))->assertRedirect(route('activities.create'))
            ->assertSessionHas('activity_conflicts.by_slot.0.0.message');

        $this->assertSame(0, $user->activities()->count());
    }

    public function test_linked_schedule_entries_must_be_managed_from_the_activity_editor(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();
        $schedule = $user->schedules()->create([
            'activity_id' => $activity->id,
            'title' => $activity->name,
            'category' => $activity->category,
            'start_datetime' => Carbon::parse('2026-11-24 06:30:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-11-24 07:15:00', 'UTC'),
            'priority' => 'medium',
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
            'recurrence_count' => 53,
            'status' => 'pending',
        ]);

        $this->actingAs($user)->get(route('schedules.edit', $schedule))
            ->assertRedirect(route('activities.edit', $activity));
        $this->actingAs($user)->put(route('schedules.update', $schedule), [
            'title' => 'Changed outside editor',
            'category' => 'work',
            'date' => '2026-11-24',
            'start_time' => '14:00',
            'end_time' => '15:00',
            'priority' => 'medium',
            'recurrence' => 'none',
        ])->assertRedirect(route('activities.edit', $activity));
        $this->actingAs($user)->delete(route('schedules.destroy', $schedule))
            ->assertRedirect(route('activities.edit', $activity));

        $this->assertSame($activity->name, $schedule->fresh()->title);
    }

    public function test_user_can_delete_their_activity(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        $this->actingAs($user)->delete(route('activities.destroy', $activity))
            ->assertRedirect(route('activities.index'));

        $this->assertModelMissing($activity);
    }

    public function test_user_cannot_touch_another_users_activity(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->create();

        $this->actingAs($user)->get(route('activities.edit', $activity))->assertForbidden();
        $this->actingAs($user)->put(route('activities.update', $activity), $this->validData())->assertForbidden();
        $this->actingAs($user)->delete(route('activities.destroy', $activity))->assertForbidden();

        $this->assertModelExists($activity);
    }
}
