<?php

use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Policies\ServicePolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

function pspService(): Service
{
    return Service::factory()->for(Provider::factory())->create();
}

test('Laravel finds the policy by its name, without registering it', function () {
    expect(Gate::getPolicyFor(Service::class))->toBeInstanceOf(ServicePolicy::class);
});

test('the owner can update and delete its service', function (string $ability) {
    $service = pspService();

    expect(Gate::forUser($service->provider->user)->allows($ability, $service))->toBeTrue();
})->with(['update', 'delete']);

test('another provider cannot, and the refusal is an authorization error (403)', function (string $ability) {
    $service = pspService();
    $other = Provider::factory()->create();

    expect(Gate::forUser($other->user)->allows($ability, $service))->toBeFalse();
    expect(fn () => Gate::forUser($other->user)->authorize($ability, $service))->toThrow(AuthorizationException::class);
})->with(['update', 'delete']);

test('a user without a provider cannot, without errors', function (string $ability) {
    $service = pspService();

    expect(Gate::forUser(User::factory()->create())->allows($ability, $service))->toBeFalse();
})->with(['update', 'delete']);
