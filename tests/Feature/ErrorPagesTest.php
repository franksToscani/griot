<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Griot's error pages (Sprint 8): resources/views/errors. They stand on their own (no Vite, no
// database, no session, no Inertia, no JavaScript), in the language of the request, with the
// colours of the palette and never a detail of the exception. Each page is rendered here by the
// exception handler, as in production (APP_DEBUG=false): no test route.

beforeEach(function () {
    config(['app.debug' => false]);
});

/**
 * The page Laravel shows for an exception, in a language, for a request without a session.
 */
function errRender(Throwable $exception, string $locale = 'it', ?Request $request = null): TestResponse
{
    $request ??= Request::create('/una-pagina');
    app()->instance('request', $request);
    app()->setLocale($locale);

    return TestResponse::fromBaseResponse(app(ExceptionHandler::class)->render($request, $exception));
}

/**
 * The exception behind each page.
 *
 * @return array<int, Closure(): Throwable>
 */
function errExceptions(): array
{
    return [
        403 => fn () => new HttpException(403, 'internal reason of the policy'),
        404 => fn () => new HttpException(404, 'No query results for model [App\Models\Provider]'),
        419 => fn () => new TokenMismatchException('CSRF token mismatch.'),
        429 => fn () => new ThrottleRequestsException('Too Many Attempts.'),
        500 => fn () => new RuntimeException('SQLSTATE[HY000] secret detail in /var/www/griot/app/Secret.php'),
        503 => fn () => new HttpException(503, 'internal maintenance note'),
    ];
}

/**
 * The CSS colour block of the error layout: the light one (:root) or the dark one (in the media
 * query), parsed as the tokens of app.css.
 *
 * @return array<string, array{0: float, 1: float, 2: float}>
 */
function errColours(string $theme): array
{
    $layout = (string) file_get_contents(resource_path('views/errors/layout.blade.php'));
    $pattern = $theme === 'light'
        ? '/<style>\s*:root \{(.*?)\}/s'
        : '/@media \(prefers-color-scheme: dark\) \{\s*:root \{(.*?)\}/s';
    expect(preg_match($pattern, $layout, $block))->toBe(1, "No {$theme} colours in the error layout");

    return accParse($block[1]);
}

// ---------------------------------------------------------------------------------------------
// Each page, in each language
// ---------------------------------------------------------------------------------------------

test('every error page has its status, title, message and links, in the language of the request', function (int $code, array $links, string $locale) {
    $response = errRender(errExceptions()[$code](), $locale)
        ->assertStatus($code)
        ->assertSee('<html lang="'.$locale.'">', false)
        ->assertSee('<title>'.e(__("errors.{$code}.title", [], $locale)).' - Griot</title>', false)
        ->assertSee('<h1>'.e(__("errors.{$code}.title", [], $locale)).'</h1>', false)
        ->assertSee(e(__("errors.{$code}.message", [], $locale)), false);

    $html = $response->getContent();
    $targets = ['home' => url('/'), 'providers' => route('providers.index'), 'reload' => '', 'retry' => '', 'retry_later' => ''];

    // The links, in this order, the first one filled; all of them open the whole page.
    preg_match_all('#<a class="button( primary)?" href="([^"]*)" target="_top">([^<]*)</a>#', (string) $html, $found, PREG_SET_ORDER);
    expect(array_map(fn (array $link) => [$link[2], $link[3]], $found))
        ->toBe(array_map(fn (string $link) => [$targets[$link], e(__("errors.action.{$link}", [], $locale))], $links));
    expect($found[0][1])->toBe(' primary');

    // The name of the site, linked to the home page.
    // Since the provisional logo the mark is the pin (decorative), then the name of the site.
    expect($html)->toMatch('#<a class="brand" href="'.preg_quote(url('/'), '#').'" target="_top">\s*<span class="mark" aria-hidden="true"><img[^>]*alt=""></span>\s*Griot\s*</a>#');
})->with([
    '403' => [403, ['home', 'providers']],
    '404' => [404, ['home', 'providers']],
    '419' => [419, ['reload', 'home']],
    '429' => [429, ['retry_later', 'home']],
    '500' => [500, ['retry', 'home']],
    '503' => [503, ['retry']],
])->with(['it', 'fr', 'en']);

