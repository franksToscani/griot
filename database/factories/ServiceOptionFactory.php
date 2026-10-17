<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceOption>
 */
class ServiceOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'name' => rtrim(fake()->sentence(2, false), '.'),
            'extra_price' => fake()->randomFloat(2, 0, 50),
            // Extra minutes, in 15-minute steps like the service durations.
            'extra_duration' => fake()->numberBetween(0, 4) * 15,
        ];
    }
}
