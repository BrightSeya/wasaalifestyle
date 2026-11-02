<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlotRecommenderIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseUrl = rtrim(config('services.slot_recommender.url'), '/');

        try {
            $response = Http::timeout(2)->get($this->baseUrl.'/health');
        } catch (ConnectionException) {
            $this->markTestSkipped('Flask slot recommender is not reachable; start it locally to run these integration tests.');
        }

        if (! $response->successful()) {
            $this->markTestSkipped('Flask slot recommender health check did not succeed.');
        }
    }

    public function test_health_endpoint_reports_a_loaded_model(): void
    {
        $response = Http::timeout(5)->get($this->baseUrl.'/health');

        $this->assertSame(200, $response->status());
        $this->assertTrue($response->json('model_loaded'));
    }

    public function test_recommendation_service_gets_three_ranked_slots_from_flask(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create([
            'category' => 'exercise',
            'duration_minutes' => 45,
        ]);

        $result = app(RecommendationService::class)->recommend($user, $activity);

        $this->assertTrue($result['ok'], $result['message'] ?? 'Flask prediction failed.');
        $recommendations = $result['recommendations'];
        $this->assertCount(3, $recommendations);

        $hours = array_column($recommendations, 'hour');
        $confidences = array_column($recommendations, 'confidence');

        $this->assertCount(3, array_unique($hours));
        $this->assertSame($confidences, collect($confidences)->sortDesc()->values()->all());
        $this->assertTrue(collect($hours)->every(fn ($hour) => $hour >= 0 && $hour <= 23));
    }

    public function test_retrain_rejects_missing_and_wrong_keys(): void
    {
        $missingKeyResponse = Http::timeout(5)->post($this->baseUrl.'/retrain');
        $wrongKeyResponse = Http::timeout(5)
            ->withHeaders(['X-API-Key' => 'integration-test-invalid-key'])
            ->post($this->baseUrl.'/retrain');

        $this->assertSame(401, $missingKeyResponse->status());
        $this->assertSame(401, $wrongKeyResponse->status());
    }

    public function test_retrain_with_the_correct_key_reloads_the_model(): void
    {
        $key = env('ML_RETRAIN_KEY');

        if (! $key || env('RUN_ML_RETRAIN_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set ML_RETRAIN_KEY and RUN_ML_RETRAIN_INTEGRATION=1 to opt into the full training run.');
        }

        $response = Http::timeout(1800)
            ->withHeaders(['X-API-Key' => $key])
            ->post($this->baseUrl.'/retrain');

        $this->assertSame(200, $response->status(), $response->body());
        $this->assertIsNumeric($response->json('model_card.cv_f1'));
        $this->assertIsArray($response->json('model_card.best_params'));

        $health = Http::timeout(5)->get($this->baseUrl.'/health');
        $this->assertSame(200, $health->status());
        $this->assertTrue($health->json('model_loaded'));
    }
}
