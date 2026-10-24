<?php

use App\Booking\BookingDuration;
use App\Booking\InvalidBookingOptions;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;

/**
 * A 60 minute service with a visible option (+15) and a hidden one (+30).
 *
 * @return array{0: Service, 1: ServiceOption, 2: ServiceOption}
 */
function ovsService(): array
{
    $service = Service::factory()->for(Provider::factory())->create(['duration_min' => 60, 'duration_max' => 60]);
    $visible = ServiceOption::factory()->for($service)->create(['extra_duration' => 15]);
    $hidden = ServiceOption::factory()->for($service)->inactive()->create(['extra_duration' => 30]);

    return [$service, $visible, $hidden];
}

/**
 * The message of the refusal, or null if minutesFor() accepted the ids.
 *
 * @param  list<int>  $optionIds
 */
function ovsRefusal(Service $service, array $optionIds): ?string
{
    try {
        app(BookingDuration::class)->minutesFor($service, $optionIds);

        return null;
    } catch (InvalidBookingOptions $exception) {
        return $exception->getMessage();
    }
}

test('a hidden option, a missing id and an option of another service are refused the same way', function (bool $loaded) {
    [$service, $visible, $hidden] = ovsService();
    $otherServiceOption = ServiceOption::factory()->for(Service::factory()->for($service->provider))->create();

    $service = Service::query()->findOrFail($service->id);

    if ($loaded) {
        // Loaded WHOLE, without any filter: the hidden option is in the collection, so only the
        // filter inside BookingDuration can refuse it.
        $service->load('options');
        expect($service->options->pluck('id')->all())->toContain($hidden->id);
    } else {
        expect($service->relationLoaded('options'))->toBeFalse();
    }

    // The same InvalidBookingOptions and the same kind of message for the three: only the id
    // differs, so the answer does not tell a hidden option from a missing one.
    foreach ([$hidden->id, 999999, $otherServiceOption->id] as $id) {
        expect(ovsRefusal($service, [$id]))->toBe("Options [{$id}] are not options of service {$service->id}.");
    }

    // The visible option still adds its minutes.
    expect(app(BookingDuration::class)->minutesFor($service, [$visible->id]))->toBe(75);
})->with([
    'options loaded' => [true],
    'options not loaded' => [false],
]);
