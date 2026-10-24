<?php

use App\Models\Availability;
use App\Models\Provider;
use App\Models\User;
use App\Policies\AvailabilityPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

function aplSlot(): Availability
{
    return Availability::factory()->for(Provider::factory())->create();
}

/**
 * The queries run while $run runs.
 */
function aplQueries(Closure $run): int
{
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $run();

    return $queries;
}

test('Laravel finds the policy by its name, without registering it', function () {
    expect(Gate::getPolicyFor(Availability::class))->toBeInstanceOf(AvailabilityPolicy::class);
});

test('the owner can update and delete its slot', function (string $ability) {
    $slot = aplSlot();

    expect(Gate::forUser($slot->provider->user)->allows($ability, $slot))->toBeTrue();
})->with(['update', 'delete']);

test('another provider cannot, and the refusal is an authorization error (403)', function (string $ability) {
    $slot = aplSlot();
    $other = Provider::factory()->create();

    expect(Gate::forUser($other->user)->allows($ability, $slot))->toBeFalse();
    expect(fn () => Gate::forUser($other->user)->authorize($ability, $slot))->toThrow(AuthorizationException::class);
})->with(['update', 'delete']);

test('a user without a provider cannot, without errors', function (string $ability) {
    expect(Gate::forUser(User::factory()->create())->allows($ability, aplSlot()))->toBeFalse();
})->with(['update', 'delete']);

test('queries of the policy: none as the controllers call it, one on its own', function () {
    $slot = aplSlot();
    $ownerId = $slot->provider->user_id;

    // As in the controllers: the user's provider already loaded. The slot's provider is never
    // loaded: the policy reads its provider_id column.
    $user = User::query()->findOrFail($ownerId)->load('provider');
    $loaded = Availability::query()->findOrFail($slot->id);
    expect(aplQueries(fn () => Gate::forUser($user)->allows('update', $loaded)))->toBe(0);
    expect($loaded->relationLoaded('provider'))->toBeFalse();

    // On its own: only $user->provider, one query.
    $freshUser = User::query()->findOrFail($ownerId);
    expect(aplQueries(fn () => Gate::forUser($freshUser)->allows('delete', $loaded)))->toBe(1);
});
