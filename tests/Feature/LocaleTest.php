<?php

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// The pages are read on the providers' list: "/" only redirects there since issue #54 (phase 1b).

// Italian and French are open to everyone (config/app.php): the tests use the configuration as it is.

/**
 * The shared props of a page, read with assertInertia().
 *
 * @return array<string, mixed>
 */
function locProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

// ---------------------------------------------------------------------------------------------
// Middleware
// ---------------------------------------------------------------------------------------------

test('without a choice the site is in Italian, with the Italian translations', function () {
    $props = locProps($this->get(route('providers.index')));

    expect($props['locale'])->toBe('it')
        ->and($props['translations'])->toHaveKey('providers.list.title', 'Trova un professionista');
});

test('the browser language is never read: the site starts in Italian', function () {
    $response = $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')->get(route('providers.index'));

    expect(locProps($response)['locale'])->toBe('it');
    $response->assertSee('<html lang="it"', false);
});

test('the language of the cookie is used when it is available', function () {
    $response = $this->withCookie('locale', 'fr')->get(route('providers.index'));

    expect(locProps($response)['locale'])->toBe('fr');
    $response->assertSee('<html lang="fr"', false);
    expect(app()->getLocale())->toBe('fr');
});

test('a cookie with a language that is not available, or not readable, gives Italian', function (string $value, bool $encrypted) {
    // Italian and French are open: English is known but not open, nor written yet.
    $test = $encrypted ? $this->withCookie('locale', $value) : $this->withUnencryptedCookie('locale', $value);

    $response = $test->get(route('providers.index'));

    expect(locProps($response)['locale'])->toBe('it');
    $response->assertSee('<html lang="it"', false);
})->with([
    'a language that does not exist' => ['de', true],
    'English, not written yet' => ['en', true],
    'an invalid value' => ['../../etc', true],
    // Written by hand in the browser: the server only trusts the cookies it encrypted.
    'a cookie not encrypted by the server' => ['it', false],
]);

test('the default language is in the available ones', function () {
    expect(config()->array('app.available_locales'))->toHaveKey(config()->string('app.locale'));
});

test('Italian is the fallback language in the config and in the example environment', function () {
    // Read in the files: the local .env may still say otherwise.
    expect((string) file_get_contents(config_path('app.php')))->toContain("'fallback_locale' => env('APP_FALLBACK_LOCALE', 'it'),");
    expect((string) file_get_contents(base_path('.env.example')))
        ->toContain("APP_LOCALE=it\n")
        ->toContain("APP_FALLBACK_LOCALE=it\n");
});

// ---------------------------------------------------------------------------------------------
// The languages shared with React
// ---------------------------------------------------------------------------------------------

test('the pages get the available languages, each with its own name', function () {
    expect(locProps($this->get(route('providers.index')))['locales'])->toBe([
        ['code' => 'it', 'name' => 'Italiano'],
        ['code' => 'fr', 'name' => 'Français'],
    ]);
});

test('the private area gets them too', function () {
    $user = User::factory()->create();

    expect(locProps($this->actingAs($user)->get(route('provider.setup')))['locales'])->toBe([
        ['code' => 'it', 'name' => 'Italiano'],
        ['code' => 'fr', 'name' => 'Français'],
    ]);
});

// ---------------------------------------------------------------------------------------------
// The route
// ---------------------------------------------------------------------------------------------

test('choosing a language writes an encrypted cookie for a year and goes back', function () {
    $this->travelTo(now());

    $response = $this->from('/providers')->post(route('locale.update'), ['locale' => 'fr']);

    $response->assertRedirect('/providers')->assertSessionHasNoErrors();
    // assertCookie() decrypts: the value was encrypted by the server.
    $response->assertCookie('locale', 'fr');
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'locale');
    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getExpiresTime())->toBe(now()->addMinutes(60 * 24 * 365)->getTimestamp());
    expect($cookie->getValue())->not->toBe('fr');
});

test('the next page is in the chosen language', function () {
    $cookie = $this->post(route('locale.update'), ['locale' => 'fr'])->getCookie('locale', decrypt: true);

    expect(locProps($this->withCookie('locale', (string) $cookie?->getValue())->get(route('providers.index')))['locale'])->toBe('fr');
});

test('without a previous page the choice goes back to the home page', function () {
    $this->post(route('locale.update'), ['locale' => 'fr'])->assertRedirect(route('home'));
});

test('a logged-in provider can choose the language too', function () {
    $this->actingAs(User::factory()->create())
        ->from(route('dashboard'))
        ->post(route('locale.update'), ['locale' => 'fr'])
        ->assertRedirect(route('dashboard'))
        ->assertCookie('locale', 'fr');
});

test('a language that is not available is refused with an Italian message, and no cookie', function (array $data, string $message) {
    $response = $this->from('/providers')->post(route('locale.update'), $data);

    $response->assertRedirect('/providers')
        ->assertSessionHasErrors(['locale' => $message])
        ->assertCookieMissing('locale');
})->with([
    'a language that does not exist' => [['locale' => 'de'], 'Il valore scelto per lingua non è valido.'],
    'English, not open yet' => [['locale' => 'en'], 'Il valore scelto per lingua non è valido.'],
    'an array' => [['locale' => ['it']], 'Il campo lingua deve essere un testo.'],
    'missing' => [[], 'Il campo lingua è obbligatorio.'],
]);

test('the route only accepts POST, under the name Wayfinder uses', function () {
    $this->get('/locale')->assertMethodNotAllowed();
    expect(route('locale.update'))->toBe(url('/locale'));
});

// ---------------------------------------------------------------------------------------------
// The selector (read in the source: there is no JavaScript test runner)
// ---------------------------------------------------------------------------------------------

test('the selector hides itself with one language and posts the choice to the route', function () {
    $source = (string) file_get_contents(resource_path('js/components/locale-switcher.tsx'));

    expect($source)
        ->toMatch('/if \(locales\.length <= 1\) \{\s*return null;\s*\}/')
        ->toContain("import { update as updateLocale } from '@/routes/locale';")
        ->toMatch('/router\.post\(\s*updateLocale\.url\(\),\s*\{ locale: event\.target\.value \},/')
        ->toContain("{t('common.locale.label')}")
        // Each language in its own name, from the server: no name written in the component.
        ->toContain('{option.name}');
});

test('the selector is in the public header, in the sidebar and in the login layout', function () {
    foreach (['js/layouts/public-layout.tsx', 'js/components/app-sidebar.tsx', 'js/layouts/auth/auth-simple-layout.tsx'] as $path) {
        $source = (string) file_get_contents(resource_path($path));

        expect($source)
            ->toContain("import { LocaleSwitcher } from '@/components/locale-switcher';")
            ->toMatch('/<LocaleSwitcher\b[^>]*\/>/');
    }
});

test('the Italian text of the selector is exactly the expected one', function () {
    expect(__('common.locale.label'))->toBe('Lingua');
});
