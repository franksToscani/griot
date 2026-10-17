<?php

use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Provider;

test('an availability can be created with the factory', function () {
    $availability = Availability::factory()->create();

    expect($availability->exists)->toBeTrue();
    expect($availability->end_time > $availability->start_time)->toBeTrue();
    $this->assertDatabaseHas('availabilities', ['id' => $availability->id]);
});

test('the weekday is stored as an ISO number and read back as an enum', function () {
    $provider = Provider::factory()->create();

    $availability = $provider->availabilities()->create([
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);

    $this->assertDatabaseHas('availabilities', ['id' => $availability->id, 'weekday' => 2]);
    expect($availability->refresh()->weekday)->toBe(Weekday::Tuesday);
});

test('an availability keeps its local times', function () {
    $availability = Availability::factory()->create([
        'start_time' => '09:30:00',
        'end_time' => '18:00:00',
    ]);

    $availability->refresh();
    expect($availability->start_time)->toBe('09:30:00');
    expect($availability->end_time)->toBe('18:00:00');
});

test('an availability belongs to a provider', function () {
    $provider = Provider::factory()->create();
    $availability = Availability::factory()->for($provider)->create();

    expect($availability->provider->is($provider))->toBeTrue();
});

test('a provider has many availabilities', function () {
    $provider = Provider::factory()->create();
    Availability::factory()->count(2)->for($provider)->create();

    expect($provider->availabilities)->toHaveCount(2);
});

test('deleting a provider deletes their availabilities', function () {
    $availability = Availability::factory()->create();

    $availability->provider->delete();

    $this->assertModelMissing($availability);
});
