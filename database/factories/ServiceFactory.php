<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Durations are in minutes, in 15-minute steps, with max >= min.
        $durationMin = fake()->numberBetween(2, 8) * 15;

        return [
            'provider_id' => Provider::factory(),
            'category_id' => Category::factory(),
            'name' => rtrim(fake()->sentence(3, false), '.'),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 10, 200),
            'duration_min' => $durationMin,
            'duration_max' => $durationMin + fake()->numberBetween(0, 8) * 15,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the service is hidden from customers.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
