<?php

use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\QueryException;

test('a provider can be created with the factory', function () {
    $provider = Provider::factory()->create();

    expect($provider->exists)->toBeTrue();
    $this->assertDatabaseHas('providers', ['slug' => $provider->slug]);
});

test('a provider is not published by default', function () {
    $user = User::factory()->create();

    $provider = $user->provider()->create([
        'slug' => 'beni-hair-design',
        'name' => 'Beni Hair Design',
        'city' => 'Bologna',
    ]);

    expect($provider->refresh()->is_published)->toBeFalse();
});

test('a user can have only one provider', function () {
    $user = User::factory()->create();
    Provider::factory()->for($user)->create();

    expect(fn () => Provider::factory()->for($user)->create())
        ->toThrow(QueryException::class);
});

test('provider slugs are unique', function () {
    Provider::factory()->create(['slug' => 'beni-hair-design']);

    expect(fn () => Provider::factory()->create(['slug' => 'beni-hair-design']))
        ->toThrow(QueryException::class);
});

test('a provider belongs to a user', function () {
    $user = User::factory()->create();
    $provider = Provider::factory()->for($user)->create();

    expect($provider->user->is($user))->toBeTrue();
});

test('a user has one provider', function () {
    $provider = Provider::factory()->create();
    $user = $provider->user;

    expect($user->provider->is($provider))->toBeTrue();
});

test('deleting a user deletes their provider', function () {
    $provider = Provider::factory()->create();

    $provider->user->delete();

    $this->assertModelMissing($provider);
});
