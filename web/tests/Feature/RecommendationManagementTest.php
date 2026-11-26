<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Recommendation;
use App\Models\Schedule;
use App\Models\User;
use App\Support\UserPreferences;
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

    private function scheduleFingerprint(Schedule $schedule): string
    {
        return hash('sha256', json_encode([
            $schedule->id,
            $schedule->title,
            $schedule->category->value,
            $schedule->start_datetime->format('Y-m-d H:i:s'),
            $schedule->end_datetime->format('Y-m-d H:i:s'),
            $schedule->is_recurring,
            $schedule->recurrence_rule,
            $schedule->recurrence_end_date?->toDateString(),
            $schedule->recurrence_count,
            $schedule->updated_at?->format('Y-m-d H:i:s.u'),
        ], JSON_THROW_ON_ERROR));
    }

    public function test_requesting_recommendations_stores_feasible_slots_linked_to_activity_and_user(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 05:00:00', 'UTC'));
        $this->fakePrediction();

        $availability = array_fill_keys(UserPreferences::days(), []);
        $availability['wed'] = [
            ['start' => '07:00', 'end' => '08:00'],
            ['start' => '12:00', 'end' => '12:30'],
        ];
        $preferences = UserPreferences::default()->toArray();
        $preferences['availability'] = $availability;
        $user = User::factory()->create(['preferences' => $preferences]);
        $activity = Activity::factory()->for($user)->create(['duration_minutes' => 30]);

        $response = $this->actingAs($user)->post(route('recommendations.store', $activity));

        $response->assertRedirect(route('recommendations.index'));
        $recommendations = Recommendation::where('user_id', $user->id)
            ->where('activity_id', $activity->id)
            ->get()
            ->values();
        $this->assertCount(3, $recommendations);
        $this->assertTrue($recommendations->every(
            fn (Recommendation $recommendation) => $recommendation->user_id === $user->id
                && $recommendation->activity_id === $activity->id,
        ));
        $this->assertSame(
            ['07:00', '07:30', '12:00'],
            $recommendations->map(fn (Recommendation $recommendation) => $recommendation->suggested_start->format('H:i'))
                ->sort()->values()->all(),
        );
    }

    public function test_activity_plan_preview_returns_distinct_days_with_time_options(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $this->fakePrediction([
            ['hour' => 8, 'confidence' => 0.95],
            ['hour' => 12, 'confidence' => 0.82],
            ['hour' => 15, 'confidence' => 0.70],
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('activities.weekly-plan.preview'), [
            'mode' => 'plan',
            'category' => 'exercise',
            'duration_minutes' => 30,
            'frequency' => 3,
        ]);

        $response->assertOk()
            ->assertJsonCount(3, 'days')
            ->assertJsonStructure(['session_count', 'days' => [['date', 'weekday', 'weekday_label', 'options' => [['start_time', 'start_time_label', 'end_time_label', 'confidence']]]]]);
        $days = collect($response->json('days'));
        $this->assertCount(3, $days->pluck('weekday')->unique());
        $this->assertTrue($days->every(fn (array $day) => count($day['options']) <= 3 && $day['options'] !== []));
    }

    public function test_day_plan_preview_excludes_times_already_shown_when_loading_more_options(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $this->fakePrediction([
            ['hour' => 8, 'confidence' => 0.95],
            ['hour' => 9, 'confidence' => 0.82],
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('activities.weekly-plan.preview'), [
            'mode' => 'day',
            'category' => 'exercise',
            'duration_minutes' => 30,
            'frequency' => 3,
            'weekday' => 1,
            'exclude_times' => ['08:00'],
        ]);

        $response->assertOk();
        $this->assertNotContains('08:00', collect($response->json('options'))->pluck('start_time')->all());
        $this->assertContains('08:30', collect($response->json('options'))->pluck('start_time')->all());
    }

    public function test_edit_plan_preview_ignores_the_activitys_own_existing_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $this->fakePrediction([
            ['hour' => 8, 'confidence' => 0.95],
            ['hour' => 9, 'confidence' => 0.82],
        ]);
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create(['category' => 'exercise', 'frequency' => 1]);
        $user->schedules()->create([
            'activity_id' => $activity->id,
            'title' => $activity->name,
            'category' => $activity->category,
            'start_datetime' => Carbon::parse('2026-11-23 08:00:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-11-23 09:00:00', 'UTC'),
            'priority' => 'medium',
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
            'recurrence_count' => 53,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->postJson(route('activities.weekly-plan.preview'), [
            'mode' => 'day',
            'category' => 'exercise',
            'duration_minutes' => 30,
            'frequency' => 1,
            'weekday' => 1,
            'activity_id' => $activity->id,
        ]);

        $response->assertOk();
        $this->assertContains('08:00', collect($response->json('options'))->pluck('start_time')->all());
    }

    public function test_edit_plan_preview_cannot_exclude_another_users_schedule(): void
    {
        $this->fakePrediction();
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $activity = Activity::factory()->for($owner)->create();

        $this->actingAs($otherUser)->postJson(route('activities.weekly-plan.preview'), [
            'mode' => 'day',
            'category' => 'exercise',
            'duration_minutes' => 30,
            'frequency' => 1,
            'weekday' => 1,
            'activity_id' => $activity->id,
        ])->assertNotFound();
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

            $response = $this->actingAs($user)->postJson(route('activities.weekly-plan.preview'), [
                'mode' => 'plan',
                'category' => 'exercise',
                'frequency' => $frequency,
                'duration_minutes' => 60,
            ]);
            $response->assertOk()->assertJsonPath('frequency', $frequency)->assertJsonPath('session_count', $frequency);

            $days = $response->json('days');
            $this->assertCount($frequency, $days);
            $this->assertCount($frequency, collect($days)->pluck('date')->unique());
            foreach ($days as $day) {
                $this->assertLessThanOrEqual(3, count($day['options']));
                $this->assertNotEmpty($day['options']);
                $this->assertArrayHasKey('confidence', $day['options'][0]);
            }
        }
    }

    public function test_weekly_plan_fills_unrecommended_sessions_at_the_preferred_time(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 05:00:00', 'UTC'));
        $this->fakePrediction([
            ['hour' => 8, 'confidence' => 0.95],
            ['hour' => 12, 'confidence' => 0.82],
        ]);
        $availability = array_fill_keys(UserPreferences::days(), []);
        $availability['mon'] = [['start' => '06:00', 'end' => '11:00']];
        $availability['wed'] = [['start' => '06:00', 'end' => '11:00']];
        $preferences = UserPreferences::default()->toArray();
        $preferences['availability'] = $availability;
        $user = User::factory()->create(['preferences' => $preferences]);

        $response = $this->actingAs($user)->postJson(route('activities.weekly-plan.preview'), [
            'mode' => 'plan',
            'category' => 'exercise',
            'duration_minutes' => 30,
            'frequency' => 4,
            'preferred_time_of_day' => '09:15',
        ]);

        $response->assertOk()
            ->assertJsonPath('frequency', 4)
            ->assertJsonPath('session_count', 4)
            ->assertJsonPath('recommended_session_count', 2)
            ->assertJsonPath('days.0.weekday', 1)
            ->assertJsonPath('days.0.options.0.start_time', '08:00')
            ->assertJsonPath('days.1.weekday', 2)
            ->assertJsonPath('days.1.recommendation_missing', true)
            ->assertJsonPath('days.1.start_time', '09:15')
            ->assertJsonPath('days.1.options', [])
            ->assertJsonPath('days.2.weekday', 3)
            ->assertJsonPath('days.2.options.0.start_time', '08:00')
            ->assertJsonPath('days.3.weekday', 4)
            ->assertJsonPath('days.3.recommendation_missing', true)
            ->assertJsonPath('days.3.start_time', '09:15');
        $response->assertJsonMissing(['message' => 'Some weekly sessions could not be recommended with your current availability and schedule.']);
        $this->assertSame(
            4,
            collect($response->json('days'))->pluck('weekday')->unique()->count(),
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

    public function test_recommendations_page_shows_empty_state_when_no_alternative_fits_availability(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 05:00:00', 'UTC'));
        Http::fake([
            '*/predict' => Http::response([
                'error' => 'No available time window is long enough for this activity on this day. Update availability or reduce the activity duration.',
            ], 422),
        ]);

        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();
        Schedule::factory()->for($user)->for($activity)->create([
            'title' => $activity->name,
            'category' => $activity->category,
            'start_datetime' => Carbon::parse('2026-11-25 08:00:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-11-25 09:00:00', 'UTC'),
        ]);

        $this->actingAs($user)->get(route('recommendations.index'))
            ->assertOk()
            ->assertSee('There are no upcoming scheduled tasks or lifestyle activities with a suitable alternative time right now.')
            ->assertDontSee('No available time window is long enough for this activity on this day.');
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
        $this->assertSame(['2026-11-28'], collect($response->json('recommendations'))->pluck('date')->unique()->values()->all());
        $this->assertSame(0, Recommendation::count(), 'Unaccepted preview times must not be stored.');
        Http::assertSent(fn ($request) => $request['day_of_week'] === 5
            && $request['duration_min'] === 60
            && $request['available_hours'] === [12, 13, 14, 15, 16]);
    }

    public function test_daily_task_recommendations_are_checked_across_the_requested_repeat_period(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 08:00:00'));
        Http::fake([
            '*/predict' => Http::response(['recommendations' => [
                ['hour' => 12, 'confidence' => 0.91, 'suitable' => true],
            ]]),
        ]);
        $user = User::factory()->create();
        $conflictStart = Carbon::parse('2026-11-29 12:30:00');
        Schedule::factory()->for($user)->create([
            'start_datetime' => $conflictStart,
            'end_datetime' => $conflictStart->copy()->addMinutes(15),
            'is_recurring' => false,
            'recurrence_rule' => null,
        ]);

        $response = $this->actingAs($user)->postJson(route('recommendations.preview-task'), [
            'category' => 'exercise',
            'date' => '2026-11-28',
            'start_time' => '12:00',
            'end_time' => '13:00',
            'recurrence' => 'daily',
            'recurrence_ends' => 'after',
            'recurrence_count' => 3,
        ]);

        $response->assertOk();
        $this->assertNotContains('2026-11-28', collect($response->json('recommendations'))->pluck('date')->all());
        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_recurring_task_recommendation_requires_a_finite_end(): void
    {
        $user = User::factory()->create();
        $payload = [
            'category' => 'exercise',
            'date' => now()->addDay()->toDateString(),
            'start_time' => '12:00',
            'end_time' => '13:00',
            'recurrence' => 'daily',
        ];

        $this->actingAs($user)->postJson(route('recommendations.preview-task'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recurrence_ends');
        $this->actingAs($user)->postJson(route('recommendations.preview-task'), $payload + [
            'recurrence_ends' => 'never',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('recurrence_ends');
    }

    public function test_recurring_task_recommendations_cannot_exceed_one_year(): void
    {
        $user = User::factory()->create();
        $payload = [
            'category' => 'exercise',
            'date' => '2027-01-04',
            'start_time' => '12:00',
            'end_time' => '13:00',
        ];

        $this->actingAs($user)->postJson(route('recommendations.preview-task'), $payload + [
            'recurrence' => 'daily',
            'recurrence_ends' => 'on_date',
            'recurrence_until' => '2028-01-05',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('recurrence_until');
        $this->actingAs($user)->postJson(route('recommendations.preview-task'), $payload + [
            'recurrence' => 'daily',
            'recurrence_ends' => 'after',
            'recurrence_count' => 367,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('recurrence_count');
        $this->actingAs($user)->postJson(route('recommendations.preview-task'), $payload + [
            'recurrence' => 'weekly',
            'recurrence_weekday' => 1,
            'recurrence_ends' => 'after',
            'recurrence_count' => 54,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('recurrence_count');
    }

    public function test_activity_recommendations_stay_inside_availability_and_recheck_before_adding(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 05:00:00'));
        Http::fake([
            '*/predict' => Http::response(['recommendations' => [
                ['hour' => 7, 'confidence' => 0.99, 'suitable' => true],
                ['hour' => 15, 'confidence' => 0.90, 'suitable' => true],
                ['hour' => 17, 'confidence' => 0.80, 'suitable' => true],
            ]]),
        ]);
        $availability = array_fill_keys(UserPreferences::days(), []);
        $availability['wed'] = [['start' => '15:00', 'end' => '16:00']];
        $preferences = UserPreferences::default()->toArray();
        $preferences['availability'] = $availability;
        $user = User::factory()->create(['preferences' => $preferences]);
        $activity = Activity::factory()->for($user)->create(['duration_minutes' => 30]);

        $response = $this->actingAs($user)->postJson(route('recommendations.store', $activity))->assertOk();
        $slots = collect($response->json('recommendations'));
        $this->assertSame(['15:00', '15:30'], $slots->map(fn ($slot) => Carbon::parse($slot['start'])->format('H:i'))->all());
        $this->assertSame('Wednesday, Nov 25', $slots->first()['date_label']);
        $this->assertSame('3:00 PM', $slots->first()['start_time_label']);
        $this->assertSame('3:30 PM', $slots->last()['start_time_label']);
        Http::assertSent(fn ($request) => $request['available_hours'] === [15]);

        $storedPreferences = $user->fresh()->preferences;
        $storedPreferences['availability']['wed'] = [];
        $user->update(['preferences' => $storedPreferences]);

        $recommendationId = $slots->first()['id'];
        $this->actingAs($user)->postJson(route('recommendations.choose-activity-slot', $activity), [
            'recommendation_id' => $recommendationId,
        ])->assertStatus(409)->assertJsonPath('message', 'This suggestion no longer fits your saved availability. Get a fresh recommendation.');

        $this->assertDatabaseCount('schedules', 0);
        $this->assertDatabaseHas('recommendations', ['id' => $recommendationId, 'status' => 'pending']);
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

    public function test_activity_list_shows_the_saved_weekly_plan_and_edit_link(): void
    {
        $user = User::factory()->create();
        Activity::factory()->for($user)->create([
            'weekly_slots' => [
                ['weekday' => 1, 'start_time' => '07:30', 'confidence' => 0.9],
                ['weekday' => 4, 'start_time' => '18:00', 'confidence' => 0.8],
            ],
        ]);

        $this->actingAs($user)->get(route('activities.index'))
            ->assertOk()
            ->assertSee('Weekly plan')
            ->assertSee('Edit weekly plan')
            ->assertSee('Mon')
            ->assertSee('7:30 AM')
            ->assertSee('Thu')
            ->assertSee('6:00 PM')
            ->assertDontSee('Get recommendation');
    }

    public function test_schedule_task_form_offers_a_calendar_for_the_selected_day(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('schedules.create'))
            ->assertOk()
            ->assertSee('Looking for a time on a specific date?')
            ->assertSee('Open calendar')
            ->assertSee('Find times for this date')
            ->assertSee('Choose a date and Wasaa will find available times for that day.');
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

    public function test_recommendations_page_shows_four_named_before_and_after_schedule_changes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 08:00:00', 'UTC'));
        $user = User::factory()->create();

        for ($day = 1; $day <= 5; $day++) {
            $start = Carbon::parse('2026-11-'.($day + 24).' 10:00:00', 'UTC');
            $schedule = Schedule::factory()->for($user)->create([
                'title' => "Task {$day}",
                'start_datetime' => $start,
                'end_datetime' => $start->copy()->addHour(),
            ]);
            $schedule->refresh();
            $newStart = $start->copy()->setTime(13, 0);

            Recommendation::factory()->for($user)->create([
                'source_schedule_id' => $schedule->id,
                'source_occurrence_start' => $start,
                'source_schedule_fingerprint' => $this->scheduleFingerprint($schedule),
                'suggested_start' => $newStart,
                'suggested_end' => $newStart->copy()->addHour(),
            ]);
        }

        $response = $this->actingAs($user)->get(route('recommendations.index'));
        $response
            ->assertOk()
            ->assertSee('Task ')
            ->assertSee('Current time')
            ->assertSee('Recommended time')
            ->assertSee('Apply recommended change');
        $this->assertSame(4, substr_count($response->getContent(), 'Apply recommended change'));
    }

    public function test_rescheduling_a_recurring_activity_changes_only_one_occurrence(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 08:00:00', 'UTC'));
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();
        $start = Carbon::parse('2026-11-26 09:00:00', 'UTC');
        $schedule = Schedule::factory()->for($user)->for($activity)->create([
            'title' => $activity->name,
            'category' => $activity->category,
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addHour(),
            'is_recurring' => true,
            'recurrence_rule' => 'weekly',
            'recurrence_count' => 4,
        ]);
        $schedule->refresh();
        $newStart = $start->copy()->setTime(13, 0);
        $recommendation = Recommendation::factory()->for($user)->create([
            'activity_id' => $activity->id,
            'source_schedule_id' => $schedule->id,
            'source_occurrence_start' => $start,
            'source_schedule_fingerprint' => $this->scheduleFingerprint($schedule),
            'suggested_start' => $newStart,
            'suggested_end' => $newStart->copy()->addHour(),
        ]);

        $this->actingAs($user)->post(route('recommendations.accept', $recommendation))
            ->assertRedirect(route('recommendations.index'))
            ->assertSessionHas('status', 'Updated only the Thursday, Nov 26 occurrence of '.$activity->name.' from 9:00 AM to 1:00 PM. All other occurrences remain unchanged.');

        $this->assertSame('accepted', $recommendation->fresh()->status);
        $this->assertSame(2, $user->schedules()->count());
        $this->assertDatabaseHas('schedule_occurrence_outcomes', [
            'schedule_id' => $schedule->id,
            'occurrence_start' => $start,
            'outcome' => 'cancelled',
        ]);
        $replacement = $user->schedules()->whereKey($recommendation->fresh()->replacement_schedule_id)->firstOrFail();
        $this->assertSame($activity->id, $replacement->activity_id);
        $this->assertFalse($replacement->is_recurring);
        $this->assertTrue($replacement->start_datetime->equalTo($newStart));
    }

    public function test_rescheduling_a_one_off_task_updates_its_time_and_confirms_the_change(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 08:00:00', 'UTC'));
        $user = User::factory()->create();
        $start = Carbon::parse('2026-11-26 10:00:00', 'UTC');
        $schedule = Schedule::factory()->for($user)->create([
            'title' => 'Team meeting',
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addHour(),
        ]);
        $schedule->refresh();
        $newStart = $start->copy()->setTime(13, 30);
        $recommendation = Recommendation::factory()->for($user)->create([
            'source_schedule_id' => $schedule->id,
            'source_occurrence_start' => $start,
            'source_schedule_fingerprint' => $this->scheduleFingerprint($schedule),
            'suggested_start' => $newStart,
            'suggested_end' => $newStart->copy()->addHour(),
        ]);

        $this->actingAs($user)->post(route('recommendations.accept', $recommendation))
            ->assertRedirect(route('recommendations.index'))
            ->assertSessionHas('status', 'Updated Team meeting from Thu, Nov 26 10:00 AM to Thu, Nov 26 1:30 PM.');

        $this->assertSame(1, $user->schedules()->count());
        $this->assertTrue($schedule->fresh()->start_datetime->equalTo($newStart));
        $this->assertSame('accepted', $recommendation->fresh()->status);
    }

    public function test_logging_an_activity_uses_its_rescheduled_one_off_time_for_today(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 08:00:00', 'UTC'));
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create([
            'weekly_slots' => [
                ['weekday' => 3, 'start_time' => '07:30', 'confidence' => 0.9],
            ],
        ]);
        $start = Carbon::parse('2026-11-25 14:00:00', 'UTC');
        Schedule::factory()->for($user)->for($activity)->create([
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addHour(),
        ]);

        $this->actingAs($user)->post(route('activities.log', $activity), ['status' => 'completed'])
            ->assertRedirect(route('activities.index'));

        $this->assertDatabaseHas('activity_logs', [
            'activity_id' => $activity->id,
            'scheduled_for' => $start,
            'status' => 'completed',
        ]);
    }
}
