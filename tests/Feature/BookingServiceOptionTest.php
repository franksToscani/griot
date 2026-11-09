<?php

use App\Models\Booking;
use App\Models\ServiceOption;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a booking can have many options', function () {
    $booking = Booking::factory()->create();
    $options = ServiceOption::factory()->count(2)->for($booking->service)->create();

    attachBookingOptions($booking, $options);

    expect($booking->options)->toHaveCount(2);
    $this->assertDatabaseHas('booking_service_option', [
        'booking_id' => $booking->id,
        'service_option_id' => $options->first()->id,
    ]);
});

test('an option can be chosen in many bookings', function () {
    $option = ServiceOption::factory()->create();
    $bookings = Booking::factory()->count(2)->for($option->service->provider)->for($option->service)->create();

    foreach ($bookings as $booking) {
        attachBookingOptions($booking, $option);
    }

    expect($option->bookings)->toHaveCount(2);
});

test('the same option cannot be added twice to a booking', function () {
    $booking = Booking::factory()->create();
    $option = ServiceOption::factory()->for($booking->service)->create();
    attachBookingOptions($booking, $option);

    expect(fn () => attachBookingOptions($booking, $option))
        ->toThrow(QueryException::class);
});

test('deleting a booking removes its options from the pivot table', function () {
    $booking = Booking::factory()->create();
    $option = ServiceOption::factory()->for($booking->service)->create();
    attachBookingOptions($booking, $option);

    $booking->delete();

    $this->assertDatabaseMissing('booking_service_option', ['booking_id' => $booking->id]);
    $this->assertModelExists($option);
});

test('an option chosen in a booking cannot be deleted', function () {
    $booking = Booking::factory()->create();
    $option = ServiceOption::factory()->for($booking->service)->create();
    attachBookingOptions($booking, $option);

    // In its own transaction (a savepoint): on PostgreSQL a failed statement spoils the test's
    // transaction, and the next query would fail too (docs/ci.md).
    expect(fn () => DB::transaction(fn () => $option->delete()))
        ->toThrow(QueryException::class);

    $this->assertModelExists($option);
});
