<?php

use App\Models\Availability;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Profile\CreateProviderProfile;
use App\Profile\ProfileDetails;
use App\Profile\PublishingRequirements;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    app()->setLocale('it');
});

/**
 * A provider of its own user, complete (an active service and an availability) unless told otherwise.
 */
function ppbProvider(bool $complete = true, bool $published = false, string $name = 'Salone Awa'): Provider
{
    $provider = Provider::factory()->create(['name' => $name, 'city' => 'Bologna', 'is_published' => $published]);

    if ($complete) {
        Service::factory()->for($provider)->create();
        Availability::factory()->for($provider)->create();
    }

    return $provider;
}

/**
 * Forget the logged-in user, for the checks a customer makes on the public pages. actingAs() keeps
 * the same User object across requests, and HandleInertiaRequests shares it as auth.user: with
 * its provider relation loaded, the provider's name would show on every page (a known debt).
 */
function ppbAsGuest(): void
{
    app('auth')->forgetGuards();
}

/**
 * @param  array<string, mixed>  $data
 */
function ppbPost(User $user, string $route, array $data = []): TestResponse
{
    return test()->actingAs($user)->post(route($route), $data);
}

test('guests, unverified users and users without a provider are sent away', function (string $route) {
    $this->post(route($route))->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    Provider::factory()->create(['user_id' => $unverified->id]);
    ppbPost($unverified, $route)->assertRedirect(route('verification.notice'));

    ppbPost(User::factory()->create(), $route)->assertRedirect(route('provider.setup'));
})->with(['provider.publish', 'provider.unpublish']);

test('a complete provider is published and becomes public', function () {
    $provider = ppbProvider(name: 'Salone Pubblicato');

    ppbPost($provider->user, 'provider.publish')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Il tuo profilo è pubblicato: i clienti ora ti trovano.']);

    expect($provider->fresh()->is_published)->toBeTrue();
    // As a customer: a guest, without the logged-in user (see ppbAsGuest()).
    ppbAsGuest();
    $this->get(route('providers.show', $provider->slug))->assertOk();
    $this->get(route('providers.index'))->assertOk()->assertSee('Salone Pubblicato');
});

