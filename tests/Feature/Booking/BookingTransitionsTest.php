<?php

use App\Booking\BookingTransitions;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// "Now" is 2026-10-27 08:00 UTC. With 1440 minutes of notice, a booking starting on 2026-10-29 at
// 08:00 UTC can be cancelled by the customer until 2026-10-28 08:00:00 UTC.
beforeEach(function () {
    config()->set('booking.cancellation_notice_minutes', 1440);
    $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
});

/**
 * A booking in memory only: the rules never read the database.
 */
function btrBooking(BookingStatus $status, string $startsAtUtc): Booking
{
    $booking = new Booking;
    $booking->status = $status;
    $booking->starts_at = CarbonImmutable::parse($startsAtUtc, 'UTC');

    return $booking;
}

function btrRules(): BookingTransitions
{
    return app(BookingTransitions::class);
}

test('the whole matrix of statuses, times and roles', function (BookingStatus $status, string $start, ?string $confirm, ?string $reject, ?string $providerCancel, ?string $customerCancel) {
    $booking = btrBooking($status, $start);

    expect(btrRules()->confirmRefusal($booking))->toBe($confirm);
    expect(btrRules()->rejectRefusal($booking))->toBe($reject);
    expect(btrRules()->providerCancelRefusal($booking))->toBe($providerCancel);
    expect(btrRules()->customerCancelRefusal($booking))->toBe($customerCancel);

    // The four "can" methods say the same: allowed when there is no reason to refuse.
    expect(btrRules()->canConfirm($booking))->toBe($confirm === null);
    expect(btrRules()->canReject($booking))->toBe($reject === null);
    expect(btrRules()->canCancelAsProvider($booking))->toBe($providerCancel === null);
    expect(btrRules()->canCancelAsCustomer($booking))->toBe($customerCancel === null);
})->with([
    // status, start (UTC), confirm, reject, cancel by the provider, cancel by the customer
    'pending, in two days' => [BookingStatus::Pending, '2026-10-29 08:00', null, null, 'not_confirmed', null],
    'pending, inside the notice' => [BookingStatus::Pending, '2026-10-27 09:00', null, null, 'not_confirmed', 'too_late'],
    'pending, starting now' => [BookingStatus::Pending, '2026-10-27 08:00', 'already_started', null, 'not_confirmed', 'too_late'],
    'pending, past' => [BookingStatus::Pending, '2026-10-20 08:00', 'already_started', null, 'not_confirmed', 'too_late'],
    'confirmed, in two days' => [BookingStatus::Confirmed, '2026-10-29 08:00', 'not_pending', 'not_pending', null, null],
    'confirmed, inside the notice' => [BookingStatus::Confirmed, '2026-10-27 09:00', 'not_pending', 'not_pending', null, 'too_late'],
    'confirmed, past' => [BookingStatus::Confirmed, '2026-10-20 08:00', 'not_pending', 'not_pending', null, 'too_late'],
    'cancelled, in two days' => [BookingStatus::Cancelled, '2026-10-29 08:00', 'already_cancelled', 'already_cancelled', 'already_cancelled', 'already_cancelled'],
    'cancelled, past' => [BookingStatus::Cancelled, '2026-10-20 08:00', 'already_cancelled', 'already_cancelled', 'already_cancelled', 'already_cancelled'],
]);

test('confirming is allowed up to one second before the start, not at the exact start', function () {
    $booking = btrBooking(BookingStatus::Pending, '2026-10-29 08:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-29 07:59:59', 'UTC'));
    expect(btrRules()->confirmRefusal($booking))->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-10-29 08:00:00', 'UTC'));
    expect(btrRules()->confirmRefusal($booking))->toBe('already_started');
});

test('the customer can cancel at the exact limit, not one second after', function (string $start, string $lastMoment, string $tooLate) {
    $booking = btrBooking(BookingStatus::Confirmed, $start);

    $this->travelTo(CarbonImmutable::parse($lastMoment, 'UTC'));
    expect(btrRules()->canCancelAsCustomer($booking))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse($tooLate, 'UTC'));
    expect(btrRules()->customerCancelRefusal($booking))->toBe('too_late');
})->with([
    'winter' => ['2026-10-29 08:00', '2026-10-28 08:00:00', '2026-10-28 08:00:01'],
    'summer' => ['2026-07-06 07:00', '2026-07-05 07:00:00', '2026-07-05 07:00:01'],
    // 1440 minutes on the UTC instant, across the change of time on 25 October.
    'change of time' => ['2026-10-25 08:00', '2026-10-24 08:00:00', '2026-10-24 08:00:01'],
]);

test('the rules run no query', function () {
    $booking = btrBooking(BookingStatus::Pending, '2026-10-29 08:00');
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    btrRules()->canConfirm($booking);
    btrRules()->canReject($booking);
    btrRules()->canCancelAsProvider($booking);
    btrRules()->canCancelAsCustomer($booking);

    expect($queries)->toBe(0);
});
