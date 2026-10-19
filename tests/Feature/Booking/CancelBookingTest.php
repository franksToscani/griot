<?php

use App\Booking\CancelBooking;
use App\Booking\CancellationNotAllowed;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// The reference booking starts on 2026-10-29 at 08:00 UTC; with 1440 minutes of notice the
// limit is 2026-10-28 08:00:00 UTC. "Now" is 2026-10-27 08:00 UTC.
beforeEach(function () {
    config()->set('booking.cancellation_notice_minutes', 1440);
    $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
});

/**
 * @param  array<string, mixed>  $override
 */
function cbkBooking(array $override = []): Booking
{
    return Booking::factory()->create([
        'starts_at' => CarbonImmutable::parse('2026-10-29 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-29 09:00', 'UTC'),
        ...$override,
    ]);
}

test('cancel returns true the first time and false the second, canCancel is false once cancelled', function () {
    $booking = cbkBooking();
    $cancelBooking = app(CancelBooking::class);

    expect($cancelBooking->canCancel($booking))->toBeTrue();
    expect($cancelBooking->cancel($booking))->toBeTrue();
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);

    expect($cancelBooking->cancel($booking))->toBeFalse();
    expect($cancelBooking->canCancel($booking->fresh()))->toBeFalse();
});

test('an old model still pending, with the row already cancelled, gives false without writing', function () {
    $booking = cbkBooking();
    // Cancelled in the database behind the model's back: the model in memory still says pending.
    DB::table('bookings')->where('id', $booking->id)->update(['status' => BookingStatus::Cancelled->value]);
    expect($booking->status)->toBe(BookingStatus::Pending);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    expect(app(CancelBooking::class)->cancel($booking))->toBeFalse();

    // The row was read again, and nothing was written.
    expect(collect($queries)->filter(fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, 'bookings')))->toHaveCount(1);
    expect(collect($queries)->filter(fn (string $sql) => str_starts_with($sql, 'update')))->toBeEmpty();
});

test('an old model still far from the start, with the row moved inside the limit, is refused', function () {
    $booking = cbkBooking(['status' => BookingStatus::Confirmed]);
    // Moved in the database to tomorrow at 07:00 UTC: the limit was today at 07:00, already passed.
    DB::table('bookings')->where('id', $booking->id)->update([
        'starts_at' => '2026-10-28 07:00:00',
        'ends_at' => '2026-10-28 08:00:00',
    ]);

    expect(fn () => app(CancelBooking::class)->cancel($booking))->toThrow(CancellationNotAllowed::class);
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

test('the exception has a technical message without the token', function () {
    $booking = cbkBooking();
    $message = CancellationNotAllowed::for($booking)->getMessage();

    expect($message)
        ->toBe("Booking {$booking->id} (pending, starting at 2026-10-29T08:00:00Z) can no longer be cancelled by the customer.")
        ->not->toContain($booking->public_token);
});

test('the notice comes from the configuration', function () {
    config()->set('booking.cancellation_notice_minutes', 60);
    $booking = cbkBooking();
    $cancelBooking = app(CancelBooking::class);

    // With 60 minutes the limit is 2026-10-29 07:00:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-29 07:00:00', 'UTC'));
    expect($cancelBooking->canCancel($booking))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-10-29 07:00:01', 'UTC'));
    expect($cancelBooking->canCancel($booking))->toBeFalse();
});
