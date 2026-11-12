<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CommitmentRiskAssessmentService;
use App\Services\ScheduleOccurrences;
use App\Support\UserPreferences;
use Illuminate\Console\Command;

class SendCommitmentRiskAlerts extends Command
{
    protected $signature = 'commitment-risk:send-alerts';

    protected $description = 'Create opted-in in-app reminders for eligible upcoming commitments';

    public function handle(CommitmentRiskAssessmentService $assessments, ScheduleOccurrences $occurrences): int
    {
        $threshold = config('services.commitment_risk.alert_threshold');
        if (! config('services.commitment_risk.enabled')
            || ! is_numeric($threshold)
            || ! is_finite((float) $threshold)
            || (float) $threshold < 0
            || (float) $threshold > 1) {
            $this->info('Commitment risk alerts are disabled or have no valid evaluated threshold.');

            return self::SUCCESS;
        }

        $now = now();
        $from = $now->copy()->startOfDay();
        $to = $from->copy()->addDays(2);
        $alertsCreated = 0;

        User::query()
            ->where('preferences->notify_commitment_risk', true)
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($assessments, $occurrences, $from, $to, $now, $threshold, &$alertsCreated) {
                foreach ($users as $user) {
                    $preferences = UserPreferences::fromArray($user->preferences);
                    if (! $preferences->notifyCommitmentRisk) {
                        continue;
                    }

                    foreach ($occurrences->between($user, $from, $to) as $occurrence) {
                        if ($occurrence->start->lt($now->copy()->addMinutes(59))
                            || $occurrence->start->gt($now->copy()->addMinutes(61))
                            || $occurrence->outcome !== null) {
                            continue;
                        }

                        $result = $assessments->assess($user, $occurrence->schedule, $occurrence->start);
                        if ($result['status'] !== 'assessed') {
                            continue;
                        }

                        $assessment = $result['assessment'];
                        if ($assessment->probability < (float) $threshold) {
                            continue;
                        }

                        $notification = $user->notifications()->firstOrCreate(
                            ['risk_assessment_id' => $assessment->getKey()],
                            [
                                'schedule_id' => $occurrence->schedule->getKey(),
                                'type' => 'system_message',
                                'message' => 'Your upcoming commitment may be at risk based on your previously recorded outcomes. This estimate may be wrong; review your schedule and decide what works for you.',
                                'is_read' => false,
                            ],
                        );

                        if ($notification->wasRecentlyCreated) {
                            $alertsCreated++;
                        }
                    }
                }
            });

        $this->info("Created {$alertsCreated} commitment risk alert(s).");

        return self::SUCCESS;
    }
}
