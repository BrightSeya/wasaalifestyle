<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\ScheduleOccurrenceOutcome;
use App\Models\User;
use App\Services\CommitmentRiskAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommitmentRiskAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-12 12:00:00'));
        config([
            'services.commitment_risk.enabled' => true,
            'services.commitment_risk.timeout' => 2,
            'services.slot_recommender.url' => 'http://127.0.0.1:5001',
        ]);
    }

    private function schedule(User $user, string $start, string $end, array $attributes = []): Schedule
    {
        return Schedule::factory()->for($user)->create(array_merge([
            'start_datetime' => Carbon::parse($start),
            'end_datetime' => Carbon::parse($end),
            'category' => 'work',
            'priority' => 'medium',
        ], $attributes));
    }

    private function addOutcome(Schedule $schedule, string $start, string $end, string $outcome, ?string $recordedAt = null): ScheduleOccurrenceOutcome
    {
        return $schedule->occurrenceOutcomes()->create([
            'occurrence_start' => Carbon::parse($start),
            'occurrence_end' => Carbon::parse($end),
            'outcome' => $outcome,
            'recorded_at' => Carbon::parse($recordedAt ?? $end),
        ]);
    }

    private function response(float $probability = 0.72, string $version = 'abc123'): array
    {
        return [
            'probability' => $probability,
            'factors' => [[
                'feature' => 'prior_missed_rate',
                'value' => 1.0,
                'log_odds_contribution' => 0.42,
            ]],
            'model' => ['name' => 'test model', 'version' => $version],
        ];
    }

    public function test_owner_assessment_sends_only_approved_features_and_persists_minimal_result(): void
    {
        $user = User::factory()->create();
        $past = $this->schedule($user, '2026-11-10 09:00', '2026-11-10 10:00', [
            'created_at' => Carbon::parse('2026-11-01 09:00'),
        ]);
        $this->addOutcome($past, '2026-11-10 09:00', '2026-11-10 10:00', 'missed', '2026-11-10 10:05');
        $lateReported = $this->schedule($user, '2026-11-11 08:00', '2026-11-11 09:00');
        $this->addOutcome($lateReported, '2026-11-11 08:00', '2026-11-11 09:00', 'completed', '2026-11-14 14:01');
        $otherUser = User::factory()->create();
        $otherUsersHistory = $this->schedule($otherUser, '2026-11-10 09:00', '2026-11-10 10:00');
        $this->addOutcome($otherUsersHistory, '2026-11-10 09:00', '2026-11-10 10:00', 'missed', '2026-11-10 10:05');
        $future = $this->schedule($user, '2026-11-14 14:00', '2026-11-14 15:30', [
            'created_at' => Carbon::parse('2026-11-01 14:00'),
        ]);
        Http::fake(['*/noshow/predict' => Http::response($this->response(), 200)]);

        $result = app(CommitmentRiskAssessmentService::class)->assess(
            $user,
            $future,
            Carbon::parse('2026-11-14 14:00'),
        );

        $this->assertSame('assessed', $result['status']);
        $this->assertSame(0.72, $result['assessment']->probability);
        $this->assertSame('abc123', $result['assessment']->model_version);
        $this->assertSame('2026-11-14 14:00:00', $result['assessment']->occurrence_start->format('Y-m-d H:i:s'));
        $this->assertSame($future->updated_at->timestamp, $result['assessment']->schedule_updated_at->timestamp);
        $this->assertDatabaseCount('schedule_risk_assessments', 1);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://127.0.0.1:5001/noshow/predict');
        $requestData = Http::recorded()->first()[0]->data();
        $this->assertSame('work', $requestData['category']);
        $this->assertSame(5, $requestData['weekday']);
        $this->assertSame(14, $requestData['scheduled_hour']);
        $this->assertSame(90.0, (float) $requestData['duration_minutes']);
        $this->assertSame(1, $requestData['prior_labeled_count']);
        $this->assertSame(1.0, (float) $requestData['prior_missed_rate']);
        $this->assertSame(1, $requestData['prior_same_category_labeled_count']);
        $this->assertArrayNotHasKey('schedule_id', $requestData);
        $this->assertArrayNotHasKey('user_id', $requestData);
        $this->assertArrayNotHasKey('title', $requestData);
    }

    public function test_only_owned_future_occurrences_are_assessed(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $schedule = $this->schedule($owner, '2026-11-14 14:00', '2026-11-14 15:00');
        Http::fake();

        $result = app(CommitmentRiskAssessmentService::class)->assess(
            $otherUser,
            $schedule,
            Carbon::parse('2026-11-14 14:00'),
        );

        $this->assertSame('not_owned', $result['status']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('schedule_risk_assessments', 0);
    }

    public function test_missing_history_returns_without_request_or_assessment(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule($user, '2026-11-14 14:00', '2026-11-14 15:00');
        Http::fake();

        $result = app(CommitmentRiskAssessmentService::class)->assess(
            $user,
            $schedule,
            Carbon::parse('2026-11-14 14:00'),
        );

        $this->assertSame('no_history', $result['status']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('schedule_risk_assessments', 0);
    }

    public function test_disabled_feature_does_not_call_python_or_leave_a_stale_assessment(): void
    {
        $user = User::factory()->create();
        $history = $this->schedule($user, '2026-11-10 09:00', '2026-11-10 10:00');
        $this->addOutcome($history, '2026-11-10 09:00', '2026-11-10 10:00', 'completed');
        $schedule = $this->schedule($user, '2026-11-14 14:00', '2026-11-14 15:00');
        $schedule->riskAssessments()->create([
            'occurrence_start' => '2026-11-14 14:00:00',
            'probability' => 0.8,
            'model_version' => 'old-model',
            'assessed_at' => now(),
            'schedule_updated_at' => $schedule->updated_at,
        ]);
        config(['services.commitment_risk.enabled' => false]);
        Http::fake();

        $result = app(CommitmentRiskAssessmentService::class)->assess(
            $user,
            $schedule,
            Carbon::parse('2026-11-14 14:00'),
        );

        $this->assertSame('disabled', $result['status']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('schedule_risk_assessments', 0);
    }

    public function test_past_wrong_date_and_cancelled_occurrences_are_not_assessed(): void
    {
        $user = User::factory()->create();
        $history = $this->schedule($user, '2026-11-10 09:00', '2026-11-10 10:00');
        $this->addOutcome($history, '2026-11-10 09:00', '2026-11-10 10:00', 'completed');
        $future = $this->schedule($user, '2026-11-14 14:00', '2026-11-14 15:00');
        $this->addOutcome($future, '2026-11-14 14:00', '2026-11-14 15:00', 'cancelled', '2026-11-12 11:00');
        Http::fake();
        $service = app(CommitmentRiskAssessmentService::class);

        $cancelled = $service->assess($user, $future, Carbon::parse('2026-11-14 14:00'));
        $wrongDate = $service->assess($user, $future, Carbon::parse('2026-11-15 14:00'));
        $past = $service->assess($user, $history, Carbon::parse('2026-11-10 09:00'));

        $this->assertSame('ineligible', $cancelled['status']);
        $this->assertSame('ineligible', $wrongDate['status']);
        $this->assertSame('ineligible', $past['status']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('schedule_risk_assessments', 0);
    }

    public function test_http_failures_and_invalid_payloads_do_not_create_assessments(): void
    {
        $user = User::factory()->create();
        $history = $this->schedule($user, '2026-11-10 09:00', '2026-11-10 10:00');
        $this->addOutcome($history, '2026-11-10 09:00', '2026-11-10 10:00', 'completed');
        $schedule = $this->schedule($user, '2026-11-14 14:00', '2026-11-14 15:00');
        $service = app(CommitmentRiskAssessmentService::class);

        Http::fake(['*' => Http::response(['error' => 'Model not loaded'], 503)]);
        $unavailable = $service->assess($user, $schedule, Carbon::parse('2026-11-14 14:00'));
        $this->assertSame('unavailable', $unavailable['status']);

        Http::fake(['*' => Http::response(['probability' => 2, 'model' => ['version' => 'v1'], 'factors' => []], 200)]);
        $invalid = $service->assess($user, $schedule, Carbon::parse('2026-11-14 14:00'));
        $this->assertSame('unavailable', $invalid['status']);
        $this->assertDatabaseCount('schedule_risk_assessments', 0);

        Http::fake(['*' => function () {
            throw new ConnectionException('private schedule data must not be reported');
        }]);
        $timeout = $service->assess($user, $schedule, Carbon::parse('2026-11-14 14:00'));
        $this->assertSame('unavailable', $timeout['status']);
        $this->assertDatabaseCount('schedule_risk_assessments', 0);
    }

    public function test_schedule_update_invalidates_assessment_and_delete_cascades_it(): void
    {
        $user = User::factory()->create();
        $history = $this->schedule($user, '2026-11-10 09:00', '2026-11-10 10:00');
        $this->addOutcome($history, '2026-11-10 09:00', '2026-11-10 10:00', 'completed');
        $schedule = $this->schedule($user, '2026-11-14 14:00', '2026-11-14 15:00');
        Http::fake(['*' => Http::response($this->response(), 200)]);
        $service = app(CommitmentRiskAssessmentService::class);
        $service->assess($user, $schedule, Carbon::parse('2026-11-14 14:00'));
        $this->assertDatabaseCount('schedule_risk_assessments', 1);

        $schedule->update(['title' => 'Changed commitment']);
        $this->assertDatabaseCount('schedule_risk_assessments', 0);

        $service->assess($user, $schedule->fresh(), Carbon::parse('2026-11-14 14:00'));
        $this->assertDatabaseCount('schedule_risk_assessments', 1);
        $schedule->delete();
        $this->assertDatabaseCount('schedule_risk_assessments', 0);
    }

    public function test_schedule_changed_during_request_is_not_saved_as_a_fresh_assessment(): void
    {
        $user = User::factory()->create();
        $history = $this->schedule($user, '2026-11-10 09:00', '2026-11-10 10:00');
        $this->addOutcome($history, '2026-11-10 09:00', '2026-11-10 10:00', 'completed');
        $schedule = $this->schedule($user, '2026-11-14 14:00', '2026-11-14 15:00');
        Http::fake(['*' => function () use ($schedule) {
            $schedule->update(['title' => 'Changed during inference']);

            return Http::response($this->response(), 200);
        }]);

        $result = app(CommitmentRiskAssessmentService::class)->assess(
            $user,
            $schedule,
            Carbon::parse('2026-11-14 14:00'),
        );

        $this->assertSame('stale', $result['status']);
        $this->assertDatabaseCount('schedule_risk_assessments', 0);
    }
}
