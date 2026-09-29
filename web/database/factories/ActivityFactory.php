<?php

namespace Database\Factories;

use App\Enums\Category;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Morning Run', 'Study Session', 'Meal Prep', 'Meditation', 'Team Call', 'Reading']),
            'category' => fake()->randomElement(Category::cases()),
            'preferred_time_of_day' => sprintf('%02d:00:00', fake()->numberBetween(6, 21)),
            'frequency' => fake()->numberBetween(1, 7),
            'duration_minutes' => fake()->randomElement([15, 30, 45, 60, 90]),
        ];
    }
}
