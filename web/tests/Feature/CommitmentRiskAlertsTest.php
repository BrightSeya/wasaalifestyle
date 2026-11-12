<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommitmentRiskAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-12 12:00:00'));
        config([
            'services.commitment_risk.enabled' => true,
            'services.commitment_risk.alert_threshold' => 0.7,
            'services.commitment_risk.timeout' => 2,
            'services.slot_recommender.url' => 'http://127.0.0.1:5001',
        ]);
    }

    public function test_opted_in_user_gets_one_dismissible_in_app_alert_for_eligible_high_risk_occurrence(): void
    {
        $user = User::factory()->create(['preferences' => ['notify_commitment_risk' => true]]);
        $history = Schedule::factory()->for($user)->create([
            'start_datetime' => Carbon::parse('2026-11-10 09:00'),
            'end_datetime' => Carbon::parse('2026-11-10 10:00'),
        ]);
        $history->occurrenceOutcomes()->create([
            'occurrence_start' => Carbon::parse('2026-11-10 09:00'),
            'occurrence_end' => Carbon::parse('2026-11-10 10:00'),
            'outcome' => 'missed',
            'recorded_at' => Carbon::parse('2026-11-10 10:05'),
        ]);
        $upcoming = Schedule::factory()->for($user)->create([
            'start_datetime' => Carbon::parse('2026-11-12 13:00'),
            'end_datetime' => Carbon::parse('2026-11-12 14:00'),
            'created_at' => Carbon::parse('2026-11-01 09:00'),
        ]);
        Http::fake(['*/noshow/predict' => Http::response([
            'probability' => 0.8,
            'factors' => [],
            'model' => ['version' => 'evaluated-test-model'],
        ])]);

        Artisan::call('commitment-risk:send-alerts');
        Artisan::call('commitment-risk:send-alerts');

        $this->assertDatabaseCount('schedule_risk_assessments', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'schedule_id' => $upcoming->id,
            'type' => 'system_message',
        ]);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://127.0.0.1:5001/noshow/predict');
    }

    public function test_default_opt_out_and_missing_threshold_never_request_predictions_or_create_alerts(): void
    {
        $user = User::factory()->create();
        Schedule::factory()->for($user)->create([
            'start_datetime' => Carbon::parse('2026-11-12 13:00'),
            'end_datetime' => Carbon::parse('2026-11-12 14:00'),
        ]);
        Http::fake();

        Artisan::call('commitment-risk:send-alerts');
        config(['services.commitment_risk.alert_threshold' => null]);
        $optedIn = User::factory()->create(['preferences' => ['notify_commitment_risk' => true]]);
        Schedule::factory()->for($optedIn)->create([
            'start_datetime' => Carbon::parse('2026-11-12 13:00'),
            'end_datetime' => Carbon::parse('2026-11-12 14:00'),
        ]);
        Artisan::call('commitment-risk:send-alerts');

        Http::assertNothingSent();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('schedule_risk_assessments', 0);
    }

    public function test_threshold_must_be_met_and_only_alert_window_occurrences_are_assessed(): void
    {
        $user = User::factory()->create(['preferences' => ['notify_commitment_risk' => true]]);
        $history = Schedule::factory()->for($user)->create([
            'start_datetime' => Carbon::parse('2026-11-10 09:00'),
            'end_datetime' => Carbon::parse('2026-11-10 10:00'),
        ]);
        $history->occurrenceOutcomes()->create([
            'occurrence_start' => Carbon::parse('2026-11-10 09:00'),
            'occurrence_end' => Carbon::parse('2026-11-10 10:00'),
            'outcome' => 'missed',
            'recorded_at' => Carbon::parse('2026-11-10 10:05'),
        ]);
        Schedule::factory()->for($user)->create([
            'start_datetime' => Carbon::parse('2026-11-12 13:00'),
            'end_datetime' => Carbon::parse('2026-11-12 14:00'),
            'created_at' => Carbon::parse('2026-11-01 09:00'),
        ]);
        Schedule::factory()->for($user)->create([
            'start_datetime' => Carbon::parse('2026-11-12 14:00'),
            'end_datetime' => Carbon::parse('2026-11-12 15:00'),
            'created_at' => Carbon::parse('2026-11-01 09:00'),
        ]);
        Http::fake(['*/noshow/predict' => Http::response([
            'probability' => 0.69,
            'factors' => [],
            'model' => ['version' => 'evaluated-test-model'],
        ])]);
        Artisan::call('commitment-risk:send-alerts');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('schedule_risk_assessments', 1);
        $this->assertDatabaseCount('notifications', 0);
    }
}