test('a real 404 is Griot\'s page, in the language of the cookie', function (string $locale, string $title) {
    $this->withCookie('locale', $locale)->get('/pagina-che-non-esiste')
        ->assertNotFound()
        ->assertSee('<html lang="'.$locale.'">', false)
        ->assertSee('<h1>'.e($title).'</h1>', false)
        ->assertSee('href="'.route('providers.index').'"', false);
})->with([
    'it' => ['it', 'Pagina non trovata'],
    'fr' => ['fr', 'Page introuvable'],
    'en' => ['en', 'Page not found'],
]);

test('the other codes get the general page, with the way back home', function () {
    $html = errRender(new HttpException(401))->assertStatus(401)
        ->assertSee('<h1>Accesso non autorizzato</h1>', false)
        ->assertSee(__('errors.generic.message'))
        ->getContent();

    expect(substr_count((string) $html, 'class="button'))->toBe(1)
        ->and($html)->toContain('href="'.url('/').'" target="_top">Torna alla home</a>');
});

test('"reload" opens the page the user was on, never an address of another site', function () {
    $post = fn (?string $referer) => tap(Request::create('/providers/x/bookings', 'POST'), fn (Request $request) => $referer === null ?: $request->headers->set('referer', $referer));

    // After a GET, and inside Inertia's dialog: the current page.
    expect(errRender(new TokenMismatchException)->getContent())->toContain('<a class="button primary" href="" target="_top">');
    // After a form sent without Inertia: the page it came from, if it is ours.
    expect(errRender(new TokenMismatchException, 'it', $post('http://localhost/providers/x'))->getContent())
        ->toContain('<a class="button primary" href="http://localhost/providers/x" target="_top">');
    expect(errRender(new TokenMismatchException, 'it', $post('https://altro-sito.example/pagina'))->getContent())
        ->toContain('<a class="button primary" href="" target="_top">')
        ->not->toContain('altro-sito');
    expect(errRender(new TokenMismatchException, 'it', $post(null))->getContent())->toContain('<a class="button primary" href="" target="_top">');
});

// ---------------------------------------------------------------------------------------------
// On their own, without details
// ---------------------------------------------------------------------------------------------

test('no page shows a detail of the exception, with APP_DEBUG=false', function (int $code) {
    $html = (string) errRender(errExceptions()[$code](), 'it')->getContent();

    foreach (['internal reason', 'No query results', 'App\\Models', 'CSRF token', 'Too Many Attempts', 'SQLSTATE', 'secret detail', '/var/www', 'Secret.php', 'RuntimeException', 'HttpException', 'maintenance note', 'Stack trace', '#0 '] as $detail) {
        expect($html)->not->toContain($detail);
    }
})->with([403, 404, 419, 429, 500, 503]);

test('no page depends on Vite, Inertia, JavaScript, the database or the session', function (int $code) {
    // Rendered for a request without a session. That alone is not enough (session() still answers
    // without one): the sources are read too.
    $html = strtolower((string) errRender(errExceptions()[$code](), 'it')->getContent());

    foreach (['/build/', 'vite', 'inertia', '<script', 'data-page', '@vite', 'fonts.', 'http://', 'https://'] as $dependency) {
        expect(str_replace([strtolower(url('/')), strtolower(route('providers.index'))], '', $html))->not->toContain($dependency);
    }

    // The sources say the same: no database, no session, no authenticated user.
    foreach (glob(resource_path('views/errors/*.blade.php')) ?: [] as $path) {
        expect((string) file_get_contents($path))
            ->not->toMatch('/@vite|Vite::|DB::|session\(|auth\(|Auth::|\$errors|@inertia|<script/');
    }
})->with([403, 404, 419, 429, 500, 503]);

test('the error views are Griot\'s: a layout, the links, one view per code and the general one', function () {
    $files = array_map('basename', glob(resource_path('views/errors/*')) ?: []);
    sort($files);

    expect($files)->toBe(['403.blade.php', '404.blade.php', '419.blade.php', '429.blade.php', '500.blade.php', '503.blade.php', 'actions.blade.php', 'layout.blade.php', 'minimal.blade.php']);
});

// ---------------------------------------------------------------------------------------------
// Accessibility: structure, colours, contrast
// ---------------------------------------------------------------------------------------------

