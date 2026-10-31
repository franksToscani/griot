<?php

use App\Http\LocaleFromRequest;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            // Before HandleInertiaRequests, which shares the translations of the language set here.
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
