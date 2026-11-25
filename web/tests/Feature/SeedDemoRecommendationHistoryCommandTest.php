<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserRecommendationFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SeedDemoRecommendationHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_balanced_recent_synthetic_history_without_changing_user_preferences_or_consent(): void
    {
        $user = User::factory()->create([
            'preferences' => ['personalize_recommendations' => false],
            'recommendation_personalization_consent_at' => null,
        ]);

        $exitCode = Artisan::call('demo:seed-recommendation-history', ['email' => $user->email]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, $user->activities()->count());
        $this->assertSame(160, $user->activityLogs()->count());
        $this->assertSame(80, $user->activityLogs()->where('status', 'completed')->count());
        $this->assertSame(80, $user->activityLogs()->where('status', 'missed')->count());
        $this->assertSame(['personalize_recommendations' => false], $user->fresh()->preferences);
        $this->assertNull($user->fresh()->recommendation_personalization_consent_at);

        $history = app(UserRecommendationFeedback::class)
            ->recommendationData($user->fresh(), 'exercise', now())['personal_history'];
        $this->assertCount(160, $history);
        $this->assertSame(80, count(array_filter($history, fn (array $outcome): bool => $outcome['label'] === 1)));
        $this->assertSame(80, count(array_filter($history, fn (array $outcome): bool => $outcome['label'] === 0)));
    }

    public function test_it_refuses_to_add_synthetic_history_to_an_account_with_existing_data(): void
    {
        $user = User::factory()->create();
        $user->activities()->create([
            'name' => 'Existing routine',
            'category' => 'exercise',
            'preferred_time_of_day' => '08:00:00',
            'frequency' => 2,
            'duration_minutes' => 30,
        ]);

        $exitCode = Artisan::call('demo:seed-recommendation-history', ['email' => $user->email]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $user->activityLogs()->count());
        $this->assertSame(1, $user->activities()->count());
    }

    public function test_it_refuses_an_unknown_account(): void
    {
        $exitCode = Artisan::call('demo:seed-recommendation-history', ['email' => 'missing@example.test']);

        $this->assertSame(1, $exitCode);
    }

    public function test_it_requires_explicit_confirmation_in_production(): void
    {
        $user = User::factory()->create();
        $this->app->detectEnvironment(fn () => 'production');

        $exitCode = Artisan::call('demo:seed-recommendation-history', ['email' => $user->email]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $user->activities()->count());
    }
}