test('an incomplete provider gets the blocked message on the dashboard and stays unpublished', function () {
    $provider = ppbProvider(complete: false);

    // Sent from another page: the redirect goes to the dashboard anyway, not back().
    $this->actingAs($provider->user)
        ->from(route('provider.edit'))
        ->post(route('provider.publish'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors(['publish' => 'Non puoi ancora pubblicare il profilo: mancano alcune cose.'])
        ->assertInertiaFlashMissing('toast');

    expect($provider->fresh()->is_published)->toBeFalse();
});

test('unpublishing hides the provider again', function () {
    $provider = ppbProvider(published: true, name: 'Salone Da Togliere');

    ppbPost($provider->user, 'provider.unpublish')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Il tuo profilo non è più visibile ai clienti.']);

    expect($provider->fresh()->is_published)->toBeFalse();
    ppbAsGuest();
    $this->get(route('providers.show', $provider->slug))->assertNotFound();
    $this->get(route('providers.index'))->assertOk()->assertDontSee('Salone Da Togliere');
});

test('the body of the request is ignored', function () {
    $provider = ppbProvider();
    $other = ppbProvider(name: 'Barberia Kofi');
    $otherBefore = $other->fresh()->only(['is_published', 'slug', 'user_id', 'name']);

    ppbPost($provider->user, 'provider.publish', [
        'is_published' => '0',
        'slug' => 'cambiato',
        'provider_id' => $other->id,
        'user_id' => $other->user_id,
        'id' => $other->id,
    ])->assertSessionHasNoErrors();

    expect($provider->fresh()->is_published)->toBeTrue();
    expect($provider->fresh()->slug)->toBe($provider->slug);
    expect($other->fresh()->only(['is_published', 'slug', 'user_id', 'name']))->toBe($otherBefore);
});

test('publishing for user A does not touch the provider of user B', function () {
    $first = ppbProvider();
    $second = ppbProvider(name: 'Barberia Kofi');

    ppbPost($first->user, 'provider.publish')->assertSessionHasNoErrors();

    expect($first->fresh()->is_published)->toBeTrue();
    expect($second->fresh()->is_published)->toBeFalse();
});

test('a GET cannot publish or unpublish', function (string $route, bool $published) {
    $provider = ppbProvider(published: $published);

    $this->actingAs($provider->user)->get(route($route))->assertStatus(405);

    expect($provider->fresh()->is_published)->toBe($published);
})->with([
    'publish' => ['provider.publish', false],
    'unpublish' => ['provider.unpublish', true],
]);

test('the 11th publish or unpublish in a minute of the same user is refused', function () {
    $provider = ppbProvider();

    // Publish and unpublish count together: five of each.
    for ($i = 1; $i <= 5; $i++) {
        ppbPost($provider->user, 'provider.publish')->assertSessionHasNoErrors();
        ppbPost($provider->user, 'provider.unpublish')->assertSessionHasNoErrors();
    }

    ppbPost($provider->user, 'provider.publish')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors(['publish' => 'Hai fatto troppe richieste in poco tempo. Riprova tra qualche minuto.']);
    expect($provider->fresh()->is_published)->toBeFalse();

    // Another user is not limited.
    $other = ppbProvider(name: 'Barberia Kofi');
    ppbPost($other->user, 'provider.publish')->assertSessionHasNoErrors();
    expect($other->fresh()->is_published)->toBeTrue();
});

test('the dashboard tells what is missing, and still gets five provider fields', function (bool $complete, bool $canPublish, array $missing) {
    $provider = ppbProvider(complete: $complete);

    $this->actingAs($provider->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('publishing', ['can_publish' => $canPublish, 'missing' => $missing])
            ->has('provider', 5)
            ->has('provider', fn (Assert $fields) => $fields->hasAll(['name', 'city', 'slug', 'is_published', 'avatar_url'])));
})->with([
    'incomplete' => [false, false, ['service', 'availability']],
    'complete' => [true, true, []],
]);

test('the number of queries of the dashboard does not grow with services and availabilities', function () {
    $few = ppbProvider();
    $many = ppbProvider(name: 'Barberia Kofi');
    Service::factory()->count(5)->for($many)->create();
    Availability::factory()->count(5)->for($many)->create();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs($few->user)->get(route('dashboard'))->assertOk();
    $countFew = $queries;

    $queries = 0;
    $this->actingAs($many->user)->get(route('dashboard'))->assertOk();

    expect($queries)->toBe($countFew);
});

test('a new profile is not published nor publishable until it has a service and an availability', function () {
    $user = User::factory()->create();
    $provider = app(CreateProviderProfile::class)->create($user, new ProfileDetails('Salone Nuovo', 'Parma'));

    expect($provider->fresh()->is_published)->toBeFalse();
    expect(app(PublishingRequirements::class)->missing($provider))->toBe(['service', 'availability']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('publishing', ['can_publish' => false, 'missing' => ['service', 'availability']]));

    ppbPost($user, 'provider.publish')->assertSessionHasErrors('publish');
    expect($provider->fresh()->is_published)->toBeFalse();
});

test('the Italian texts are exactly the expected ones', function () {
    expect(__('provider.publish.published'))->toBe('Il tuo profilo è pubblicato: i clienti ora ti trovano.');
    expect(__('provider.publish.unpublished'))->toBe('Il tuo profilo non è più visibile ai clienti.');
    expect(__('provider.publish.blocked'))->toBe('Non puoi ancora pubblicare il profilo: mancano alcune cose.');
    expect(__('provider.publish.title'))->toBe('Pubblicazione');
    expect(__('provider.publish.missing_title'))->toBe('Per pubblicare ti manca ancora:');
    expect(__('provider.publish.missing_name'))->toBe('Il nome della tua attività');
    expect(__('provider.publish.missing_city'))->toBe('La tua città');
    expect(__('provider.publish.missing_service'))->toBe('Almeno un servizio attivo');
    expect(__('provider.publish.missing_availability'))->toBe('Almeno una fascia di orario in cui lavori');
    expect(__('provider.publish.ready'))->toBe('Il tuo profilo ha tutto il necessario: puoi pubblicarlo.');
    expect(__('provider.publish.submit'))->toBe('Pubblica il profilo');
    expect(__('provider.publish.unpublish'))->toBe('Togli dalla pubblicazione');
    expect(__('provider.publish.unpublish_title'))->toBe('Vuoi togliere il profilo dalla pubblicazione?');
    expect(__('provider.publish.unpublish_description'))->toBe('I clienti non ti troveranno più nell\'elenco e la tua pagina pubblica non sarà raggiungibile. Potrai pubblicarlo di nuovo.');
    expect(__('provider.publish.unpublish_confirm'))->toBe('Sì, toglilo');
    expect(__('provider.publish.unpublish_cancel'))->toBe('No, lascialo');
});

test('in the seeder the published providers meet the requirements and atelier-wax is not published', function () {
    $this->seed(DatabaseSeeder::class);
    $requirements = app(PublishingRequirements::class);

    $state = Provider::query()->orderBy('slug')->get()
        ->mapWithKeys(fn (Provider $provider) => [$provider->slug => [$provider->is_published, $requirements->missing($provider)]])
        ->all();

    expect($state)->toBe([
        'atelier-wax' => [false, []],
        'barberia-lagos' => [true, []],
        'trecce-sahel' => [true, []],
        'unghie-kente' => [true, []],
    ]);
});
