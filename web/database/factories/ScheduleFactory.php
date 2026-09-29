<?php

namespace Database\Factories;

use App\Enums\Category;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfHour()->addDays(fake()->numberBetween(0, 13))->setHour(fake()->numberBetween(6, 20));

        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'category' => fake()->randomElement(Category::cases()),
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addMinutes(fake()->randomElement([30, 45, 60, 90, 120])),
            'priority' => fake()->randomElement(['low', 'medium', 'high']),
            'is_recurring' => false,
            'recurrence_rule' => null,
            'status' => 'pending',
        ];
    }
}
