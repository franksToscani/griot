<?php

use App\Models\Provider;
use App\Models\User;
use App\Policies\ProviderPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

test('Laravel finds the policy by its name, without registering it', function () {
    expect(Gate::getPolicyFor(Provider::class))->toBeInstanceOf(ProviderPolicy::class);
});

test('only the owner can update the provider', function () {
    $provider = Provider::factory()->create();
    $other = User::factory()->create();

    expect(Gate::forUser($provider->user)->allows('update', $provider))->toBeTrue();
    expect(Gate::forUser($other)->allows('update', $provider))->toBeFalse();
    expect(fn () => Gate::forUser($other)->authorize('update', $provider))->toThrow(AuthorizationException::class);
});

test('a refused authorization becomes a 403 response', function () {
    $provider = Provider::factory()->create();
    // A route only for this test: the area routes have no identifier, so over HTTP a user can
    // only reach their own provider. Here the policy is checked against someone else's.
    Route::middleware('web')->get('/ppo-policy-check', function () use ($provider) {
        Gate::authorize('update', $provider);

        return 'ok';
    });

    $this->actingAs(User::factory()->create())->get('/ppo-policy-check')->assertForbidden();
    $this->actingAs($provider->user)->get('/ppo-policy-check')->assertOk();
});

test('the user ids compared by the policy are integers', function () {
    $provider = Provider::factory()->create()->fresh();

    expect($provider->user_id)->toBeInt();
    expect($provider->user->id)->toBeInt();
});

test('only the five profile fields are fillable', function () {
    expect((new Provider)->getFillable())->toBe(['name', 'city', 'bio', 'whatsapp', 'instagram']);
});

test('fill() silently drops slug, is_published and avatar_path', function () {
    $provider = (new Provider)->fill([
        'name' => 'Salone Awa',
        'slug' => 'x',
        'is_published' => true,
        'avatar_path' => 'p',
    ]);

    // No exception: the model has no strict mode, the fields are just not set.
    expect($provider->name)->toBe('Salone Awa');
    expect($provider->getAttributes())->not->toHaveKeys(['slug', 'is_published', 'avatar_path']);
});

test('the factory can still set slug and is_published', function () {
    $provider = Provider::factory()->published()->create(['slug' => 'dalla-factory']);

    expect($provider->fresh()->slug)->toBe('dalla-factory');
    expect($provider->fresh()->is_published)->toBeTrue();
});
