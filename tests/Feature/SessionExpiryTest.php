<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

// Issue #130: a page left open longer than the session (SESSION_LIFETIME, 120 minutes).
// - Laravel 13 (PreventRequestForgery) accepts a send marked by the browser as
//   "Sec-Fetch-Site: same-origin" before comparing the tokens: an expired session gives no 419.
// - For a browser without that header, a 419 is still possible: lib/http-errors.ts asks
//   session.refresh for a new XSRF-TOKEN cookie, then says to send again. Nothing is lost.
// The CSRF check is off in the tests (runningUnitTests()): here a subclass keeps it on.

/**
 * PreventRequestForgery as in production: the check also runs in the tests.
 */
final class SeForgeryAsInProduction extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}

beforeEach(function () {
    app()->setLocale('it');
});

// ---------------------------------------------------------------------------------------------
// The check of Laravel, on
// ---------------------------------------------------------------------------------------------

test('without a token, a send from the same site passes; from another site or without the header it is a 419', function (?string $site, int $status) {
    $this->app->bind(PreventRequestForgery::class, SeForgeryAsInProduction::class);

    // A new session, as after an expired one: no token in the session, none in the request.
    $headers = $site !== null ? ['Sec-Fetch-Site' => $site] : [];
    $response = $this->post(route('locale.update'), ['locale' => 'fr', 'return_to' => '/'], $headers);

    expect($response->status())->toBe($status);
})->with([
    'the same site' => ['same-origin', 302],
    'another site of the same domain' => ['same-site', 419],
    'another site' => ['cross-site', 419],
    'typed in the address bar' => ['none', 419],
    'a browser without the header' => [null, 419],
]);

test('the web group uses PreventRequestForgery, with the defaults: no "same-site", no "origin only"', function () {
    $web = app(Kernel::class)->getMiddlewareGroups()['web'];

    expect($web)->toContain(PreventRequestForgery::class);
    expect((string) file_get_contents(base_path('bootstrap/app.php')))
        ->not->toContain('preventRequestForgery(')
        ->not->toContain('allowSameSite')
        ->not->toContain('originOnly');
});

// ---------------------------------------------------------------------------------------------
// The new token
// ---------------------------------------------------------------------------------------------

test('session.refresh: an empty answer, never stored, with the XSRF-TOKEN cookie, for a guest too', function () {
    $response = $this->get(route('session.refresh'));

    $response->assertNoContent();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $response->assertCookie('XSRF-TOKEN', null, false);
    $this->assertGuest();
});

test('session.refresh: no login, its own request limit', function () {
    $route = Route::getRoutes()->getByName('session.refresh');
    assert($route !== null);

    expect($route->uri())->toBe('session/refresh')
        ->and($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->gatherMiddleware())->toContain('throttle:session-refresh')
        ->and($route->gatherMiddleware())->not->toContain('auth')
        ->and(config('booking.rate_limit_session_refresh_per_minute'))->toBe(10);
});

test('session.refresh: the eleventh request in a minute is refused', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->get(route('session.refresh'))->assertNoContent();
    }

    $this->get(route('session.refresh'))->assertTooManyRequests();
});

// ---------------------------------------------------------------------------------------------
// The browser, read from the sources
// ---------------------------------------------------------------------------------------------

test('after a 419 only: a new token first, then the toast; never a reload, never a new send', function () {
    $source = (string) file_get_contents(resource_path('js/lib/http-errors.ts'));

    expect($source)
        ->toContain("import { refresh } from '@/routes/session';")
        ->toMatch("/await fetch\\(refresh\\.url\\(\\), \\{\\s*headers: \\{ 'X-Requested-With': 'XMLHttpRequest' \\},\\s*cache: 'no-store',\\s*\\}\\);/")
        ->toMatch('/if \(status === 419\) \{\s*void renewSession\(\)\.then\(\(\) => showError\(status\)\);\s*return;\s*\}\s*showError\(status\);/')
        ->and(substr_count($source, 'void renewSession()'))->toBe(1)
        // Nothing sent again, nothing reloaded, nothing kept in the browser.
        ->and($source)->not->toMatch('/router\.(post|put|patch|delete|visit|reload|get)\(|\.submit\(|location\.|localStorage|sessionStorage|remember/i');
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the new text in the three languages, and the old one is gone', function () {
    $expected = [
        'it' => 'Il modulo non è stato inviato perché la pagina era aperta da troppo tempo. Quello che hai scritto è ancora qui: premi di nuovo Invia.',
        'fr' => "Le formulaire n'a pas été envoyé, car la page était ouverte depuis trop longtemps. Ce que vous avez écrit est toujours là\u{00A0}: appuyez de nouveau sur Envoyer.",
        'en' => 'The form was not sent because the page had been open for too long. What you wrote is still here: press Send again.',
    ];

    foreach ($expected as $locale => $text) {
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        expect($translations['errors.request.not_sent'])->toBe($text)
            ->and($translations)->not->toHaveKey('errors.request.session_expired');
    }

    expect((string) file_get_contents(lang_path('it.json')))->not->toContain('Ricarica la pagina e riprova');
});

test('SESSION_LIFETIME stays 120 minutes', function () {
    // What the repository says, never the local .env (each developer may change it): the default,
    // used when the variable is not set (as on Render), and the two examples.
    expect((string) file_get_contents(config_path('session.php')))->toContain("'lifetime' => (int) env('SESSION_LIFETIME', 120),");
    expect((string) file_get_contents(base_path('.env.example')))->toContain("SESSION_LIFETIME=120\n");
    expect((string) file_get_contents(base_path('.env.production.example')))->toContain("SESSION_LIFETIME=120\n");
    expect((string) file_get_contents(base_path('render.yaml')))->not->toContain('SESSION_LIFETIME');
});
