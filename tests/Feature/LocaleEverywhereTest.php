<?php

use App\Booking\LocalTime;
use App\Http\LocaleFromRequest;
use Carbon\CarbonImmutable;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

// The language in every page, the error pages included (issue #54, phase 3a-1). French is opened
// here as APP_PREVIEW_LOCALES=fr would do.

function levFrench(): void
{
    config()->set('app.available_locales', ['it' => 'Italiano', 'fr' => 'Français']);
}

/**
 * The CSRF check as in production (PreventRequestForgery, in the web group): Laravel skips it while
 * running the tests.
 */
function levRealCsrf(): void
{
    app()->instance(PreventRequestForgery::class, new class(app(), app('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
}

/**
 * A request with the "locale" cookie as the browser sends it: encrypted, with its prefix.
 */
function levRequest(?string $cookie, bool $encrypt): Request
{
    $request = Request::create('/');

    if ($cookie !== null) {
        $request->cookies->set('locale', $encrypt
            ? Crypt::encryptString(CookieValuePrefix::create('locale', Crypt::getKey()).$cookie)
            : $cookie);
    }

    return $request;
}

// ---------------------------------------------------------------------------------------------
// Error pages
// ---------------------------------------------------------------------------------------------

test('a 404 without a route is in the chosen language, with its lang', function () {
    levFrench();

    $this->withCookie('locale', 'fr')->get('/indirizzo-che-non-esiste')
        ->assertNotFound()
        ->assertSee('<html lang="fr">', false)
        ->assertSee('Page introuvable')
        ->assertDontSee('Pagina non trovata');
});

test('a 404 without a route and without a choice is in Italian', function () {
    levFrench();

    $this->get('/indirizzo-che-non-esiste')
        ->assertNotFound()
        ->assertSee('<html lang="it">', false)
        ->assertSee('Pagina non trovata');
});

test('a 419 is in the chosen language, though SetLocale has not run yet', function () {
    levFrench();
    levRealCsrf();

    $this->withCookie('locale', 'fr')->post(route('locale.update'), ['locale' => 'it'])
        ->assertStatus(419)
        ->assertSee('<html lang="fr">', false)
        ->assertSee('Page expirée');
});

test('a 419 without a choice is in Italian', function () {
    levFrench();
    levRealCsrf();

    $this->post(route('locale.update'), ['locale' => 'fr'])
        ->assertStatus(419)
        ->assertSee('<html lang="it">', false)
        ->assertSee('Pagina scaduta');
});

test('a 404 inside a route is in the chosen language too', function () {
    levFrench();

    $this->withCookie('locale', 'fr')->get('/providers/non-esiste')
        ->assertNotFound()
        ->assertSee('<html lang="fr">', false)
        ->assertSee('Page introuvable');
});

test('a cookie that is not valid or not readable gives Italian, without errors', function (Closure $send) {
    levFrench();

    $send($this)->assertNotFound()
        ->assertSee('<html lang="it">', false)
        ->assertSee('Pagina non trovata');
})->with([
    'a language not available' => [fn ($test) => $test->withCookie('locale', 'de')->get('/indirizzo-che-non-esiste')],
    'written by hand, not encrypted' => [fn ($test) => $test->withUnencryptedCookie('locale', 'fr')->get('/indirizzo-che-non-esiste')],
    'garbage' => [fn ($test) => $test->withUnencryptedCookie('locale', 'eyJpdiI6Im5vcGUi')->get('/indirizzo-che-non-esiste')],
    'French not available' => [fn ($test) => tap($test, fn () => config()->set('app.available_locales', ['it' => 'Italiano']))->withCookie('locale', 'fr')->get('/indirizzo-che-non-esiste')],
]);

test('the error layout is Laravel\'s, with the language of the page only', function () {
    $ours = (string) file_get_contents(resource_path('views/errors/minimal.blade.php'));
    $laravel = (string) file_get_contents(base_path('vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/views/minimal.blade.php'));

    expect($ours)->toContain('<html lang="{{ str_replace(\'_\', \'-\', app()->getLocale()) }}">');
    $withoutLang = fn (string $view) => preg_replace(['/^\{\{--.*--\}\}\n/m', '/<html lang="[^"]*">/'], ['', '<html>'], $view);
    expect($withoutLang($ours))->toBe($withoutLang($laravel));
    // Only this layout: the other error views stay Laravel's.
    expect(glob(resource_path('views/errors/*')))->toBe([resource_path('views/errors/minimal.blade.php')]);
});

// ---------------------------------------------------------------------------------------------
// LocaleFromRequest
// ---------------------------------------------------------------------------------------------

test('the cookie is read plain after EncryptCookies, and decrypted before it', function () {
    levFrench();

    expect(LocaleFromRequest::resolve(levRequest('fr', encrypt: false)))->toBe('fr')
        ->and(LocaleFromRequest::resolve(levRequest('fr', encrypt: true), encrypted: true))->toBe('fr')
        // Before EncryptCookies a plain value is not trusted.
        ->and(LocaleFromRequest::resolve(levRequest('fr', encrypt: false), encrypted: true))->toBeNull()
        ->and(LocaleFromRequest::resolve(levRequest(null, encrypt: false)))->toBeNull();
});

test('a cookie encrypted for another name, a language not available or garbage give null', function () {
    levFrench();
    $otherName = levRequest(null, encrypt: false);
    $otherName->cookies->set('locale', Crypt::encryptString(CookieValuePrefix::create('appearance', Crypt::getKey()).'fr'));
    $array = levRequest(null, encrypt: false);
    $array->cookies->set('locale', ['fr']);

    expect(LocaleFromRequest::resolve($otherName, encrypted: true))->toBeNull()
        ->and(LocaleFromRequest::resolve(levRequest('de', encrypt: true), encrypted: true))->toBeNull()
        ->and(LocaleFromRequest::resolve(levRequest('not-a-cipher', encrypt: false), encrypted: true))->toBeNull()
        ->and(LocaleFromRequest::resolve($array))->toBeNull()
        ->and(LocaleFromRequest::resolve(levRequest('', encrypt: false)))->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------------------------------

test('the long date and the time range are the same as before in Italian', function (string $start, string $end, string $date, string $time) {
    app()->setLocale('it');

    expect(LocalTime::longDate(CarbonImmutable::parse($start, 'UTC')))->toBe($date)
        ->and(LocalTime::timeRange(CarbonImmutable::parse($start, 'UTC'), CarbonImmutable::parse($end, 'UTC')))->toBe($time);
})->with([
    'summer' => ['2026-10-22 07:00', '2026-10-22 09:45', 'giovedì 22 ottobre 2026', '09:00–11:45'],
    'winter' => ['2026-10-29 08:00', '2026-10-29 10:45', 'giovedì 29 ottobre 2026', '09:00–11:45'],
]);

test('the long date follows the language of the application, or the one given', function () {
    $start = CarbonImmutable::parse('2026-10-29 08:00', 'UTC');

    app()->setLocale('fr');
    expect(LocalTime::longDate($start))->toBe('jeudi 29 octobre 2026');

    // A given language does not change the application's.
    app()->setLocale('it');
    expect(LocalTime::longDate($start, 'fr'))->toBe('jeudi 29 octobre 2026')
        ->and(app()->getLocale())->toBe('it')
        ->and(LocalTime::longDate($start))->toBe('giovedì 29 ottobre 2026');
});

test('the long date and the time range are written in LocalTime only', function () {
    $sources = [...glob(app_path('Notifications/*.php')), ...glob(app_path('Http/Resources/*.php'))];

    foreach ($sources as $path) {
        $source = (string) file_get_contents($path);

        expect($source)->not->toContain('isoFormat(')
            ->not->toContain("format('H:i').'–'");
    }
});

// ---------------------------------------------------------------------------------------------
// Sources and texts
// ---------------------------------------------------------------------------------------------

test('no aria-label is written by hand in the React sources', function () {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = $file->getPathname();

        if (! str_ends_with($path, '.tsx') || preg_match('#/resources/js/(routes|actions|wayfinder)/#', $path) === 1) {
            continue;
        }

        expect(preg_match('/aria-label="[^"]*[A-Za-z][^"]*"/', (string) file_get_contents($path)))->toBe(0, "A written aria-label in {$path}");
    }
});

test('the label of the breadcrumbs is translated', function () {
    expect(__('common.breadcrumb', [], 'it'))->toBe('Percorso')
        ->and(__('common.breadcrumb', [], 'fr'))->toBe("Fil d'Ariane");
    expect((string) file_get_contents(resource_path('js/components/ui/breadcrumb.tsx')))->toContain('aria-label={t("common.breadcrumb")}');
});
