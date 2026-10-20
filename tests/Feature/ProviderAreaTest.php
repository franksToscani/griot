<?php

use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    app()->setLocale('it');
});

/**
 * A verified user with a provider of their own, with the given provider fields replaced.
 *
 * @param  array<string, mixed>  $override
 */
function paProvider(array $override = []): Provider
{
    return Provider::factory()->create([
        'name' => 'Salone Awa',
        'city' => 'Bologna',
        'slug' => 'salone-awa',
        'avatar_path' => null,
        ...$override,
    ]);
}

test('guests are sent to the login page', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['dashboard', 'provider.setup']);

test('a user with an unverified email is sent to the verification page', function () {
    $user = User::factory()->unverified()->create();
    paProvider(['user_id' => $user->id]);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

test('a user with a provider sees the dashboard with exactly the five fields', function () {
    $provider = paProvider(['is_published' => true]);

    $this->actingAs($provider->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            // where() with the whole array fails if a key is missing or one more is there.
            ->where('provider', [
                'name' => 'Salone Awa',
                'city' => 'Bologna',
                'slug' => 'salone-awa',
                'is_published' => true,
                'avatar_url' => null,
            ]));
});

test('a user without a provider is sent from the dashboard to the setup page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('provider.setup'));
});

test('a user without a provider sees the setup page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('provider.setup'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('provider/setup'));
});

test('a user with a provider is sent from the setup page to the dashboard', function () {
    $this->actingAs(paProvider()->user)
        ->get(route('provider.setup'))
        ->assertRedirect(route('dashboard'));
});

test('each user sees only their own provider', function () {
    $first = paProvider(['name' => 'Salone Awa', 'slug' => 'salone-awa']);
    $second = paProvider(['name' => 'Barberia Kofi', 'slug' => 'barberia-kofi']);

    $this->actingAs($first->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('provider.name', 'Salone Awa'))
        ->assertDontSee('Barberia Kofi');

    $this->actingAs($second->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('provider.name', 'Barberia Kofi'))
        ->assertDontSee('Salone Awa');
});

test('the dashboard shows whether the profile is published', function (bool $published) {
    $provider = paProvider(['is_published' => $published]);

    $this->actingAs($provider->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('provider.is_published', $published));
})->with([
    'unpublished' => [false],
    'published' => [true],
]);

test('the area addresses have no parameters', function () {
    expect(route('dashboard', absolute: false))->toBe('/dashboard');
    expect(route('provider.setup', absolute: false))->toBe('/provider/setup');
});

test('the number of queries does not change with or without an avatar', function () {
    $withoutAvatar = paProvider(['slug' => 'senza-foto']);
    $withAvatar = paProvider(['slug' => 'con-foto', 'avatar_path' => 'avatars/salone.jpg']);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs($withoutAvatar->user)->get(route('dashboard'))->assertOk();
    $countWithout = $queries;

    $queries = 0;
    $this->actingAs($withAvatar->user)->get(route('dashboard'))->assertOk();

    expect($queries)->toBe($countWithout);
});

test('avatar_url is the photo address, or null without a photo', function () {
    $withAvatar = paProvider(['slug' => 'con-foto', 'avatar_path' => 'avatars/salone.jpg']);
    $withoutAvatar = paProvider(['slug' => 'senza-foto']);

    $this->actingAs($withAvatar->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('provider.avatar_url', Storage::disk('public')->url('avatars/salone.jpg')));

    $this->actingAs($withoutAvatar->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('provider.avatar_url', null));
});

test('the Italian texts are exactly the expected ones', function () {
    expect(__('provider.area.title'))->toBe('La tua area');
    expect(__('provider.area.welcome'))->toBe('Ciao :name');
    expect(__('provider.area.status'))->toBe('Stato del profilo');
    expect(__('provider.area.status_published'))->toBe("Pubblicato: i clienti ti trovano nell'elenco.");
    expect(__('provider.area.status_unpublished'))->toBe('Non pubblicato: i clienti non ti vedono ancora.');
    expect(__('provider.area.public_page'))->toBe('Vedi la tua pagina pubblica');
    expect(__('provider.setup.title'))->toBe('Completa il tuo profilo');
    expect(__('provider.setup.description'))->toBe('Il tuo account non ha ancora un profilo professionale. Senza, i clienti non possono trovarti sul sito.');
});

test('a user with a provider logs in and lands on the dashboard', function () {
    $provider = paProvider();

    $this->post(route('login.store'), [
        'email' => $provider->user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dashboard'));
});
