<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Availability>
 */
class AvailabilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Whole-hour slot between 08:00 and 20:00, with end_time after start_time.
        $startHour = fake()->numberBetween(8, 14);
        $endHour = $startHour + fake()->numberBetween(3, 6);

        return [
            'provider_id' => Provider::factory(),
            'weekday' => fake()->randomElement(Weekday::cases()),
            'start_time' => sprintf('%02d:00:00', $startHour),
            'end_time' => sprintf('%02d:00:00', $endHour),
        ];
    }
}
