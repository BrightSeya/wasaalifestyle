<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\ScheduleOccurrenceOutcome;
use App\Models\User;
use App\Services\ConflictChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduleOccurrenceOutcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-12 12:00:00'));
    }

    private function entry(User $user, string $start, string $end, array $overrides = []): Schedule
    {
        return Schedule::factory()->for($user)->create(array_merge([
            'start_datetime' => Carbon::parse($start),
            'end_datetime' => Carbon::parse($end),
        ], $overrides));
    }

    public function test_guest_cannot_record_an_occurrence_outcome(): void
    {
        $schedule = $this->entry(User::factory()->create(), '2026-11-11 10:00', '2026-11-11 11:00');

        $this->post(route('schedules.outcomes.store', $schedule), [
            'occurrence_start' => '2026-11-11 10:00:00',
            'outcome' => 'completed',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('schedule_occurrence_outcomes', 0);
    }

    public function test_owner_can_record_each_supported_outcome_for_a_past_occurrence(): void
    {
        $user = User::factory()->create();
        $schedule = $this->entry($user, '2026-11-11 10:00', '2026-11-11 11:00');

        foreach (['completed', 'missed', 'skipped'] as $outcome) {
            $this->actingAs($user)->post(route('schedules.outcomes.store', $schedule), [
                'occurrence_start' => '2026-11-11 10:00:00',
                'outcome' => $outcome,
            ])->assertRedirect(route('schedules.week', ['date' => '2026-11-11']))
                ->assertSessionHas('status', 'Schedule outcome recorded.');

            $this->assertDatabaseHas('schedule_occurrence_outcomes', [
                'schedule_id' => $schedule->id,
                'occurrence_start' => '2026-11-11 10:00:00',
                'occurrence_end' => '2026-11-11 11:00:00',
                'outcome' => $outcome,
            ]);
        }

        $this->assertSame(1, ScheduleOccurrenceOutcome::count());
    }

    public function test_future_occurrence_cannot_be_marked(): void
    {
        $user = User::factory()->create();
        $schedule = $this->entry($user, '2026-11-13 10:00', '2026-11-13 11:00');

        $this->actingAs($user)->from('/schedules/day')->post(route('schedules.outcomes.store', $schedule), [
            'occurrence_start' => '2026-11-13 10:00:00',
            'outcome' => 'missed',
        ])->assertRedirect('/schedules/day')
            ->assertSessionHasErrors('outcome');

        $this->assertDatabaseCount('schedule_occurrence_outcomes', 0);
    }

    public function test_owner_cannot_record_another_users_schedule_outcome(): void
    {
        $schedule = $this->entry(User::factory()->create(), '2026-11-11 10:00', '2026-11-11 11:00');

        $this->actingAs(User::factory()->create())->post(route('schedules.outcomes.store', $schedule), [
            'occurrence_start' => '2026-11-11 10:00:00',
            'outcome' => 'missed',
        ])->assertForbidden();

        $this->assertDatabaseCount('schedule_occurrence_outcomes', 0);
    }

    public function test_date_must_match_an_occurrence_of_the_schedule(): void
    {
        $user = User::factory()->create();
        $schedule = $this->entry($user, '2026-11-11 10:00', '2026-11-11 11:00');

        $this->actingAs($user)->from('/schedules/day')->post(route('schedules.outcomes.store', $schedule), [
            'occurrence_start' => '2026-11-10 10:00:00',
            'outcome' => 'completed',
        ])->assertRedirect('/schedules/day')
            ->assertSessionHasErrors('occurrence_start');

        $this->assertDatabaseCount('schedule_occurrence_outcomes', 0);
    }

    public function test_recurring_occurrences_have_separate_outcomes(): void
    {
        $user = User::factory()->create();
        $schedule = $this->entry($user, '2026-11-05 10:00', '2026-11-05 11:00', [
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
        ]);

        foreach ([['2026-11-05 10:00:00', 'completed'], ['2026-11-12 10:00:00', 'missed']] as [$start, $outcome]) {
            $this->actingAs($user)->post(route('schedules.outcomes.store', $schedule), [
                'occurrence_start' => $start,
                'outcome' => $outcome,
            ])->assertSessionHas('status', 'Schedule outcome recorded.');
        }

        $this->assertDatabaseCount('schedule_occurrence_outcomes', 2);
        $this->assertDatabaseHas('schedule_occurrence_outcomes', [
            'schedule_id' => $schedule->id,
            'occurrence_start' => '2026-11-05 10:00:00',
            'outcome' => 'completed',
        ]);
        $this->assertDatabaseHas('schedule_occurrence_outcomes', [
            'schedule_id' => $schedule->id,
            'occurrence_start' => '2026-11-12 10:00:00',
            'outcome' => 'missed',
        ]);
    }

    public function test_schedule_edit_does_not_overwrite_existing_occurrence_outcomes(): void
    {
        $user = User::factory()->create();
        $schedule = $this->entry($user, '2026-11-11 10:00', '2026-11-11 11:00', [
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
        ]);

        $this->actingAs($user)->post(route('schedules.outcomes.store', $schedule), [
            'occurrence_start' => '2026-11-11 10:00:00',
            'outcome' => 'completed',
        ]);

        $schedule->update([
            'start_datetime' => Carbon::parse('2026-11-12 09:00'),
            'end_datetime' => Carbon::parse('2026-11-12 10:00'),
        ]);

        $this->assertDatabaseHas('schedule_occurrence_outcomes', [
            'schedule_id' => $schedule->id,
            'occurrence_start' => '2026-11-11 10:00:00',
            'outcome' => 'completed',
        ]);
    }

    public function test_deleting_schedule_cascades_to_its_outcomes(): void
    {
        $user = User::factory()->create();
        $schedule = $this->entry($user, '2026-11-11 10:00', '2026-11-11 11:00');
        $schedule->occurrenceOutcomes()->create([
            'occurrence_start' => '2026-11-11 10:00:00',
            'outcome' => 'missed',
            'recorded_at' => now(),
        ]);

        $schedule->delete();

        $this->assertDatabaseCount('schedule_occurrence_outcomes', 0);
    }

    public function test_day_view_offers_outcome_capture_only_after_the_occurrence_ends(): void
    {
        $user = User::factory()->create();
        $this->entry($user, '2026-11-11 10:00', '2026-11-11 11:00', ['title' => 'Past meeting']);
        $this->entry($user, '2026-11-13 10:00', '2026-11-13 11:00', ['title' => 'Future meeting']);

        $this->actingAs($user)->get('/schedules/day?date=2026-11-11')
            ->assertOk()
            ->assertSee('Past meeting')
            ->assertSee('Record outcome')
            ->assertDontSee('Future meeting');

        $this->actingAs($user)->get('/schedules/day?date=2026-11-13')
            ->assertOk()
            ->assertSee('Future meeting')
            ->assertDontSee('Record outcome');
    }

    public function test_future_occurrence_can_be_cancelled_and_is_distinct_from_other_outcomes(): void
    {
        $user = User::factory()->create();
        $schedule = $this->entry($user, '2026-11-13 10:00', '2026-11-13 11:00');

        $this->actingAs($user)->post(route('schedules.outcomes.store', $schedule), [
            'occurrence_start' => '2026-11-13 10:00:00',
            'outcome' => 'cancelled',
        ])->assertRedirect(route('schedules.week', ['date' => '2026-11-13']));

        $this->assertDatabaseHas('schedule_occurrence_outcomes', [
            'schedule_id' => $schedule->id,
            'occurrence_start' => '2026-11-13 10:00:00',
            'outcome' => 'cancelled',
        ]);
        $this->assertSame(0, app(ConflictChecker::class)->check(
            $user,
            Carbon::parse('2026-11-13 10:00'),
            Carbon::parse('2026-11-13 11:00'),
            'medium',
        )->count());

        $this->actingAs($user)->get('/schedules/day?date=2026-11-13')
            ->assertOk()
            ->assertSee('Cancelled');
    }
}
