<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Notification;
use App\Models\Recommendation;
use App\Models\Schedule;
use App\Models\User;
use App\Support\ScheduleFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecommendationManagementTest extends TestCase
{
    use RefreshDatabase;

    private function fakePrediction(array $slots = [
        ['hour' => 7, 'confidence' => 0.91],
        ['hour' => 12, 'confidence' => 0.74],
        ['hour' => 18, 'confidence' => 0.62],
    ]): void
    {
        Http::fake([
            '*/predict' => Http::response([
                'recommendations' => $slots,
            ]),
        ]);
    }

    private function riskAlert(User $user, Schedule $schedule, Carbon $occurrenceStart): Notification
    {
        $assessment = $schedule->riskAssessments()->create([
            'occurrence_start' => $occurrenceStart,
            'probability' => 0.8,
            'model_version' => 'evaluated-test-model',
            'assessed_at' => now(),
            'schedule_updated_at' => $schedule->updated_at,
        ]);

        return $user->notifications()->create([
            'schedule_id' => $schedule->id,
            'risk_assessment_id' => $assessment->id,
            'message' => 'Commitment may be at risk.',
            'type' => 'system_message',
            'is_read' => false,
        ]);
    }

    public function test_requesting_a_recommendation_stores_three_rows_linked_to_activity_and_user(): void
    {
        $this->fakePrediction();

        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('recommendations.store', $activity));

        $response->assertRedirect(route('recommendations.index'));
        $this->assertDatabaseCount('recommendations', 3);
        $this->assertSame(
            3,
            Recommendation::where('user_id', $user->id)->where('activity_id', $activity->id)->count(),
        );
    }

    public function test_service_down_is_handled_without_a_server_error(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Could not connect to the slot recommender.');
        });

        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('recommendations.store', $activity));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_accepting_a_recommendation_creates_a_schedule_and_updates_status(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();
        $recommendation = Recommendation::factory()
            ->for($user)
            ->create(['activity_id' => $activity->id, 'category' => $activity->category]);

        $response = $this->actingAs($user)->post(route('recommendations.accept', $recommendation));

        $response->assertRedirect(route('recommendations.index'));
        $this->assertDatabaseHas('schedules', [
            'user_id' => $user->id,
            'start_datetime' => $recommendation->suggested_start,
            'end_datetime' => $recommendation->suggested_end,
        ]);
        $this->assertSame('accepted', $recommendation->fresh()->status);
    }

    public function test_dismissing_a_recommendation_updates_status_only(): void
    {
        $user = User::factory()->create();
        $recommendation = Recommendation::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('recommendations.dismiss', $recommendation));

        $response->assertRedirect(route('recommendations.index'));
        $this->assertSame('dismissed', $recommendation->fresh()->status);
        $this->assertDatabaseCount('schedules', 0);
    }

    public function test_a_user_cannot_accept_another_users_recommendation(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $recommendation = Recommendation::factory()->for($owner)->create();

        $response = $this->actingAs($intruder)->post(route('recommendations.accept', $recommendation));

        $response->assertForbidden();
        $this->assertSame('pending', $recommendation->fresh()->status);
    }

    public function test_a_user_cannot_dismiss_another_users_recommendation(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $recommendation = Recommendation::factory()->for($owner)->create();

        $response = $this->actingAs($intruder)->post(route('recommendations.dismiss', $recommendation));

        $response->assertForbidden();
        $this->assertSame('pending', $recommendation->fresh()->status);
    }

    public function test_risk_alert_requests_reuse_ranker_and_store_only_conflict_free_future_alternatives(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 12:00:00'));
        $this->fakePrediction([
            ['hour' => 13, 'confidence' => 0.95, 'suitable' => true],
            ['hour' => 14, 'confidence' => 0.9, 'suitable' => true],
            ['hour' => 15, 'confidence' => 0.8, 'suitable' => false],
        ]);
        $user = User::factory()->create();
        $target = Carbon::parse('2026-11-12 13:00');
        $schedule = Schedule::factory()->for($user)->create([
            'category' => 'work',
            'start_datetime' => $target,
            'end_datetime' => $target->copy()->addHour(),
        ]);
        $alert = $this->riskAlert($user, $schedule, $target);
        Schedule::factory()->for($user)->create([
            'start_datetime' => Carbon::parse('2026-11-12 15:00'),
            'end_datetime' => Carbon::parse('2026-11-12 16:00'),
        ]);

        $response = $this->actingAs($user)->post(route('notifications.alternatives', $alert));

        $response->assertRedirect(route('recommendations.index'));
        $response->assertSessionHas('status');
        $this->assertDatabaseCount('recommendations', 1);
        $recommendation = Recommendation::sole();
        $this->assertSame($schedule->id, $recommendation->source_schedule_id);
        $this->assertSame($schedule->id, $recommendation->sourceSchedule->id);
        $this->assertSame(14, $recommendation->suggested_start->hour);
        $this->assertSame($target->toDateTimeString(), $schedule->fresh()->start_datetime->toDateTimeString());
        Http::assertSent(fn ($request) => $request['category'] === 'work'
            && $request['duration_min'] === 60
            && $request['day_of_week'] === 3);
    }

    public function test_a_user_cannot_request_alternatives_from_another_users_risk_alert(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $target = now()->addHour()->startOfHour();
        $schedule = Schedule::factory()->for($owner)->create([
            'start_datetime' => $target,
            'end_datetime' => $target->copy()->addHour(),
        ]);
        $alert = $this->riskAlert($owner, $schedule, $target);
        Http::fake();

        $this->actingAs($intruder)->post(route('notifications.alternatives', $alert))->assertNotFound();

        $this->assertDatabaseCount('recommendations', 0);
        Http::assertNothingSent();
    }

    public function test_confirming_a_schedule_alternative_rechecks_conflicts_and_leaves_original_unchanged(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 12:00:00'));
        $user = User::factory()->create();
        $target = Carbon::parse('2026-11-12 13:00');
        $schedule = Schedule::factory()->for($user)->create([
            'start_datetime' => $target,
            'end_datetime' => $target->copy()->addHour(),
        ]);
        $recommendation = Recommendation::factory()->for($user)->create([
            'source_schedule_id' => $schedule->id,
            'source_occurrence_start' => $target,
            'source_schedule_updated_at' => $schedule->updated_at,
            'source_schedule_fingerprint' => ScheduleFingerprint::for($schedule),
            'activity_id' => null,
            'is_suitable' => true,
            'suggested_start' => Carbon::parse('2026-11-12 14:00'),
            'suggested_end' => Carbon::parse('2026-11-12 15:00'),
        ]);
        Schedule::factory()->for($user)->create([
            'start_datetime' => Carbon::parse('2026-11-12 14:00'),
            'end_datetime' => Carbon::parse('2026-11-12 15:00'),
        ]);

        $this->actingAs($user)->post(route('recommendations.accept', $recommendation))
            ->assertRedirect(route('recommendations.index'))
            ->assertSessionHas('alternative_conflicts');

        $this->assertSame($target->toDateTimeString(), $schedule->fresh()->start_datetime->toDateTimeString());
        $this->assertSame('pending', $recommendation->fresh()->status);
        $this->assertDatabaseCount('schedules', 2);
    }

    public function test_confirming_a_schedule_alternative_moves_the_original_entry_only_after_explicit_acceptance(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 12:00:00'));
        $user = User::factory()->create();
        $target = Carbon::parse('2026-11-12 13:00');
        $schedule = Schedule::factory()->for($user)->create([
            'start_datetime' => $target,
            'end_datetime' => $target->copy()->addHour(),
        ]);
        $recommendation = Recommendation::factory()->for($user)->create([
            'source_schedule_id' => $schedule->id,
            'source_occurrence_start' => $target,
            'source_schedule_updated_at' => $schedule->updated_at,
            'source_schedule_fingerprint' => ScheduleFingerprint::for($schedule),
            'activity_id' => null,
            'is_suitable' => true,
            'suggested_start' => Carbon::parse('2026-11-12 14:00'),
            'suggested_end' => Carbon::parse('2026-11-12 15:00'),
        ]);

        $this->actingAs($user)->get(route('recommendations.index'))
            ->assertOk()
            ->assertSee('Alternative time for this commitment.')
            ->assertSee('confidence')
            ->assertSee('Ranked as suitable by the model.')
            ->assertSee('Move to this time');
        $this->assertSame($target->toDateTimeString(), $schedule->fresh()->start_datetime->toDateTimeString());
        $this->actingAs($user)->post(route('recommendations.accept', $recommendation))
            ->assertRedirect(route('recommendations.index'))
            ->assertSessionHas('status', 'Your commitment was moved to the selected time.');

        $this->assertSame('2026-11-12 14:00:00', $schedule->fresh()->start_datetime->toDateTimeString());
        $this->assertSame('accepted', $recommendation->fresh()->status);
        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_alternative_is_rejected_when_source_schedule_changed_after_generation(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 12:00:00'));
        $user = User::factory()->create();
        $target = Carbon::parse('2026-11-12 13:00');
        $schedule = Schedule::factory()->for($user)->create([
            'start_datetime' => $target,
            'end_datetime' => $target->copy()->addHour(),
        ]);
        $recommendation = Recommendation::factory()->for($user)->create([
            'source_schedule_id' => $schedule->id,
            'source_occurrence_start' => $target,
            'source_schedule_updated_at' => $schedule->updated_at,
            'source_schedule_fingerprint' => ScheduleFingerprint::for($schedule),
            'activity_id' => null,
            'suggested_start' => Carbon::parse('2026-11-12 14:00'),
            'suggested_end' => Carbon::parse('2026-11-12 15:00'),
        ]);
        $changedStart = Carbon::parse('2026-11-12 13:30');
        $schedule->update([
            'start_datetime' => $changedStart,
            'end_datetime' => $changedStart->copy()->addHour(),
        ]);

        $this->actingAs($user)->post(route('recommendations.accept', $recommendation))
            ->assertRedirect(route('recommendations.index'))
            ->assertSessionHas('error');

        $this->assertSame($changedStart->toDateTimeString(), $schedule->fresh()->start_datetime->toDateTimeString());
        $this->assertSame('dismissed', $recommendation->fresh()->status);
    }

    public function test_dismissing_an_alternative_preserves_its_source_schedule(): void
    {
        $user = User::factory()->create();
        $schedule = Schedule::factory()->for($user)->create();
        $start = $schedule->start_datetime->copy();
        $recommendation = Recommendation::factory()->for($user)->create([
            'source_schedule_id' => $schedule->id,
            'source_occurrence_start' => $start,
            'source_schedule_updated_at' => $schedule->updated_at,
            'source_schedule_fingerprint' => ScheduleFingerprint::for($schedule),
            'activity_id' => null,
        ]);

        $this->actingAs($user)->post(route('recommendations.dismiss', $recommendation))
            ->assertRedirect(route('recommendations.index'));

        $this->assertSame($start->toDateTimeString(), $schedule->fresh()->start_datetime->toDateTimeString());
        $this->assertSame('dismissed', $recommendation->fresh()->status);
    }

    public function test_confirming_a_recurring_alternative_cancels_only_that_occurrence_and_adds_a_one_off_entry(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 12:00:00'));
        $user = User::factory()->create();
        $seriesStart = Carbon::parse('2026-11-05 13:00');
        $occurrenceStart = Carbon::parse('2026-11-12 13:00');
        $schedule = Schedule::factory()->for($user)->create([
            'start_datetime' => $seriesStart,
            'end_datetime' => $seriesStart->copy()->addHour(),
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
        ]);
        $recommendation = Recommendation::factory()->for($user)->create([
            'source_schedule_id' => $schedule->id,
            'source_occurrence_start' => $occurrenceStart,
            'source_schedule_updated_at' => $schedule->updated_at,
            'source_schedule_fingerprint' => ScheduleFingerprint::for($schedule),
            'activity_id' => null,
            'suggested_start' => Carbon::parse('2026-11-12 14:00'),
            'suggested_end' => Carbon::parse('2026-11-12 15:00'),
        ]);

        $this->actingAs($user)->post(route('recommendations.accept', $recommendation))
            ->assertRedirect(route('recommendations.index'))
            ->assertSessionHas('status', 'Your commitment was moved to the selected time.');

        $this->assertSame($seriesStart->toDateTimeString(), $schedule->fresh()->start_datetime->toDateTimeString());
        $this->assertSame('cancelled', $schedule->occurrenceOutcomes()->where('occurrence_start', $occurrenceStart)->value('outcome'));
        $replacement = $user->schedules()->where('is_recurring', false)->sole();
        $this->assertSame('2026-11-12 14:00:00', $replacement->start_datetime->toDateTimeString());
        $this->assertSame('accepted', $recommendation->fresh()->status);
    }

    public function test_accepting_a_conflicting_recommendation_requires_confirmation(): void
    {
        $user = User::factory()->create();
        $start = now()->addDay()->setTime(10, 0);
        Schedule::factory()->for($user)->create([
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addHour(),
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);
        $recommendation = Recommendation::factory()->for($user)->create([
            'suggested_start' => $start,
            'suggested_end' => $start->copy()->addHour(),
        ]);

        $response = $this->actingAs($user)->post(route('recommendations.accept', $recommendation));

        $response->assertRedirect(route('recommendations.index'));
        $response->assertSessionHas('conflicts');
        $this->assertDatabaseCount('schedules', 1);
        $this->assertSame('pending', $recommendation->fresh()->status);
    }

    public function test_confirming_a_conflicting_recommendation_accepts_it_anyway(): void
    {
        $user = User::factory()->create();
        $start = now()->addDay()->setTime(10, 0);
        Schedule::factory()->for($user)->create([
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addHour(),
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);
        $recommendation = Recommendation::factory()->for($user)->create([
            'suggested_start' => $start,
            'suggested_end' => $start->copy()->addHour(),
        ]);

        $response = $this->actingAs($user)->post(route('recommendations.accept', $recommendation), ['confirmed' => '1']);

        $response->assertRedirect(route('recommendations.index'));
        $this->assertDatabaseCount('schedules', 2);
        $this->assertSame('accepted', $recommendation->fresh()->status);
        $notification = $user->notifications()->sole();
        $this->assertSame('conflict_alert', $notification->type);
        $this->assertSame($user->schedules()->latest('id')->first()->id, $notification->schedule_id);
    }
}
