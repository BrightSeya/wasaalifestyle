<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Schedule;
use App\Models\User;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RecommendationService
    {
        return app(RecommendationService::class);
    }

    public function test_sends_the_correct_payload_and_returns_recommendations(): void
    {
        Http::fake([
            '*/predict' => Http::response([
                'category' => 'exercise',
                'day_of_week' => 0,
                'recommendations' => [
                    ['hour' => 7, 'confidence' => 0.8, 'suitable' => true],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create(['category' => 'exercise', 'duration_minutes' => 45]);

        $result = $this->service()->recommend($user, $activity, Carbon::parse('2026-09-28')); // Monday

        $this->assertTrue($result['ok']);
        $this->assertSame([['hour' => 7, 'confidence' => 0.8, 'suitable' => true]], $result['recommendations']);

        Http::assertSent(function ($request) {
            return $request->url() === config('services.slot_recommender.url').'/predict'
                && $request['category'] === 'exercise'
                && $request['day_of_week'] === 0
                && $request['duration_min'] === 45
                && $request['gap_since_last_same_category_hrs'] === 24.0;
        });
    }

    public function test_day_of_week_matches_the_wasaa_monday_zero_convention(): void
    {
        Http::fake(['*/predict' => Http::response(['recommendations' => []])]);

        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        $this->service()->recommend($user, $activity, Carbon::parse('2026-10-04')); // Sunday

        Http::assertSent(fn ($request) => $request['day_of_week'] === 6);
    }

    public function test_schedule_density_counts_the_days_occurrences(): void
    {
        Http::fake(['*/predict' => Http::response(['recommendations' => []])]);

        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();
        $date = Carbon::parse('2026-09-28');

        Schedule::factory()->for($user)->create(['start_datetime' => $date->copy()->setHour(9), 'end_datetime' => $date->copy()->setHour(10)]);
        Schedule::factory()->for($user)->create(['start_datetime' => $date->copy()->setHour(14), 'end_datetime' => $date->copy()->setHour(15)]);
        Schedule::factory()->for($user)->create(['start_datetime' => $date->copy()->addDay()->setHour(9), 'end_datetime' => $date->copy()->addDay()->setHour(10)]);

        $this->service()->recommend($user, $activity, $date);

        Http::assertSent(fn ($request) => $request['schedule_density'] === 2);
    }

    public function test_gap_uses_the_last_completed_log_of_the_same_category(): void
    {
        Http::fake(['*/predict' => Http::response(['recommendations' => []])]);

        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create(['category' => 'exercise']);
        $date = Carbon::parse('2026-09-28 08:00');

        ActivityLog::factory()->for($activity)->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'scheduled_for' => $date->copy()->subHours(30),
        ]);

        $this->service()->recommend($user, $activity, $date);

        Http::assertSent(fn ($request) => $request['gap_since_last_same_category_hrs'] === 30.0);
    }

    public function test_missed_logs_and_other_categories_do_not_count_toward_the_gap(): void
    {
        Http::fake(['*/predict' => Http::response(['recommendations' => []])]);

        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create(['category' => 'exercise']);
        $otherCategory = Activity::factory()->for($user)->create(['category' => 'work']);
        $date = Carbon::parse('2026-09-28 08:00');

        ActivityLog::factory()->for($activity)->create([
            'user_id' => $user->id,
            'status' => 'missed',
            'scheduled_for' => $date->copy()->subHours(2),
        ]);
        ActivityLog::factory()->for($otherCategory)->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'scheduled_for' => $date->copy()->subHours(1),
        ]);

        $this->service()->recommend($user, $activity, $date);

        Http::assertSent(fn ($request) => $request['gap_since_last_same_category_hrs'] === 24.0);
    }

    public function test_connection_failure_is_reported_without_throwing(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        $result = $this->service()->recommend($user, $activity);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unavailable', $result['message']);
    }

    public function test_an_error_response_is_reported_with_its_message(): void
    {
        Http::fake([
            '*/predict' => Http::response(['error' => 'category must be one of: work, exercise, leisure, errand, social, rest'], 400),
        ]);

        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        $result = $this->service()->recommend($user, $activity);

        $this->assertFalse($result['ok']);
        $this->assertSame('category must be one of: work, exercise, leisure, errand, social, rest', $result['message']);
    }
}
