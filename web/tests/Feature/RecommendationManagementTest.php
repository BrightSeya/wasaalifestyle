<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecommendationManagementTest extends TestCase
{
    use RefreshDatabase;

    private function fakePrediction(): void
    {
        Http::fake([
            '*/predict' => Http::response([
                'recommendations' => [
                    ['hour' => 7, 'confidence' => 0.91],
                    ['hour' => 12, 'confidence' => 0.74],
                    ['hour' => 18, 'confidence' => 0.62],
                ],
            ]),
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
}
