<?php

use App\Booking\BookingDuration;
use App\Booking\InvalidBookingOptions;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use Illuminate\Support\Facades\DB;

/**
 * A service lasting from $min to $max minutes.
 */
function serviceLasting(int $min, int $max, ?Provider $provider = null): Service
{
    return Service::factory()
        ->for($provider ?? Provider::factory()->create())
        ->create(['duration_min' => $min, 'duration_max' => $max]);
}

function optionAdding(Service $service, int $minutes): ServiceOption
{
    return ServiceOption::factory()->for($service)->create(['extra_duration' => $minutes]);
}

test('without options the booking lasts the longest duration of the service', function () {
    $service = serviceLasting(30, 45);

    expect((new BookingDuration)->minutesFor($service))->toBe(45);
});

test('the chosen options add their extra minutes', function () {
    $service = serviceLasting(60, 90);
    $longHair = optionAdding($service, 30);
    $beads = optionAdding($service, 15);

    expect((new BookingDuration)->minutesFor($service, [$longHair->id]))->toBe(120);
    expect((new BookingDuration)->minutesFor($service, [$longHair->id, $beads->id]))->toBe(135);
});

test('an option without extra minutes does not change the duration', function () {
    $service = serviceLasting(60, 90);
    $extensions = optionAdding($service, 0);

    expect((new BookingDuration)->minutesFor($service, [$extensions->id]))->toBe(90);
});

test('the longest duration is used, not the shortest', function () {
    $service = serviceLasting(60, 180);

    expect((new BookingDuration)->minutesFor($service))->toBe(180);
});

test('an empty list of options is valid', function () {
    $service = serviceLasting(30, 40);
    optionAdding($service, 10);

    expect((new BookingDuration)->minutesFor($service, []))->toBe(40);
});

test('a repeated option counts once', function () {
    $service = serviceLasting(30, 40);
    $design = optionAdding($service, 10);

    expect((new BookingDuration)->minutesFor($service, [$design->id, $design->id, $design->id]))->toBe(50);
});

test('an option of another service of the same provider is rejected', function () {
    $provider = Provider::factory()->create();
    $haircut = serviceLasting(30, 40, $provider);
    $shave = serviceLasting(25, 35, $provider);
    $beardCare = optionAdding($shave, 10);

    expect(fn () => (new BookingDuration)->minutesFor($haircut, [$beardCare->id]))
        ->toThrow(InvalidBookingOptions::class);
});

test('an option of another provider is rejected', function () {
    $service = serviceLasting(30, 40);
    $otherProviderOption = optionAdding(serviceLasting(30, 40), 10);

    expect(fn () => (new BookingDuration)->minutesFor($service, [$otherProviderOption->id]))
        ->toThrow(InvalidBookingOptions::class);
});

test('a missing option id is rejected', function () {
    $service = serviceLasting(30, 40);

    expect(fn () => (new BookingDuration)->minutesFor($service, [999999]))
        ->toThrow(InvalidBookingOptions::class, 'Options [999999] are not options of service');
});

test('one invalid id among valid ones rejects the whole list and returns nothing', function () {
    $service = serviceLasting(30, 40);
    $valid = optionAdding($service, 10);
    $foreign = optionAdding(serviceLasting(30, 40), 10);

    $minutes = null;

    expect(function () use ($service, $valid, $foreign, &$minutes) {
        $minutes = (new BookingDuration)->minutesFor($service, [$valid->id, $foreign->id]);
    })->toThrow(InvalidBookingOptions::class, "Options [{$foreign->id}]");

    expect($minutes)->toBeNull();
});

test('rejected options also fail when the options are already loaded', function () {
    $service = serviceLasting(30, 40);
    $foreign = optionAdding(serviceLasting(30, 40), 10);
    $service->load('options');

    expect(fn () => (new BookingDuration)->minutesFor($service, [$foreign->id]))
        ->toThrow(InvalidBookingOptions::class);
});

test('no query runs when the options are already loaded', function () {
    $service = serviceLasting(30, 40);
    $first = optionAdding($service, 10);
    $second = optionAdding($service, 5);
    $service = Service::query()->with('options')->findOrFail($service->id);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $minutes = (new BookingDuration)->minutesFor($service, [$first->id, $second->id]);
    DB::disableQueryLog();

    expect($minutes)->toBe(55);
    expect(DB::getQueryLog())->toBe([]);
});

test('one query reads all the chosen options when they are not loaded', function () {
    $service = serviceLasting(30, 40);
    $ids = collect(range(1, 4))->map(fn () => optionAdding($service, 5)->id)->all();
    $service = Service::query()->findOrFail($service->id);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $minutes = (new BookingDuration)->minutesFor($service, $ids);
    DB::disableQueryLog();

    expect($minutes)->toBe(60);
    expect(DB::getQueryLog())->toHaveCount(1);
});