test('every page has one h1, a main landmark, its lang and the favicons of the site', function (int $code) {
    $html = (string) errRender(errExceptions()[$code](), 'fr')->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(substr_count($html, '<main'))->toBe(1)
        ->and(substr_count($html, '<header'))->toBe(1)
        ->and($html)->toContain('<html lang="fr">')
        // Since the provisional logo the favicon is an ICO only (docs/brand.md).
        ->toContain('<link rel="icon" href="/favicon.ico" sizes="any">')
        ->not->toContain('favicon.svg')
        // The small mark: the pin of the logo, a file of public/ (no Vite here), decorative.
        ->toContain('<span class="mark" aria-hidden="true"><img src="/brand/pin.png" width="22" height="24" alt=""></span>')
        ->toContain('<meta name="robots" content="noindex">');
})->with([403, 404, 419, 429, 500, 503]);

test('the CSS keeps targets, focus and borders: 44px below 768px, an outline, forced colours, no motion', function () {
    $css = (string) file_get_contents(resource_path('views/errors/layout.blade.php'));

    expect($css)
        ->toMatch('/@media \(max-width: 767\.98px\) \{\s*\.brand,\s*\.button \{\s*min-height: 2\.75rem;/')
        ->toMatch('/a:focus-visible \{\s*outline: 3px solid var\(--ring\);\s*outline-offset: 2px;/')
        ->toMatch('/@media \(forced-colors: active\) \{\s*\.button\.primary \{\s*border-color: ButtonText;/')
        // The square of the small mark: the brown of the band, a border where its background is gone.
        ->toMatch('/\.brand \.mark \{[^}]*background: var\(--hero\);/')
        ->toMatch('/\.brand \.mark \{\s*border: 1px solid CanvasText;/')
        // The filled button keeps its border (transparent), the others show it.
        ->toMatch('/\.button \{[^}]*border: 1px solid var\(--input\);/')
        ->toMatch('/\.button\.primary \{[^}]*border-color: transparent;/')
        ->not->toContain('animation')
        ->not->toContain('transition')
        ->not->toContain('outline: none')
        ->toContain('font-family: system-ui');
});

test('the colours of the error pages are the tokens of app.css, in the light and in the dark theme', function (string $theme) {
    $colours = errColours($theme);
    $tokens = accTokens($theme);

    expect(array_keys($colours))->toBe(['background', 'foreground', 'muted-foreground', 'primary', 'primary-foreground', 'input', 'ring', 'decor', 'hero']);

    foreach ($colours as $name => $value) {
        expect($value)->toBe($tokens[$name], "--{$name} of the error pages differs from app.css ({$theme})");
    }
})->with(['light', 'dark']);

test('every colour pair of the error pages reaches its WCAG threshold', function (string $theme) {
    $c = errColours($theme);
    $background = accSrgb($c['background']);
    $pairs = [
        ['title (foreground)', accSrgb($c['foreground']), $background, 4.5],
        ['message (muted-foreground)', accSrgb($c['muted-foreground']), $background, 4.5],
        ['name of the site (primary)', accSrgb($c['primary']), $background, 4.5],
        ['filled button', accSrgb($c['primary-foreground']), accSrgb($c['primary']), 4.5],
        ['text of the other buttons', accSrgb($c['foreground']), $background, 4.5],
        ['border of the other buttons (input)', accSrgb($c['input']), $background, 3.0],
        ['focus outline (ring, solid)', accSrgb($c['ring']), $background, 3.0],
        // The small mark is the pin of the logo on --hero: its orange, read from the pixels of
        // public/brand/pin.png, is measured in BrandAssetsTest.
        ['orange line under the header', accSrgb($c['decor']), $background, 3.0],
    ];

    foreach ($pairs as [$what, $foreground, $back, $threshold]) {
        $ratio = accRatio($foreground, $back);
        expect($ratio)->toBeGreaterThanOrEqual($threshold, sprintf('%s, %s theme: %.2f:1 (needed %.1f:1)', $what, $theme, $ratio, $threshold));
    }
})->with(['light', 'dark']);

// ---------------------------------------------------------------------------------------------
// Texts
// ---------------------------------------------------------------------------------------------

test('every text of the error pages exists in Italian, French and English', function () {
    $keys = [];

    foreach ([403, 404, 419, 429, 500, 503] as $code) {
        $keys[] = "errors.{$code}.title";
        $keys[] = "errors.{$code}.message";
    }

    foreach (['home', 'providers', 'reload', 'retry', 'retry_later'] as $action) {
        $keys[] = "errors.action.{$action}";
    }

    $keys[] = 'errors.generic.message';

    foreach (['it', 'fr', 'en'] as $locale) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

        foreach ($keys as $key) {
            expect($json)->toHaveKey($key)
                ->and($json[$key])->toBeString()->not->toBe('');
        }
    }
});
