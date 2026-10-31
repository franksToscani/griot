<?php

use App\Booking\CreateBooking;
use App\Booking\CustomerDetails;
use App\Booking\FreeSlots;
use App\Booking\SlotNotAvailable;
use App\Booking\SlotRules;
use App\Booking\TimeWindow;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use Carbon\CarbonImmutable;

// "Now" is Monday 19 October 2026, 07:00 UTC (09:00 in Italy). The provider works today
// 09:00-13:00 local, 07:00-11:00 UTC.
const SLOT_RULES_TODAY = '2026-10-19';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(SLOT_RULES_TODAY.' 07:00', 'UTC'));
});

function srProvider(): Provider
{
    $provider = Provider::factory()->published()->create();
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);

    return $provider;
}

function srService(Provider $provider): Service
{
    return Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);
}

function srUtc(string $time): CarbonImmutable
{
    return CarbonImmutable::parse(SLOT_RULES_TODAY." {$time}", 'UTC');
}

/**
 * The UTC starts of the day's slots, as "HH:MM".
 *
 * @return list<string>
 */
function srStarts(Provider $provider, Service $service, ?SlotRules $rules = null): array
{
    return app(FreeSlots::class)
        ->slotsFor($provider, $service, [], srUtc('00:00')->setTimezone('Europe/Rome'), $rules)
        ->map(fn (TimeWindow $slot) => $slot->start->format('H:i'))
        ->all();
}

function srBook(Provider $provider, Service $service, string $time, BookingStatus $status = BookingStatus::Pending, ?SlotRules $rules = null, bool $byProvider = false): Booking
{
    return app(CreateBooking::class)->create(
        $provider,
        $service,
        [],
        srUtc($time),
        new CustomerDetails('Awa Diop', '+393331234567'),
        status: $status,
        rules: $rules,
        createdByProvider: $byProvider,
    );
}

test('the customer rules read the minimum notice from the config, the provider rules have none', function () {
    config()->set('booking.minimum_notice_minutes', 45);

    expect(SlotRules::forCustomer()->minimumNoticeMinutes)->toBe(45)
        ->and(SlotRules::forProvider()->minimumNoticeMinutes)->toBe(0);
});

test('without rules FreeSlots applies the customer ones; the provider ones start from now', function () {
    $provider = srProvider();
    $service = srService($provider);

    // 120 minutes of notice: from 09:00 UTC.
    expect(srStarts($provider, $service))->toBe(['09:00', '09:30', '10:00'])
        ->and(srStarts($provider, $service, SlotRules::forCustomer()))->toBe(['09:00', '09:30', '10:00'])
        ->and(srStarts($provider, $service, SlotRules::forProvider()))->toBe(['07:00', '07:30', '08:00', '08:30', '09:00', '09:30', '10:00']);

    // A slot that has started is gone for the provider too.
    $this->travelTo(srUtc('07:01'));
    expect(srStarts($provider, $service, SlotRules::forProvider()))->toBe(['07:30', '08:00', '08:30', '09:00', '09:30', '10:00']);
});

test('by default CreateBooking makes a pending booking sent by a customer', function () {
    $provider = srProvider();

    $booking = app(CreateBooking::class)->create($provider, srService($provider), [], srUtc('09:00'), new CustomerDetails('Awa Diop', '+393331234567'));

    expect($booking->status)->toBe(BookingStatus::Pending)
        ->and($booking->fresh()->created_by_provider)->toBeFalse();
});

test('CreateBooking saves the status and the author it is given', function () {
    $provider = srProvider();

    $booking = srBook($provider, srService($provider), '09:00', BookingStatus::Confirmed, SlotRules::forProvider(), true);

    $fresh = $booking->fresh();
    expect($fresh->status)->toBe(BookingStatus::Confirmed)
        ->and($fresh->created_by_provider)->toBeTrue();
});

test('CreateBooking checks the slot again with the rules it is given', function () {
    $provider = srProvider();
    $service = srService($provider);

    // 07:30 UTC is in 30 minutes: refused with the customer rules, given or by default...
    expect(fn () => srBook($provider, $service, '07:30'))->toThrow(SlotNotAvailable::class);
    expect(fn () => srBook($provider, $service, '07:30', rules: SlotRules::forCustomer()))->toThrow(SlotNotAvailable::class);
    expect(Booking::query()->count())->toBe(0);

    // ...accepted with the provider ones.
    srBook($provider, $service, '07:30', BookingStatus::Confirmed, SlotRules::forProvider(), true);
    expect(Booking::query()->count())->toBe(1);

    // The overlap is checked with the provider rules as well.
    expect(fn () => srBook($provider, $service, '08:00', BookingStatus::Confirmed, SlotRules::forProvider(), true))->toThrow(SlotNotAvailable::class);
});

test('even with the provider rules, a start in the past is refused', function () {
    $provider = srProvider();
    $this->travelTo(srUtc('07:10'));

    expect(fn () => srBook($provider, srService($provider), '07:00', BookingStatus::Confirmed, SlotRules::forProvider(), true))
        ->toThrow(SlotNotAvailable::class);
    expect(Booking::query()->count())->toBe(0);
});
