<?php

namespace Database\Factories;

use App\Enums\Category;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recommendation>
 */
class RecommendationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfHour()->addDays(fake()->numberBetween(1, 7))->setHour(fake()->numberBetween(6, 21));

        return [
            'user_id' => User::factory(),
            'activity_id' => null,
            'category' => fake()->randomElement(Category::cases()),
            'suggested_start' => $start,
            'suggested_end' => $start->copy()->addHour(),
            'confidence_score' => fake()->randomFloat(4, 0.5, 0.99),
            'status' => 'pending',
            'generated_at' => now(),
        ];
    }
}
