<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A future slot on the quarter hour (UTC), lasting 30 minutes to 2 hours.
        $startsAt = now()
            ->addDays(fake()->numberBetween(1, 30))
            ->setTime(fake()->numberBetween(7, 17), fake()->randomElement([0, 15, 30, 45]));

        return [
            'provider_id' => Provider::factory(),
            // The service belongs to the same provider as the booking.
            'service_id' => fn (array $attributes) => Service::factory()->state([
                'provider_id' => $attributes['provider_id'],
            ]),
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->e164PhoneNumber(),
            'customer_email' => fake()->optional()->safeEmail(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(fake()->numberBetween(2, 8) * 15),
            'status' => BookingStatus::Pending,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Indicate that the provider confirmed the booking.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Confirmed,
        ]);
    }

    /**
     * Indicate that the booking was cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Cancelled,
        ]);
    }
}
