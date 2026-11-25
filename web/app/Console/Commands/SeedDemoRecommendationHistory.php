<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\UserTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedDemoRecommendationHistory extends Command
{
    protected $signature = 'demo:seed-recommendation-history
        {email : Email address of the already-registered, empty demo account}
        {--force : Confirm this synthetic data write in production}';

    protected $description = 'Add synthetic personalized-recommendation history to a clean demo account';

    private const OUTCOME_COUNT = 160;

    private const LEGACY_ACTIVITY_NAMES = [
        'Demo: Morning exercise' => 'Morning exercise',
        'Demo: Evening reading' => 'Evening reading',
    ];

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('Provide a valid account email address.');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('force')) {
            $this->components->error('Production requires the explicit --force option.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $this->components->error('No registered account matches that email.');

            return self::FAILURE;
        }

        if ($user->role !== 'user' || $user->status !== 'active') {
            $this->components->error('Synthetic history can only be added to an active user account.');

            return self::FAILURE;
        }

        if ($this->renamePreviouslySeededActivities($user)) {
            $this->components->info('Renamed the two seeded activities. Their 160 history records were left unchanged.');

            return self::SUCCESS;
        }

        if ($user->activities()->exists()
            || $user->activityLogs()->exists()
            || $user->schedules()->exists()
            || $user->recommendations()->exists()) {
            $this->components->error('The account already has app data. Use a newly registered, empty demo account.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user): void {
            $activities = collect([
                [
                    'name' => 'Morning exercise',
                    'category' => 'exercise',
                    'preferred_time_of_day' => '08:00:00',
                    'frequency' => 4,
                    'duration_minutes' => 45,
                ],
                [
                    'name' => 'Evening reading',
                    'category' => 'leisure',
                    'preferred_time_of_day' => '19:00:00',
                    'frequency' => 5,
                    'duration_minutes' => 30,
                ],
            ])->map(fn (array $activity): Activity => $user->activities()->create($activity));

            $timezone = UserTime::timezone($user);
            $firstDay = now($timezone)->startOfDay()->subDays(320);

            for ($index = 0; $index < self::OUTCOME_COUNT; $index++) {
                $completed = $index % 2 === 0;
                $activityIndex = intdiv($index, 2) % $activities->count();
                $activity = $activities[$activityIndex];
                $hour = match ([$activityIndex, $completed]) {
                    [0, true] => 8,
                    [0, false] => 21,
                    [1, true] => 19,
                    default => 13,
                };
                $scheduledFor = $firstDay->copy()->addDays($index * 2)->setTime($hour, 0);

                ActivityLog::query()->create([
                    'user_id' => $user->getKey(),
                    'activity_id' => $activity->getKey(),
                    'scheduled_for' => UserTime::utc($user, $scheduledFor),
                    'status' => $completed ? 'completed' : 'missed',
                    'logged_at' => $completed ? UserTime::utc($user, $scheduledFor->copy()->addHour()) : null,
                ]);
            }
        });

        $this->components->info('Added 160 synthetic outcomes (80 completed, 80 missed) to the demo account.');
        $this->line('Personalization consent was not changed. The account owner must enable it in Settings.');

        return self::SUCCESS;
    }

    private function renamePreviouslySeededActivities(User $user): bool
    {
        $activities = $user->activities()->get();
        $legacyNames = collect(array_keys(self::LEGACY_ACTIVITY_NAMES))->sort()->values();

        if ($activities->count() !== 2
            || $activities->pluck('name')->sort()->values()->all() !== $legacyNames->all()
            || $user->schedules()->exists()
            || $user->recommendations()->exists()) {
            return false;
        }

        $logs = $user->activityLogs()->get(['activity_id', 'status']);
        if ($logs->count() !== self::OUTCOME_COUNT) {
            return false;
        }

        foreach ($activities as $activity) {
            $activityLogs = $logs->where('activity_id', $activity->getKey());
            if ($activityLogs->count() !== 80
                || $activityLogs->where('status', 'completed')->count() !== 40
                || $activityLogs->where('status', 'missed')->count() !== 40) {
                return false;
            }
        }

        DB::transaction(function () use ($activities): void {
            foreach ($activities as $activity) {
                $activity->update(['name' => self::LEGACY_ACTIVITY_NAMES[$activity->name]]);
            }
        });

        return true;
    }
}
