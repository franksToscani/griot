<?php

use App\Models\Booking;
use App\Models\Provider;
use App\Models\User;
use App\Policies\BookingPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

function bplBooking(): Booking
{
    return Booking::factory()->create();
}

/**
 * The queries run while $run runs.
 */
function bplQueries(Closure $run): int
{
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $run();

    return $queries;
}

test('Laravel finds the policy by its name, without registering it', function () {
    expect(Gate::getPolicyFor(Booking::class))->toBeInstanceOf(BookingPolicy::class);
});

test('the provider of the booking can manage it', function () {
    $booking = bplBooking();

    expect(Gate::forUser($booking->provider->user)->allows('manage', $booking))->toBeTrue();
});

test('another provider cannot, and the refusal is an authorization error (403)', function () {
    $booking = bplBooking();
    $other = Provider::factory()->create();

    expect(Gate::forUser($other->user)->allows('manage', $booking))->toBeFalse();
    expect(fn () => Gate::forUser($other->user)->authorize('manage', $booking))->toThrow(AuthorizationException::class);
});

test('a user without a provider cannot, without errors', function () {
    expect(Gate::forUser(User::factory()->create())->allows('manage', bplBooking()))->toBeFalse();
});

test('queries of the policy: none as the controllers call it, one on its own', function () {
    $booking = bplBooking();
    $ownerId = $booking->provider->user_id;

    // As in the controllers: the user's provider already loaded. The booking's provider is never
    // loaded: the policy reads its provider_id column.
    $user = User::query()->findOrFail($ownerId)->load('provider');
    $loaded = Booking::query()->findOrFail($booking->id);
    expect(bplQueries(fn () => Gate::forUser($user)->allows('manage', $loaded)))->toBe(0);
    expect($loaded->relationLoaded('provider'))->toBeFalse();

    $freshUser = User::query()->findOrFail($ownerId);
    expect(bplQueries(fn () => Gate::forUser($freshUser)->allows('manage', $loaded)))->toBe(1);
});
