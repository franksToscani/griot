<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use Carbon\CarbonImmutable;

/**
 * A booking on Monday 12 January 2026 between two UTC times ("HH:MM").
 */
function bookingBetween(string $start, string $end, BookingStatus $status = BookingStatus::Pending): Booking
{
    return Booking::factory()->create([
        'starts_at' => CarbonImmutable::parse("2026-01-12 {$start}", 'UTC'),
        'ends_at' => CarbonImmutable::parse("2026-01-12 {$end}", 'UTC'),
        'status' => $status,
    ]);
}

/**
 * The ids of the bookings overlapping a span between two UTC times on 12 January 2026.
 *
 * @return list<int>
 */
function idsOverlapping(string $start, string $end): array
{
    return Booking::query()
        ->overlapping(CarbonImmutable::parse("2026-01-12 {$start}", 'UTC'), CarbonImmutable::parse("2026-01-12 {$end}", 'UTC'))
        ->orderBy('id')
        ->pluck('id')
        ->all();
}

test('occupying keeps pending and confirmed bookings, not cancelled ones', function () {
    $pending = bookingBetween('09:00', '10:00', BookingStatus::Pending);
    $confirmed = bookingBetween('10:00', '11:00', BookingStatus::Confirmed);
    bookingBetween('11:00', '12:00', BookingStatus::Cancelled);

    expect(Booking::query()->occupying()->orderBy('id')->pluck('id')->all())
        ->toBe([$pending->id, $confirmed->id]);
});

test('overlapping finds the bookings that share some time with the span', function () {
    $booking = bookingBetween('10:00', '11:00');

    expect(idsOverlapping('09:30', '10:30'))->toBe([$booking->id]);
    expect(idsOverlapping('10:30', '11:30'))->toBe([$booking->id]);
    // A span inside the booking, and a span containing it.
    expect(idsOverlapping('10:15', '10:45'))->toBe([$booking->id]);
    expect(idsOverlapping('09:00', '12:00'))->toBe([$booking->id]);
});

test('back-to-back spans do not overlap', function () {
    bookingBetween('10:00', '11:00');

    // The span ends when the booking starts, or starts when it ends.
    expect(idsOverlapping('09:00', '10:00'))->toBe([]);
    expect(idsOverlapping('11:00', '12:00'))->toBe([]);
});

test('overlapping compares local dates as UTC', function () {
    $booking = bookingBetween('10:00', '11:00');

    // 11:00-12:00 in Rome in January is 10:00-11:00 UTC: the same time as the booking.
    $start = CarbonImmutable::parse('2026-01-12 11:00', 'Europe/Rome');
    $ids = Booking::query()->overlapping($start, $start->addHour())->pluck('id')->all();
    // 10:00-11:00 in Rome is 09:00-10:00 UTC: it ends when the booking starts.
    $earlier = CarbonImmutable::parse('2026-01-12 10:00', 'Europe/Rome');
    $none = Booking::query()->overlapping($earlier, $earlier->addHour())->pluck('id')->all();

    expect($ids)->toBe([$booking->id]);
    expect($none)->toBe([]);
});

test('the scopes combine with each other and with a provider', function () {
    $provider = Provider::factory()->create();
    $mine = Booking::factory()->for($provider)->create([
        'starts_at' => CarbonImmutable::parse('2026-01-12 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-01-12 11:00', 'UTC'),
        'status' => BookingStatus::Confirmed,
    ]);
    Booking::factory()->for($provider)->create([
        'starts_at' => CarbonImmutable::parse('2026-01-12 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-01-12 11:00', 'UTC'),
        'status' => BookingStatus::Cancelled,
    ]);
    bookingBetween('10:00', '11:00', BookingStatus::Confirmed);

    $ids = $provider->bookings()
        ->occupying()
        ->overlapping(CarbonImmutable::parse('2026-01-12 09:00', 'UTC'), CarbonImmutable::parse('2026-01-12 12:00', 'UTC'))
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$mine->id]);
});
