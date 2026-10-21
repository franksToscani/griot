<?php

use App\Models\Availability;
use App\Models\Provider;
use App\Models\Service;
use App\Profile\CannotPublish;
use App\Profile\PublishProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00', 'UTC'));
});

/**
 * An unpublished provider with an active service and an availability, unless told otherwise.
 */
function pppProvider(bool $complete = true, bool $published = false): Provider
{
    $provider = Provider::factory()->create(['name' => 'Salone Awa', 'city' => 'Bologna', 'is_published' => $published]);

    if ($complete) {
        Service::factory()->for($provider)->create();
        Availability::factory()->for($provider)->create();
    }

    return $provider;
}

function pppPublisher(): PublishProvider
{
    return app(PublishProvider::class);
}

test('a complete provider is published', function () {
    $provider = pppProvider();

    pppPublisher()->publish($provider);

    expect($provider->fresh()->is_published)->toBeTrue();
});

test('publishing an already published provider writes nothing and throws nothing', function () {
    $provider = pppProvider(published: true);
    $updatedAt = $provider->fresh()->updated_at->toIso8601String();
    $this->travel(1)->hour();

    pppPublisher()->publish($provider);

    expect($provider->fresh()->updated_at->toIso8601String())->toBe($updatedAt);
});

test('an incomplete provider throws CannotPublish with the missing codes and stays unpublished', function () {
    $provider = pppProvider(complete: false);

    try {
        pppPublisher()->publish($provider);
        $this->fail('CannotPublish was not thrown.');
    } catch (CannotPublish $exception) {
        expect($exception->missing())->toBe(['service', 'availability']);
    }

    expect($provider->fresh()->is_published)->toBeFalse();
});

test('an old model: the last service deactivated in the database blocks the publication', function () {
    $provider = pppProvider();
    DB::table('services')->where('provider_id', $provider->id)->update(['is_active' => false]);

    // The services are always read from the database, so this blocks with or without the locked
    // read: the two tests below are the ones that depend on it.
    expect(fn () => pppPublisher()->publish($provider))->toThrow(CannotPublish::class);
    expect($provider->fresh()->is_published)->toBeFalse();
});

test('an old model: a name emptied in the database blocks the publication, the row decides', function () {
    $provider = pppProvider();
    DB::table('providers')->where('id', $provider->id)->update(['name' => '   ']);
    // The model in memory still has a valid name.
    expect($provider->name)->toBe('Salone Awa');

    expect(fn () => pppPublisher()->publish($provider))->toThrow(CannotPublish::class);
    expect($provider->fresh()->is_published)->toBeFalse();
});

test('an old model that says published, while the row is not: the row decides and it is published', function () {
    $provider = pppProvider();
    // The model in memory says published; the row in the database does not.
    $provider->is_published = true;

    pppPublisher()->publish($provider);

    expect(DB::table('providers')->where('id', $provider->id)->value('is_published'))->toBe(1);
});

test('unpublish works without requirements and twice in a row', function () {
    $provider = pppProvider(complete: false, published: true);

    pppPublisher()->unpublish($provider);
    expect($provider->fresh()->is_published)->toBeFalse();

    $updatedAt = $provider->fresh()->updated_at->toIso8601String();
    $this->travel(1)->hour();
    pppPublisher()->unpublish($provider);
    expect($provider->fresh()->updated_at->toIso8601String())->toBe($updatedAt);
});

test('CannotPublish has a technical message with codes only', function () {
    $provider = pppProvider(complete: false);

    $exception = CannotPublish::because($provider, ['service', 'availability']);

    expect($exception->getMessage())->toBe("Provider {$provider->id} cannot be published, missing: service, availability.");
    expect($exception->getMessage())->not->toContain('Salone Awa')->not->toContain('Bologna');
    expect($exception->missing())->toBe(['service', 'availability']);
});
