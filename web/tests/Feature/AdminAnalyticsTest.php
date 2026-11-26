<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_aggregate_counts_and_service_health_without_model_evaluation_or_private_details(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'ok', 'model_loaded' => true])]);
        $admin = User::factory()->admin()->create();
        $activeUser = User::factory()->create(['name' => 'Private Person', 'email' => 'private@example.test']);
        $suspendedUser = User::factory()->suspended()->create(['name' => 'Hidden Person']);
        Schedule::factory()->for($activeUser)->create();
        Schedule::factory()->for($suspendedUser)->create();
        Activity::factory()->for($activeUser)->create(['name' => 'Private Activity']);

        $this->actingAs($admin)->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Registered users')
            ->assertSee('3')
            ->assertSee('Active accounts')
            ->assertSee('2')
            ->assertSee('Suspended accounts')
            ->assertSee('1')
            ->assertSee('Schedules')
            ->assertSee('Activities')
            ->assertDontSee('Slot model evaluation')
            ->assertDontSee('Model comparison')
            ->assertDontSee('Cross-validation F1')
            ->assertSee('Reachable (HTTP 200)')
            ->assertSee('Loaded')
            ->assertDontSee('private@example.test')
            ->assertDontSee('Private Person')
            ->assertDontSee('Private Activity');
    }

    public function test_missing_model_metric_files_do_not_break_the_admin_analytics_page(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'ok', 'model_loaded' => false])]);
        config([
            'services.slot_recommender.model_card_path' => base_path('tests/fixtures/missing-model-card.json'),
            'services.slot_recommender.baselines_path' => base_path('tests/fixtures/missing-baselines.json'),
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.analytics'))
            ->assertOk()
            ->assertDontSee('Slot model evaluation')
            ->assertDontSee('Model metrics are unavailable')
            ->assertSee('Reachable (HTTP 200)')
            ->assertSee('Not loaded');
    }

    public function test_recommender_health_shows_unreachable_when_connection_fails(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused.'));
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Unreachable')
            ->assertDontSee('Reachable (HTTP');
    }

    public function test_non_admin_cannot_access_analytics(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.analytics'))->assertForbidden();
        Http::assertNothingSent();
    }
}
