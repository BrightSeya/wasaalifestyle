<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\ScheduleOccurrenceOutcome;
use App\Models\ScheduleRiskAssessment;
use App\Models\User;
use App\Support\Occurrence;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class CommitmentRiskAssessmentService
{
    /**
     * Assess one exact occurrence only for its owner.
     *
     * @return array{status: string, assessment?: ScheduleRiskAssessment}
     */
    public function assess(User $user, Schedule $schedule, Carbon $occurrenceStart): array
    {
        $ownedSchedule = $user->schedules()->find($schedule->getKey());
        if (! $ownedSchedule) {
            return ['status' => 'not_owned'];
        }

        if (! config('services.commitment_risk.enabled')) {
            $this->invalidate($ownedSchedule, $occurrenceStart);

            return ['status' => 'disabled'];
        }

        $dayStart = $occurrenceStart->copy()->startOfDay();
        $occurrence = app(ScheduleOccurrences::class)
            ->between($user, $dayStart, $dayStart->copy()->addDay())
            ->first(fn (Occurrence $candidate) => $candidate->schedule->is($ownedSchedule)
                && $candidate->start->equalTo($occurrenceStart));

        if (! $occurrence
            || ! $occurrence->start->isFuture()
            || in_array($ownedSchedule->status, ['completed', 'missed'], true)
            || $occurrence->outcome !== null) {
            $this->invalidate($ownedSchedule, $occurrenceStart);

            return ['status' => 'ineligible'];
        }

        $featureData = $this->featuresFor($user, $ownedSchedule, $occurrence);
        if ($featureData === null) {
            $this->invalidate($ownedSchedule, $occurrence->start);

            return ['status' => 'no_history'];
        }

        $scheduleSnapshot = $ownedSchedule->getRawOriginal();

        try {
            $response = Http::acceptJson()
                ->timeout(max(1, (int) config('services.commitment_risk.timeout', 2)))
                ->post(rtrim(config('services.slot_recommender.url'), '/').'/noshow/predict', $featureData);
        } catch (ConnectionException) {
            $this->invalidate($ownedSchedule, $occurrence->start);

            return ['status' => 'unavailable'];
        }

        $payload = $response->json();
        if ($response->failed() || ! $this->validResponse($payload)) {
            $this->invalidate($ownedSchedule, $occurrence->start);

            return ['status' => 'unavailable'];
        }

        $latestSchedule = $user->schedules()->find($ownedSchedule->getKey());
        $outcomeRecorded = $ownedSchedule->occurrenceOutcomes()
            ->where('occurrence_start', $occurrence->start)
            ->exists();
        if (! $latestSchedule
            || ! $this->sameScheduleVersion($scheduleSnapshot, $latestSchedule->getRawOriginal())
            || ! $occurrence->start->isFuture()
            || $outcomeRecorded) {
            $this->invalidate($ownedSchedule, $occurrence->start);

            return ['status' => 'stale'];
        }

        $assessment = $ownedSchedule->riskAssessments()->updateOrCreate(
            ['occurrence_start' => $occurrence->start],
            [
                'probability' => (float) $payload['probability'],
                'model_version' => $payload['model']['version'],
                'assessed_at' => now(),
                'schedule_updated_at' => $ownedSchedule->updated_at,
            ],
        );

        return ['status' => 'assessed', 'assessment' => $assessment];
    }

    /** @return array<string, int|float|string|null>|null */
    private function featuresFor(User $user, Schedule $schedule, Occurrence $occurrence): ?array
    {
        $historyQuery = ScheduleOccurrenceOutcome::query()
            ->join('schedules', 'schedules.id', '=', 'schedule_occurrence_outcomes.schedule_id')
            ->where('schedules.user_id', $user->getKey())
            ->whereIn('schedule_occurrence_outcomes.outcome', ['completed', 'missed'])
            ->whereNotNull('schedule_occurrence_outcomes.occurrence_end')
            ->where('schedule_occurrence_outcomes.occurrence_end', '<=', $occurrence->start)
            ->where('schedule_occurrence_outcomes.recorded_at', '<', $occurrence->start);

        $history = (clone $historyQuery)
            ->selectRaw("COUNT(*) as outcome_count, SUM(CASE WHEN schedule_occurrence_outcomes.outcome = 'missed' THEN 1 ELSE 0 END) as missed_count")
            ->first();

        if (! $history || (int) $history->outcome_count === 0) {
            return null;
        }

        $sameCategory = (clone $historyQuery)
            ->where('schedules.category', $schedule->category->value)
            ->selectRaw("COUNT(*) as outcome_count, SUM(CASE WHEN schedule_occurrence_outcomes.outcome = 'missed' THEN 1 ELSE 0 END) as missed_count")
            ->first();
        $historyCount = (int) $history->outcome_count;
        $sameCategoryCount = (int) ($sameCategory->outcome_count ?? 0);
        $createdAt = Carbon::parse($schedule->created_at);
        $durationMinutes = ($occurrence->end->getTimestamp() - $occurrence->start->getTimestamp()) / 60;

        return [
            'category' => $schedule->category->value,
            'priority' => $schedule->priority,
            'weekday' => $occurrence->start->dayOfWeekIso - 1,
            'scheduled_hour' => $occurrence->start->hour,
            'duration_minutes' => $durationMinutes,
            'lead_hours' => ($occurrence->start->getTimestamp() - $createdAt->getTimestamp()) / 3600,
            'prior_labeled_count' => $historyCount,
            'prior_missed_rate' => (int) $history->missed_count / $historyCount,
            'prior_same_category_labeled_count' => $sameCategoryCount,
            'prior_same_category_missed_rate' => $sameCategoryCount === 0
                ? null
                : (int) $sameCategory->missed_count / $sameCategoryCount,
        ];
    }

    private function validResponse(mixed $payload): bool
    {
        if (! is_array($payload)
            || ! isset($payload['probability'], $payload['model'])
            || ! is_array($payload['model'])
            || ! is_string($payload['model']['version'] ?? null)) {
            return false;
        }

        $probability = $payload['probability'];
        $version = trim($payload['model']['version']);
        if (is_bool($probability)
            || (! is_float($probability) && ! is_int($probability))
            || ! is_finite((float) $probability)
            || (float) $probability < 0
            || (float) $probability > 1
            || $version === ''
            || ! preg_match('/^[A-Za-z0-9._:+-]+$/', $version)
            || mb_strlen($version) > 128) {
            return false;
        }

        if (! is_array($payload['factors'] ?? null) || count($payload['factors']) > 3) {
            return false;
        }

        foreach ($payload['factors'] as $factor) {
            if (! is_array($factor)
                || ! is_string($factor['feature'] ?? null)
                || ! array_key_exists('value', $factor)
                || is_bool($factor['log_odds_contribution'] ?? null)
                || (! is_float($factor['log_odds_contribution'] ?? null) && ! is_int($factor['log_odds_contribution'] ?? null))
                || ! is_finite((float) $factor['log_odds_contribution'])
                || (float) $factor['log_odds_contribution'] <= 0) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    private function sameScheduleVersion(array $before, array $after): bool
    {
        foreach ([
            'user_id', 'title', 'description', 'category', 'start_datetime', 'end_datetime',
            'priority', 'is_recurring', 'recurrence_rule', 'status', 'created_at', 'updated_at',
        ] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function invalidate(Schedule $schedule, Carbon $occurrenceStart): void
    {
        $schedule->riskAssessments()
            ->where('occurrence_start', $occurrenceStart)
            ->delete();
    }
}
