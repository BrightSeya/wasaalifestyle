<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\UserTime;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

class SeedDemoRecommendationHistory extends Command
{
    protected $signature = 'demo:seed-recommendation-history
        {email : Email address of the already-registered, empty demo account}
        {--force : Confirm this synthetic data write in production}';

    protected $description = 'Add synthetic personalized-recommendation history to a clean demo account';

    private const OUTCOME_COUNT = 240;

    private const LEGACY_ACTIVITY_NAMES = [
        'Demo: Morning exercise' => 'Morning exercise',
        'Demo: Evening reading' => 'Evening reading',
    ];

    private const LEGACY_OUTCOME_COUNT = 160;

    private const EXTRA_OUTCOME_COUNT = 80;

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

        if ($this->expandPreviouslySeededActivities($user)) {
            $this->components->info('Expanded the existing history to four activities and 240 outcomes.');

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
            $activities = collect($this->activityDefinitions())
                ->map(fn (array $activity): Activity => $user->activities()->create($activity));

            $this->createHistory(
                $user,
                $activities,
                [0, 1, 0, 2, 1, 0, 3, 1, 0, 2, 1, 3],
                self::OUTCOME_COUNT,
                20261126,
            );
        });

        $this->components->info('Added 240 varied activity outcomes across four activities.');
        $this->line('Personalization consent was not changed. The account owner must enable it in Settings.');

        return self::SUCCESS;
    }

    private function expandPreviouslySeededActivities(User $user): bool
    {
        $activities = $user->activities()->get();
        $legacyNames = collect(array_keys(self::LEGACY_ACTIVITY_NAMES))->sort()->values();
        $currentNames = $activities->pluck('name')->sort()->values();
        $knownNames = [
            $legacyNames->all(),
            ['Evening reading', 'Morning exercise'],
        ];

        if ($activities->count() !== 2
            || ! in_array($currentNames->all(), $knownNames, true)
            || $user->schedules()->exists()
            || $user->recommendations()->exists()) {
            return false;
        }

        $expectedCategories = [
            'Demo: Morning exercise' => 'exercise',
            'Morning exercise' => 'exercise',
            'Demo: Evening reading' => 'leisure',
            'Evening reading' => 'leisure',
        ];
        foreach ($activities as $activity) {
            if ($activity->category->value !== $expectedCategories[$activity->name]) {
                return false;
            }
        }

        $logs = $user->activityLogs()->get(['activity_id', 'status']);
        if ($logs->count() !== self::LEGACY_OUTCOME_COUNT) {
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

        DB::transaction(function () use ($activities, $user): void {
            foreach ($activities as $activity) {
                if (isset(self::LEGACY_ACTIVITY_NAMES[$activity->name])) {
                    $activity->update(['name' => self::LEGACY_ACTIVITY_NAMES[$activity->name]]);
                }
            }

            $additionalActivities = collect(array_slice($this->activityDefinitions(), 2))
                ->map(fn (array $activity): Activity => $user->activities()->create($activity));

            $this->createHistory($user, $additionalActivities, [0, 1], self::EXTRA_OUTCOME_COUNT, 20261127);
        });

        return true;
    }

    /**
     * @return list<array{name: string, category: string, preferred_time_of_day: string, frequency: int, duration_minutes: int}>
     */
    private function activityDefinitions(): array
    {
        return [
            [
                'name' => 'Morning exercise',
                'category' => 'exercise',
                'preferred_time_of_day' => '08:00:00',
                'frequency' => 4,
                'duration_minutes' => 40,
            ],
            [
                'name' => 'Evening reading',
                'category' => 'leisure',
                'preferred_time_of_day' => '19:00:00',
                'frequency' => 5,
                'duration_minutes' => 30,
            ],
            [
                'name' => 'Grocery shopping',
                'category' => 'errand',
                'preferred_time_of_day' => '17:00:00',
                'frequency' => 1,
                'duration_minutes' => 60,
            ],
            [
                'name' => 'Catch up with family',
                'category' => 'social',
                'preferred_time_of_day' => '19:00:00',
                'frequency' => 2,
                'duration_minutes' => 30,
            ],
        ];
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @param  list<int>  $activityPattern
     */
    private function createHistory(
        User $user,
        Collection $activities,
        array $activityPattern,
        int $count,
        int $seed,
    ): void {
        $randomizer = new Randomizer(new Mt19937($seed));
        $dayOffsets = array_slice($randomizer->shuffleArray(range(0, 363)), 0, $count);
        sort($dayOffsets);

        $firstDay = now(UserTime::timezone($user))->startOfDay()->subDays(364);
        $windows = [
            'exercise' => [[6, 7, 8, 9], [16, 17, 20, 21]],
            'leisure' => [[18, 19, 20, 21], [8, 9, 12, 13]],
            'errand' => [[15, 16, 17, 18], [9, 10, 11, 12]],
            'social' => [[18, 19, 20, 21], [11, 12, 14, 15]],
        ];

        foreach ($dayOffsets as $index => $dayOffset) {
            $activity = $activities[$activityPattern[$index % count($activityPattern)]];
            $reliableWindow = $randomizer->getInt(0, 99) < 68;
            $hours = $windows[$activity->category->value][$reliableWindow ? 0 : 1];
            $hour = $hours[$randomizer->getInt(0, count($hours) - 1)];
            $completed = $randomizer->getInt(0, 99) < ($reliableWindow ? 84 : 42);
            $scheduledFor = $firstDay->copy()->addDays($dayOffset)->setTime($hour, 0);

            ActivityLog::query()->create([
                'user_id' => $user->getKey(),
                'activity_id' => $activity->getKey(),
                'scheduled_for' => UserTime::utc($user, $scheduledFor),
                'status' => $completed ? 'completed' : 'missed',
                'logged_at' => $completed
                    ? UserTime::utc($user, $scheduledFor->copy()->addMinutes($activity->duration_minutes))
                    : null,
            ]);
        }
    }
}
