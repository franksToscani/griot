<?php

use App\Models\Booking;
use App\Models\ServiceOption;
use Illuminate\Database\QueryException;

test('a booking can have many options', function () {
    $booking = Booking::factory()->create();
    $options = ServiceOption::factory()->count(2)->for($booking->service)->create();

    $booking->options()->attach($options);

    expect($booking->options)->toHaveCount(2);
    $this->assertDatabaseHas('booking_service_option', [
        'booking_id' => $booking->id,
        'service_option_id' => $options->first()->id,
    ]);
});

test('an option can be chosen in many bookings', function () {
    $option = ServiceOption::factory()->create();
    $bookings = Booking::factory()->count(2)->for($option->service->provider)->for($option->service)->create();

    $option->bookings()->attach($bookings);

    expect($option->bookings)->toHaveCount(2);
});

test('the same option cannot be added twice to a booking', function () {
    $booking = Booking::factory()->create();
    $option = ServiceOption::factory()->for($booking->service)->create();
    $booking->options()->attach($option);

    expect(fn () => $booking->options()->attach($option))
        ->toThrow(QueryException::class);
});

test('deleting a booking removes its options from the pivot table', function () {
    $booking = Booking::factory()->create();
    $option = ServiceOption::factory()->for($booking->service)->create();
    $booking->options()->attach($option);

    $booking->delete();

    $this->assertDatabaseMissing('booking_service_option', ['booking_id' => $booking->id]);
    $this->assertModelExists($option);
});

test('an option chosen in a booking cannot be deleted', function () {
    $booking = Booking::factory()->create();
    $option = ServiceOption::factory()->for($booking->service)->create();
    $booking->options()->attach($option);

    expect(fn () => $option->delete())
        ->toThrow(QueryException::class);

    $this->assertModelExists($option);
});
