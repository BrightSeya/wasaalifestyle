<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Recommendation;
use App\Models\Schedule;
use App\Models\User;
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

    public function test_requesting_a_recommendation_stores_three_rows_linked_to_activity_and_user(): void
    {
        $this->fakePrediction();

        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create(['frequency' => 3]);

        $response = $this->actingAs($user)->post(route('recommendations.store', $activity));

        $response->assertRedirect(route('recommendations.index'));
        $this->assertDatabaseCount('recommendations', 3);
        $this->assertSame(
            3,
            Recommendation::where('user_id', $user->id)->where('activity_id', $activity->id)->count(),
        );
        $suggestedDates = Recommendation::where('user_id', $user->id)
            ->where('activity_id', $activity->id)
            ->get()
            ->map(fn (Recommendation $recommendation) => $recommendation->suggested_start->toDateString())
            ->unique();
        $this->assertCount(3, $suggestedDates, 'The weekly plan should use three different days.');
    }

    public function test_weekly_plan_uses_one_session_for_a_once_per_week_activity(): void
    {
        $this->fakePrediction();
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create(['frequency' => 1]);

        $this->actingAs($user)->post(route('recommendations.store', $activity))
            ->assertRedirect(route('recommendations.index'));

        $this->assertDatabaseCount('recommendations', 1);
    }

    public function test_weekly_plan_covers_activity_frequency_with_multiple_time_options_per_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00'));
        Http::fake(function ($request) {
            $hours = array_slice($request['available_hours'], 0, 3);

            return Http::response([
                'recommendations' => collect($hours)->values()->map(fn ($hour, $index) => [
                    'hour' => $hour,
                    'confidence' => 0.9 - ($index * 0.1),
                    'suitable' => true,
                ])->all(),
            ]);
        });

        foreach ([6, 7] as $frequency) {
            $user = User::factory()->create();
            $activity = Activity::factory()->for($user)->create(['frequency' => $frequency, 'duration_minutes' => 60]);

            $response = $this->actingAs($user)->postJson(route('recommendations.store', $activity));
            $response->assertOk()->assertJsonPath('frequency', $frequency)->assertJsonPath('session_count', $frequency);

            $days = $response->json('days');
            $this->assertCount($frequency, $days);
            $this->assertCount($frequency, collect($days)->pluck('date')->unique());
            foreach ($days as $day) {
                $this->assertCount(3, $day['options']);
                $this->assertArrayHasKey('confidence', $day['options'][0]);
            }

            $dayOptions = $days[0]['options'];
            $this->actingAs($user)->postJson(route('recommendations.choose-activity-slot', $activity), [
                'recommendation_id' => $dayOptions[0]['id'],
            ])->assertOk();
            $this->assertDatabaseHas('recommendations', ['id' => $dayOptions[0]['id'], 'status' => 'accepted']);
            foreach (array_slice($dayOptions, 1) as $option) {
                $this->assertDatabaseHas('recommendations', ['id' => $option['id'], 'status' => 'dismissed']);
            }
        }
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

    public function test_no_suitable_availability_is_shown_on_the_recommendations_page(): void
    {
        Http::fake([
            '*/predict' => Http::response([
                'error' => 'No available time window is long enough for this activity on this day. Update availability or reduce the activity duration.',
            ], 422),
        ]);

        $user = User::factory()->create();
        Activity::factory()->for($user)->create();

        $this->actingAs($user)->get(route('recommendations.index'))
            ->assertOk()
            ->assertSee('No available time window is long enough for this activity on this day. Update availability or reduce the activity duration.');
    }

    public function test_schedule_task_recommendations_use_the_selected_weekday(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 08:00:00'));
        Http::fake([
            '*/predict' => Http::response([
                'recommendations' => [
                    ['hour' => 12, 'confidence' => 0.91, 'suitable' => true],
                    ['hour' => 13, 'confidence' => 0.82, 'suitable' => true],
                    ['hour' => 14, 'confidence' => 0.78, 'suitable' => true],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson(route('recommendations.preview-task'), [
            'category' => 'exercise',
            'date' => '2026-11-28', // Saturday
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);

        $response->assertOk()->assertJsonCount(3, 'recommendations');
        $savedDates = Recommendation::where('user_id', $user->id)->whereNull('activity_id')
            ->get()->map(fn (Recommendation $recommendation) => $recommendation->suggested_start->toDateString())->unique();
        $this->assertSame(['2026-11-28'], $savedDates->values()->all());
        Http::assertSent(fn ($request) => $request['day_of_week'] === 5
            && $request['duration_min'] === 60
            && $request['available_hours'] === [12, 13, 14, 15, 16]);
    }

    public function test_schedule_task_recommendations_reject_past_dates(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 08:00:00'));
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('recommendations.preview-task'), [
            'category' => 'exercise',
            'date' => '2026-11-23',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_activity_recommendation_dialog_explains_weekly_sessions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('activities.index'))
            ->assertOk()
            ->assertSee('Suggested weekly sessions')
            ->assertSee('based on this activity');
    }

    public function test_schedule_task_form_offers_a_calendar_for_the_selected_day(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('schedules.create'))
            ->assertOk()
            ->assertSee('Looking for a time on a specific date?')
            ->assertSee('Open calendar')
            ->assertSee('Find times for this date')
            ->assertSee('Choose the date in Task details, then get times that fit that specific day');
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
