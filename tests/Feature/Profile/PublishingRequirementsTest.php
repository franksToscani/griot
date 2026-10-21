<?php

use App\Models\Availability;
use App\Models\Provider;
use App\Models\Service;
use App\Profile\PublishingRequirements;

/**
 * A provider with the given fields, an active service and an availability unless told otherwise.
 *
 * @param  array<string, mixed>  $override
 */
function pprProvider(array $override = [], bool $activeService = true, bool $availability = true): Provider
{
    $provider = Provider::factory()->create(['name' => 'Salone Awa', 'city' => 'Bologna', ...$override]);

    if ($activeService) {
        Service::factory()->for($provider)->create();
    }

    if ($availability) {
        Availability::factory()->for($provider)->create();
    }

    return $provider;
}

function pprMissing(Provider $provider): array
{
    return app(PublishingRequirements::class)->missing($provider);
}

test('a complete provider misses nothing', function () {
    expect(pprMissing(pprProvider()))->toBe([]);
});

test('only an inactive service does not count', function () {
    $provider = pprProvider(activeService: false);
    Service::factory()->for($provider)->inactive()->create();

    expect(pprMissing($provider))->toBe(['service']);
});

test('without an availability, availability is missing', function () {
    expect(pprMissing(pprProvider(availability: false)))->toBe(['availability']);
});

test('a name or a city of only spaces is missing', function (array $override, array $missing) {
    // Saved with spaces directly: the form would trim them, older data might not be.
    $provider = pprProvider();
    $provider->forceFill($override)->save();

    expect(pprMissing($provider->fresh()))->toBe($missing);
})->with([
    'name' => [['name' => '   '], ['name']],
    'city' => [['city' => '   '], ['city']],
]);

test('with nothing, the four codes come in the fixed order', function () {
    $provider = pprProvider(activeService: false, availability: false);
    $provider->forceFill(['name' => ' ', 'city' => ' '])->save();

    expect(pprMissing($provider->fresh()))->toBe(['name', 'city', 'service', 'availability']);
});

test('the service and the availability of another provider do not count', function () {
    $provider = pprProvider(activeService: false, availability: false);
    pprProvider(['slug' => 'altro-provider']);

    expect(pprMissing($provider))->toBe(['service', 'availability']);
});
