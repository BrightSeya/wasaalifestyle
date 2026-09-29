<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $status = fake()->randomElement(['completed', 'completed', 'completed', 'missed', 'skipped']);
        $scheduledFor = now()->startOfHour()->subDays(fake()->numberBetween(1, 30));

        return [
            'activity_id' => Activity::factory(),
            // A log always belongs to the same user as its activity.
            'user_id' => fn (array $attributes) => Activity::find($attributes['activity_id'])->user_id,
            'scheduled_for' => $scheduledFor,
            'status' => $status,
            'logged_at' => $status === 'completed' ? $scheduledFor->copy()->addHour() : null,
        ];
    }
}
