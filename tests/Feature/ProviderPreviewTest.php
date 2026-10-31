<?php

use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    app()->setLocale('it');
});

/**
 * A provider with an active service (a visible and a hidden option), an inactive service and a
 * slot on Monday.
 */
function ppvProvider(bool $published, string $name = 'Atelier Wax'): Provider
{
    $provider = Provider::factory()->create(['name' => $name, 'is_published' => $published]);
    $category = Category::factory()->create();
    $active = Service::factory()->for($provider)->for($category)->create(['name' => "Servizio attivo di {$name}"]);
    ServiceOption::factory()->for($active)->create(['name' => "Opzione visibile di {$name}"]);
    ServiceOption::factory()->for($active)->inactive()->create(['name' => "Opzione nascosta di {$name}"]);
    Service::factory()->inactive()->for($provider)->for($category)->create(['name' => "Servizio disattivato di {$name}"]);
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '10:00:00',
        'end_time' => '17:00:00',
    ]);

    return $provider;
}

/**
 * The props of a page, read from the response.
 *
 * @return array<string, mixed>
 */
function ppvProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $page->component('providers/show');
        $props = $page->toArray()['props'];
    });

    return $props;
}

test('guests, unverified users and users without a provider are sent away', function () {
    $this->get(route('provider.preview'))->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    Provider::factory()->create(['user_id' => $unverified->id]);
    $this->actingAs($unverified)->get(route('provider.preview'))->assertRedirect(route('verification.notice'));

    $this->actingAs(User::factory()->create())->get(route('provider.preview'))->assertRedirect(route('provider.setup'));
});

test('an unpublished provider sees its own page, without the inactive service and the hidden option', function () {
    $provider = ppvProvider(published: false);
    // The keys of the public page, from a published provider.
    $publicKeys = array_keys(ppvProps($this->get(route('providers.show', ppvProvider(true, 'Barberia Kofi')->slug)))['provider']);

    $response = $this->actingAs($provider->user)->get(route('provider.preview'));
    $props = ppvProps($response);

    expect($props['preview'])->toBeTrue();
    expect(array_keys($props['provider']))->toBe($publicKeys);
    expect($props['provider']['name'])->toBe('Atelier Wax');

    $services = $props['provider']['service_groups'][0]['services'];
    expect(array_column($services, 'name'))->toBe(['Servizio attivo di Atelier Wax']);
    expect(array_column($services[0]['options'], 'name'))->toBe(['Opzione visibile di Atelier Wax']);
    expect($props['provider']['opening_hours'][0]['slots'])->toBe([['start' => '10:00', 'end' => '17:00']]);

    $response->assertDontSee('Servizio disattivato di Atelier Wax')
        ->assertDontSee('Opzione nascosta di Atelier Wax');
});

test('a published provider sees exactly the data of its public page', function () {
    $provider = ppvProvider(published: true);

    $public = ppvProps($this->get(route('providers.show', $provider->slug)));
    $preview = ppvProps($this->actingAs($provider->user)->get(route('provider.preview')));

    expect($public['preview'])->toBeFalse();
    expect($preview['preview'])->toBeTrue();
    expect($preview['provider'])->toBe($public['provider']);
    expect($preview['bookingWindow'])->toBe($public['bookingWindow']);
});

test('each user always sees their own preview, never another one', function () {
    $mine = ppvProvider(false, 'Atelier Wax');
    $theirs = ppvProvider(false, 'Barberia Kofi');

    $this->actingAs($mine->user)->get(route('provider.preview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('provider.name', 'Atelier Wax')->where('provider.slug', $mine->slug))
        ->assertDontSee('Barberia Kofi');

    // A query string cannot choose another provider: there is nothing to read it.
    $this->actingAs($theirs->user)->get(route('provider.preview', ['slug' => $mine->slug, 'provider' => $mine->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('provider.name', 'Barberia Kofi'))
        ->assertDontSee('Atelier Wax');
});

test('the public page of an unpublished provider stays a 404, even for its owner, and the list skips it', function () {
    $provider = ppvProvider(published: false);

    $this->get(route('providers.show', $provider->slug))->assertNotFound();
    $this->actingAs($provider->user)->get(route('providers.show', $provider->slug))->assertNotFound();

    $this->get(route('providers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('providers.data', []));
});

test('the preview is neither cached nor indexed; the public page has no such headers', function () {
    $provider = ppvProvider(published: true);

    $preview = $this->actingAs($provider->user)->get(route('provider.preview'))->assertOk();
    expect($preview->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
    expect($preview->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    // Only the two headers of PrivateProviderPage, not the booking page's Referrer-Policy.
    expect($preview->headers->has('Referrer-Policy'))->toBeFalse();

    app('auth')->forgetGuards();
    $public = $this->get(route('providers.show', $provider->slug))->assertOk();
    expect($public->headers->has('X-Robots-Tag'))->toBeFalse();
    expect($public->headers->get('Cache-Control'))->not->toContain('no-store');
});

test('the address has no parameters, and other methods are not allowed', function () {
    $provider = ppvProvider(published: false);

    expect(route('provider.preview'))->toBe(url('/provider/preview'));
    $this->actingAs($provider->user)->get('/provider/preview/'.$provider->id)->assertNotFound();

    $this->actingAs($provider->user)->post('/provider/preview')->assertMethodNotAllowed();
    $this->actingAs($provider->user)->put('/provider/preview')->assertMethodNotAllowed();
    $this->actingAs($provider->user)->delete('/provider/preview')->assertMethodNotAllowed();
});

test('the number of queries does not grow with services and options', function () {
    // A provider each: actingAs() keeps the same User object across requests of one user.
    $count = function (int $services, int $options) {
        $provider = Provider::factory()->create(['name' => "Salone {$services} {$options}"]);
        $category = Category::factory()->create();

        for ($i = 0; $i < $services; $i++) {
            $service = Service::factory()->for($provider)->for($category)->create();
            ServiceOption::factory()->count($options)->for($service)->create();
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->actingAs($provider->user)->get(route('provider.preview'))->assertOk();

        return $queries;
    };

    $base = $count(1, 1);

    expect($count(5, 1))->toBe($base);
    expect($count(1, 5))->toBe($base);
    expect($count(5, 5))->toBe($base);
});

test('the booking form is mounted only on the real page, not in the preview', function () {
    // Read in the page source: there is no JavaScript test runner.
    $source = file_get_contents(resource_path('js/pages/providers/show.tsx'));

    // One BookingSheet only, and it is the "else" branch of the preview check, right after the
    // text that replaces it.
    expect(substr_count($source, '<BookingSheet'))->toBe(1);
    expect($source)->toMatch(
        "/\\{preview \\? \\(\\s*<p[^>]*>\\s*\\{t\\('provider\\.preview\\.no_booking'\\)\\}\\s*<\\/p>\\s*\\) : \\(\\s*<BookingSheet\\b/",
    );
});

test('the texts of the preview are exactly the expected ones', function () {
    $texts = [
        'provider.preview.banner' => 'Anteprima: così ti vedono i clienti.',
        // "Il mio pannello", as the menu and the breadcrumbs call the dashboard (issue #54).
        'provider.preview.back' => 'Torna al mio pannello',
        'provider.preview.no_booking' => 'In anteprima non si può prenotare.',
        'provider.area.preview' => "Vedi l'anteprima",
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});
