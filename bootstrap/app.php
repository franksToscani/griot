<?php

use App\Http\Controllers\RobotsController;
use App\Http\Controllers\WebAppManifestController;
use App\Http\LocaleFromRequest;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoIndexPrivatePages;
use App\Http\Middleware\NoIndexSite;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\PrivateExceptionLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The rules for crawlers, from the config: everything disallowed while the site is private
        // (SITE_NOINDEX, docs/production.md). A route, not a file in public/, which would win over
        // it. Outside the "web" group: no session, no cookie, no CSRF token for a crawler. The
        // global middleware still runs (NoIndexSite, SecurityHeaders).
        then: function () {
            Route::get('robots.txt', RobotsController::class)->name('robots');
            // The web app manifest, for the installation on a home screen: outside "web" as well,
            // the browser downloads it without cookies anyway (docs/production.md).
            Route::get('manifest.webmanifest', WebAppManifestController::class)->name('manifest');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Every response, error pages and /up included (docs/production.md).
        $middleware->append(SecurityHeaders::class);
        // SITE_NOINDEX: a private site is never indexed, whatever the page (docs/production.md).
        $middleware->append(NoIndexSite::class);

        $middleware->web(append: [
            // Before HandleInertiaRequests, which shares the translations of the language set here.
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            NoIndexPrivatePages::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never the message of a database or mail transport error in the log: it can hold the
        // customers' data and the secret code of a private link (issue #186). One line with the
        // class, the code and the place instead, and stop(): Laravel does not write its own.
        $exceptions->report(fn (QueryException $exception) => PrivateExceptionLog::query($exception))->stop();
        $exceptions->report(fn (TransportExceptionInterface $exception) => PrivateExceptionLog::mail($exception))->stop();

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The error pages in the chosen language. A 419 or a 404 without a route is rendered before
        // SetLocale runs; without a route the cookies are not decrypted either. Only for HTTP
        // requests: commands and jobs report their exceptions, they never render them. Returning
        // null leaves the rendering to Laravel; LocaleFromRequest never throws.
        $exceptions->render(function (Throwable $exception, Request $request) {
            $chosen = LocaleFromRequest::resolve($request, encrypted: $request->route() === null);

            if ($chosen !== null) {
                app()->setLocale($chosen);
            }

            return null;
        });
    })->create();
