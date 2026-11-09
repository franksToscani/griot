<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
            // The price of its service when the booking is made, as CreateBooking saves it (issue
            // #97). Without parents (withoutParents()) there is no service to read.
            'service_price_cents' => fn (array $attributes) => $attributes['service_id'] === null
                ? 0
                : Money::cents(Service::query()->whereKey($attributes['service_id'])->firstOrFail()->price),
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->e164PhoneNumber(),
            'customer_email' => fake()->optional()->safeEmail(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(fake()->numberBetween(2, 8) * 15),
            'status' => BookingStatus::Pending,
            'notes' => fake()->optional()->sentence(),
            // Set here and not in a model event: DatabaseSeeder runs WithoutModelEvents.
            'public_token' => Str::random(48),
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
