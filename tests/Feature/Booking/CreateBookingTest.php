<?php

use App\Booking\CreateBooking;
use App\Booking\CustomerDetails;
use App\Booking\InvalidBookingOptions;
use App\Booking\ServiceNotOfProvider;
use App\Booking\SlotNotAvailable;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// "Now" is Sunday 18 October 2026, 08:00 UTC (10:00 in Italy, summer time UTC+2). The bookings are
// for Monday 19 October, where the 09:00-13:00 local window is 07:00-11:00 UTC.
const BOOKING_MONDAY = '2026-10-19';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
});

/**
 * A published provider working on Mondays from 09:00 to 13:00 local.
 */
function cbProvider(): Provider
{
    $provider = Provider::factory()->published()->create();
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);

    return $provider;
}

function cbService(Provider $provider, int $minutes = 60): Service
{
    return Service::factory()->for($provider)->create(['duration_min' => $minutes, 'duration_max' => $minutes]);
}

/**
 * A local time in Italy ("HH:MM") on the given day.
 */
function cbLocal(string $time, string $day = BOOKING_MONDAY): CarbonImmutable
{
    return CarbonImmutable::parse("{$day} {$time}", 'Europe/Rome');
}

/**
 * @param  list<int>  $optionIds
 */
function cbBook(Provider $provider, Service $service, CarbonImmutable $startsAt, array $optionIds = []): Booking
{
    return app(CreateBooking::class)->create(
        $provider,
        $service,
        $optionIds,
        $startsAt,
        new CustomerDetails('Awa Diop', '+393331234567'),
    );
}

/**
 * A booking of the provider between two UTC times of the test Monday, with the given status.
 */
function cbExistingBooking(Provider $provider, string $start, string $end, BookingStatus $status): void
{
    Booking::factory()->for($provider)->for(cbService($provider))->create([
        'starts_at' => CarbonImmutable::parse(BOOKING_MONDAY." {$start}", 'UTC'),
        'ends_at' => CarbonImmutable::parse(BOOKING_MONDAY." {$end}", 'UTC'),
        'status' => $status,
    ]);
}

test('a booking is created pending, for the provider, in UTC, with the customer details and a token', function () {
    $provider = cbProvider();
    $service = cbService($provider);

    $booking = app(CreateBooking::class)->create(
        $provider,
        $service,
        [],
        CarbonImmutable::parse(BOOKING_MONDAY.' 07:00', 'UTC'),
        new CustomerDetails('Awa Diop', '+393331234567'),
    )->refresh();

    expect($booking->status)->toBe(BookingStatus::Pending);
    expect($booking->provider_id)->toBe($provider->id);
    expect($booking->service_id)->toBe($service->id);
    expect($booking->starts_at->toIso8601String())->toBe('2026-10-19T07:00:00+00:00');
    // The end is the start plus the 60 minutes of the service.
    expect($booking->ends_at->toIso8601String())->toBe('2026-10-19T08:00:00+00:00');
    expect($booking->customer_name)->toBe('Awa Diop');
    expect($booking->customer_phone)->toBe('+393331234567');
    expect($booking->customer_email)->toBeNull();
    expect($booking->notes)->toBeNull();
    expect($booking->public_token)->toHaveLength(48);
});

test('email and notes are saved when given', function () {
    $provider = cbProvider();

    $booking = app(CreateBooking::class)->create(
        $provider,
        cbService($provider),
        [],
        cbLocal('09:00'),
        new CustomerDetails('Awa Diop', '+393331234567', 'awa@example.com', 'Porto io le extension.'),
    )->refresh();

    expect($booking->customer_email)->toBe('awa@example.com');
    expect($booking->notes)->toBe('Porto io le extension.');
});

test('options make the booking longer and are linked once, even when repeated', function () {
    $provider = cbProvider();
    $service = cbService($provider);
    $longHair = ServiceOption::factory()->for($service)->create(['extra_duration' => 30]);
    $beads = ServiceOption::factory()->for($service)->create(['extra_duration' => 0]);

    $booking = cbBook($provider, $service, cbLocal('09:00'), [$longHair->id, $longHair->id, $beads->id]);

    // 60 minutes of the service plus 30 of the long hair option: 07:00-08:30 UTC.
    expect($booking->refresh()->ends_at->toIso8601String())->toBe('2026-10-19T08:30:00+00:00');
    expect($booking->options()->orderBy('service_options.id')->pluck('service_options.id')->all())
        ->toBe([$longHair->id, $beads->id]);
});

test('the same slot booked twice in a row is created once', function () {
    $provider = cbProvider();
    $service = cbService($provider);
    cbBook($provider, $service, cbLocal('09:00'));

    expect(fn () => cbBook($provider, $service, cbLocal('09:00')))->toThrow(SlotNotAvailable::class);
    expect(Booking::query()->count())->toBe(1);
});

