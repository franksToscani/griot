<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\TranslationsProp;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

// Issue #178: the translations are an Inertia "once" prop (App\Http\TranslationsProp). The browser
// gets them on the first visit and keeps them; the next visits name their key in
// X-Inertia-Except-Once-Props and the server leaves them out. Another language or other texts give
// another key, and the translations come again.

beforeEach(function () {
    app()->setLocale('it');
});

/**
 * The key of a language: "translations.{locale}.{16 hex of the file}".
 */
function toKey(string $locale): string
{
    return 'translations.'.$locale.'.'.substr((string) hash_file('xxh128', lang_path("{$locale}.json")), 0, 16);
}

/**
 * An Inertia visit (a link or a form of the site), saying which once props the browser has.
 *
 * @param  list<string>  $kept
 * @param  array<string, string>  $cookies
 */
function toVisit(string $url, array $kept = [], array $cookies = []): TestResponse
{
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(Request::create('/')),
        'X-Requested-With' => 'XMLHttpRequest',
    ];

    if ($kept !== []) {
        $headers['X-Inertia-Except-Once-Props'] = implode(',', $kept);
    }

    $test = test();
    foreach ($cookies as $name => $value) {
        $test = $test->withCookie($name, $value);
    }

    return $test->get($url, $headers)->assertOk();
}

test('the first visit gets the translations, with the key of its language', function () {
    $response = toVisit(route('providers.index'));

    expect($response->json('props.translations')['providers.list.title'])->toBe('Trova un professionista')
        ->and($response->json('onceProps'))->toBe([toKey('it') => ['prop' => 'translations', 'expiresAt' => null]]);
});

test('a page loaded in full has them too, with their key', function () {
    $this->get(route('home'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('translations', fn ($translations) => $translations['home.title'] === __('home.title', [], 'it')));
});

test('the next visits with the key kept do not get them again, and keep the key', function () {
    $response = toVisit(route('privacy'), [toKey('it')]);

    expect($response->json('props'))->not->toHaveKey('translations')
        ->and($response->json('onceProps'))->toHaveKey(toKey('it'))
        // The other shared props still come at every visit.
        ->and($response->json('props.locale'))->toBe('it');
});

test('another language gives another key: the translations come again', function () {
    $response = toVisit(route('providers.index'), [toKey('it')], ['locale' => 'fr']);

    expect($response->json('props.translations')['providers.list.title'])->toBe('Trouver un professionnel')
        ->and($response->json('onceProps'))->toHaveKey(toKey('fr'))
        ->and($response->json('onceProps'))->not->toHaveKey(toKey('it'));
});

test('back to the first language after another one: the right texts again', function () {
    // The browser kept French last: it names the French key only.
    $response = toVisit(route('providers.index'), [toKey('fr')], ['locale' => 'it']);

    expect($response->json('props.translations')['providers.list.title'])->toBe('Trova un professionista');
});

test('the private page of a booking uses the key of the booking\'s language, set after the middleware', function () {
    $booking = Booking::factory()->create(['locale' => 'en']);

    // No language chosen (a cookie would win): the request starts in Italian, the page is in the
    // booking's English. The browser has the Italian texts: they come again, in English.
    $response = toVisit(route('bookings.show', $booking->public_token), [toKey('it')]);

    expect($response->json('props.locale'))->toBe('en')
        ->and($response->json('props.translations')['booking.confirmation.title'])->toBe(__('booking.confirmation.title', [], 'en'))
        ->and($response->json('onceProps'))->toHaveKey(toKey('en'));
});

test('the key changes with the texts: the hash is of the file\'s content', function () {
    app()->setLocale('fr');
    $key = (new TranslationsProp)->getKey();

    expect($key)->toBe(toKey('fr'))
        ->and($key)->toMatch('/^translations\.fr\.[0-9a-f]{16}$/')
        ->and(toKey('fr'))->not->toBe(toKey('it'));
});

test('a partial reload never sends them, as before', function () {
    $response = test()->get(route('providers.index'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'providers/index',
        'X-Inertia-Partial-Data' => 'providers',
    ])->assertOk();

    expect($response->json('props'))->not->toHaveKey('translations');
});

test('the middleware shares them once, and the browser reads them from the props as before', function () {
    $middleware = (string) file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php'));
    $prop = (string) file_get_contents(app_path('Http/TranslationsProp.php'));

    expect($middleware)->toContain("'translations' => new TranslationsProp,")
        ->and($prop)->toContain('final class TranslationsProp extends OnceProp')
        ->and($prop)->toMatch("/return 'translations\\.'\\.app\\(\\)->getLocale\\(\\)\\.'\\.'\\.self::hash\\(\\);/");

    // t() and pageText() read the translations of the page's props, which Inertia fills from the
    // kept ones before "navigate" and before "success" (setPage merges them, then the event: Inertia
    // 3.9.1). document-language.ts follows both events since the audit #175.
    expect((string) file_get_contents(resource_path('js/hooks/use-translation.ts')))->toContain('const { locale, translations } = usePage().props;');
    expect((string) file_get_contents(resource_path('js/lib/document-language.ts')))
        ->toContain('const { locale, translations } = page.props;')
        ->toContain("router.on('success', (event) => follow(event.detail.page));");
});
