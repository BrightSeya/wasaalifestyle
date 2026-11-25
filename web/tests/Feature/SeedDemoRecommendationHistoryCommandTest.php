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

    public function test_it_adds_varied_recent_history_without_changing_user_preferences_or_consent(): void
    {
        $user = User::factory()->create([
            'preferences' => ['personalize_recommendations' => false],
            'recommendation_personalization_consent_at' => null,
        ]);

        $exitCode = Artisan::call('demo:seed-recommendation-history', ['email' => $user->email]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(4, $user->activities()->count());
        $this->assertSame(
            ['Catch up with family', 'Evening reading', 'Grocery shopping', 'Morning exercise'],
            $user->activities()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertSame(240, $user->activityLogs()->count());
        $this->assertSame(['personalize_recommendations' => false], $user->fresh()->preferences);
        $this->assertNull($user->fresh()->recommendation_personalization_consent_at);

        $history = app(UserRecommendationFeedback::class)
            ->recommendationData($user->fresh(), 'exercise', now())['personal_history'];
        $this->assertCount(240, $history);
        $completed = count(array_filter($history, fn (array $outcome): bool => $outcome['label'] === 1));
        $missed = count(array_filter($history, fn (array $outcome): bool => $outcome['label'] === 0));
        $this->assertGreaterThan(20, $completed);
        $this->assertGreaterThan(20, $missed);
        $this->assertLessThan(210, $completed);
        $this->assertLessThan(120, $missed);
        $this->assertGreaterThan(1, count(array_unique(array_column($history, 'hour'))));
        $recentLabels = array_column(array_slice($history, -48), 'label');
        $this->assertGreaterThanOrEqual(5, count(array_filter($recentLabels, fn (int $label): bool => $label === 1)));
        $this->assertGreaterThanOrEqual(5, count(array_filter($recentLabels, fn (int $label): bool => $label === 0)));
    }

    public function test_it_expands_the_previous_two_activity_seed_without_losing_history(): void
    {
        $user = User::factory()->create();
        $activities = collect([
            ['name' => 'Demo: Morning exercise', 'category' => 'exercise'],
            ['name' => 'Demo: Evening reading', 'category' => 'leisure'],
        ])->map(fn (array $attributes) => $user->activities()->create($attributes + [
            'preferred_time_of_day' => '08:00:00',
            'frequency' => 3,
            'duration_minutes' => 30,
        ]));

        for ($index = 0; $index < 160; $index++) {
            $activity = $activities[intdiv($index, 2) % 2];
            $completed = $index % 2 === 0;
            $scheduledFor = now()->subDays(320)->addDays($index * 2)->setTime(8, 0);
            $user->activityLogs()->create([
                'activity_id' => $activity->getKey(),
                'scheduled_for' => $scheduledFor,
                'status' => $completed ? 'completed' : 'missed',
                'logged_at' => $completed ? $scheduledFor->copy()->addMinutes(30) : null,
            ]);
        }

        $exitCode = Artisan::call('demo:seed-recommendation-history', ['email' => $user->email]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            ['Catch up with family', 'Evening reading', 'Grocery shopping', 'Morning exercise'],
            $user->activities()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertSame(240, $user->activityLogs()->count());
        $this->assertSame(4, $user->activities()->distinct('category')->count('category'));
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