test('an overlapping start is refused, a back-to-back one is accepted', function () {
    $provider = cbProvider();
    $service = cbService($provider);
    cbBook($provider, $service, cbLocal('09:00'));

    // 09:30-10:30 overlaps 09:00-10:00; 10:00-11:00 starts when it ends.
    expect(fn () => cbBook($provider, $service, cbLocal('09:30')))->toThrow(SlotNotAvailable::class);
    expect(cbBook($provider, $service, cbLocal('10:00'))->exists)->toBeTrue();
    expect(Booking::query()->count())->toBe(2);
});

test('a cancelled booking does not block, pending and confirmed ones do', function () {
    $provider = cbProvider();
    $service = cbService($provider);
    // 09:00-10:00 cancelled, 10:00-11:00 pending, 11:00-12:00 confirmed (local).
    cbExistingBooking($provider, '07:00', '08:00', BookingStatus::Cancelled);
    cbExistingBooking($provider, '08:00', '09:00', BookingStatus::Pending);
    cbExistingBooking($provider, '09:00', '10:00', BookingStatus::Confirmed);

    expect(cbBook($provider, $service, cbLocal('09:00'))->exists)->toBeTrue();
    expect(fn () => cbBook($provider, $service, cbLocal('10:00')))->toThrow(SlotNotAvailable::class);
    expect(fn () => cbBook($provider, $service, cbLocal('11:00')))->toThrow(SlotNotAvailable::class);
});

test('a booking of another provider does not block', function () {
    $provider = cbProvider();
    $service = cbService($provider);
    cbExistingBooking(cbProvider(), '07:00', '08:00', BookingStatus::Confirmed);

    expect(cbBook($provider, $service, cbLocal('09:00'))->exists)->toBeTrue();
});

test('a start that is not a free slot is refused and nothing is created', function (Closure $startsAt, ?string $now) {
    if ($now !== null) {
        $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
    }
    $provider = cbProvider();
    $service = cbService($provider);

    expect(fn () => cbBook($provider, $service, $startsAt()))->toThrow(SlotNotAvailable::class);
    expect(Booking::query()->count())->toBe(0);
})->with([
    // The grid starts at 09:00 every 30 minutes.
    'off the grid' => [fn () => cbLocal('09:10'), null],
    // The window ends at 13:00.
    'outside the window' => [fn () => cbLocal('14:00'), null],
    // Monday 12 October is before "now".
    'in the past' => [fn () => cbLocal('09:00', '2026-10-12'), null],
    // Now is Monday 10:10 local: with 120 minutes of notice the earliest start is 12:10.
    'inside the minimum notice' => [fn () => cbLocal('11:00'), '2026-10-19 08:10'],
    // 18 October plus 60 days is 17 December; Monday 21 December is beyond.
    'beyond the booking window' => [fn () => cbLocal('09:00', '2026-12-21'), null],
]);

test('an unpublished provider or an inactive service cannot be booked', function () {
    $unpublished = cbProvider();
    $unpublished->forceFill(['is_published' => false])->save();
    $provider = cbProvider();
    $inactive = Service::factory()->inactive()->for($provider)->create();

    expect(fn () => cbBook($unpublished, cbService($unpublished), cbLocal('09:00')))->toThrow(SlotNotAvailable::class);
    expect(fn () => cbBook($provider, $inactive, cbLocal('09:00')))->toThrow(SlotNotAvailable::class);
    expect(Booking::query()->count())->toBe(0);
});

test('a service of another provider or a foreign option is rejected, and nothing is created', function () {
    $provider = cbProvider();
    $service = cbService($provider);
    $otherService = cbService(cbProvider());
    $foreignOption = ServiceOption::factory()->for($otherService)->create();

    expect(fn () => cbBook($provider, $otherService, cbLocal('09:00')))->toThrow(ServiceNotOfProvider::class);
    expect(fn () => cbBook($provider, $service, cbLocal('09:00'), [$foreignOption->id]))->toThrow(InvalidBookingOptions::class);
    expect(Booking::query()->count())->toBe(0);
    expect(DB::table('booking_service_option')->count())->toBe(0);
});

test('a start given in another time zone is the same instant and is saved in UTC', function () {
    $provider = cbProvider();

    // 09:00 in Rome in summer time is 07:00 UTC.
    $booking = cbBook($provider, cbService($provider), CarbonImmutable::parse(BOOKING_MONDAY.' 09:00', 'Europe/Rome'));

    expect(DB::table('bookings')->where('id', $booking->id)->value('starts_at'))->toBe('2026-10-19 07:00:00');
    expect($booking->refresh()->starts_at->timezoneName)->toBe('UTC');
});

test('every booking gets its own token', function () {
    $provider = cbProvider();
    $service = cbService($provider);

    $first = cbBook($provider, $service, cbLocal('09:00'));
    $second = cbBook($provider, $service, cbLocal('10:00'));

    expect($first->public_token)->toHaveLength(48);
    expect($second->public_token)->toHaveLength(48);
    expect($first->public_token)->not->toBe($second->public_token);
});

test('the token cannot be mass assigned', function () {
    $booking = new Booking(['public_token' => 'scelto-dal-cliente', 'customer_name' => 'Awa Diop']);

    expect($booking->isFillable('public_token'))->toBeFalse();
    expect($booking->public_token)->toBeNull();
    expect($booking->customer_name)->toBe('Awa Diop');
});
