<?php

use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Models\User;
use App\Policies\ServiceOptionPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

function sopOption(): ServiceOption
{
    $service = Service::factory()->for(Provider::factory())->create();

    return ServiceOption::factory()->for($service)->create();
}

/**
 * The queries run while $run runs.
 */
function sopQueries(Closure $run): int
{
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $run();

    return $queries;
}

test('Laravel finds the policy by its name, without registering it', function () {
    expect(Gate::getPolicyFor(ServiceOption::class))->toBeInstanceOf(ServiceOptionPolicy::class);
});

test('the owner can update and delete its option', function (string $ability) {
    $option = sopOption();

    expect(Gate::forUser($option->service->provider->user)->allows($ability, $option))->toBeTrue();
})->with(['update', 'delete']);

test('another provider cannot, and the refusal is an authorization error (403)', function (string $ability) {
    $option = sopOption();
    $other = Provider::factory()->create();

    expect(Gate::forUser($other->user)->allows($ability, $option))->toBeFalse();
    expect(fn () => Gate::forUser($other->user)->authorize($ability, $option))->toThrow(AuthorizationException::class);
})->with(['update', 'delete']);

test('a user without a provider cannot, without errors', function (string $ability) {
    expect(Gate::forUser(User::factory()->create())->allows($ability, sopOption()))->toBeFalse();
})->with(['update', 'delete']);

test('queries of the policy: none as the controllers call it, two on its own', function () {
    $option = sopOption();
    $ownerId = $option->service->provider->user_id;

    // As in the controllers: the user's provider already loaded, the option found through its
    // service (setRelation).
    $user = User::query()->findOrFail($ownerId)->load('provider');
    $loaded = ServiceOption::query()->findOrFail($option->id);
    $loaded->setRelation('service', Service::query()->findOrFail($option->service_id));
    expect(sopQueries(fn () => Gate::forUser($user)->allows('update', $loaded)))->toBe(0);

    // On its own, with nothing loaded: $user->provider and $option->service, one query each.
    $freshUser = User::query()->findOrFail($ownerId);
    $freshOption = ServiceOption::query()->findOrFail($option->id);
    expect(sopQueries(fn () => Gate::forUser($freshUser)->allows('update', $freshOption)))->toBe(2);
});
