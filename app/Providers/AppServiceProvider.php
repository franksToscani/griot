<?php

namespace App\Providers;

use App\Models\Provider;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRouteBindings();
        $this->configureRateLimiting();
    }

    /**
     * Resolve the {publishedProvider} route parameter, by slug, among the published providers.
     *
     * Route bindings run before the form request: an unpublished or missing provider gives a
     * 404 even when the other parameters are wrong, so the response never reveals which slugs exist.
     */
    protected function configureRouteBindings(): void
    {
        Route::bind('publishedProvider', fn (string $slug): Provider => Provider::query()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail());
    }

    /**
     * Rate limits per IP address for the public booking routes, used as "throttle:<name>".
     *
     * Each limiter has its own counters: Laravel prefixes the key with the limiter's name, so
     * using up booking-store does not block booking-slots or booking-cancel. The two limits of
     * booking-store need a prefix of their own as well, otherwise they would share one counter.
     *
     * Warning for production: behind a proxy (Render), $request->ip() is the proxy's address
     * unless the trusted proxies are configured, so the limits would apply to all the
     * customers together. That is decided with the deploy, in Sprint 8 (issue #60).
     */
    protected function configureRateLimiting(): void
    {
        // The answer to a booking form over its limit. A JSON client gets the usual 429 with
        // Retry-After; the Inertia form gets a validation error instead of an error page in a
        // window. The cancellation goes back to the private page by its code: that page sends no
        // Referer, so back() would not find it. ThrottleRequests runs before SubstituteBindings
        // (middleware priority), so route('booking') is still the raw code here, not the model.
        $tooMany = fn (string $field): Closure => function (Request $request, array $headers) use ($field): never {
            if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
                throw new ThrottleRequestsException('Too Many Attempts.', null, $headers);
            }

            $exception = ValidationException::withMessages([$field => __('booking.throttle.too_many')]);

            if ($field === 'cancel') {
                $exception->redirectTo(route('bookings.show', ['booking' => (string) $request->route('booking')]));
            }

            // The photo form lives on the edit page: back there, whatever the "previous" address is.
            if ($field === 'avatar') {
                $exception->redirectTo(route('provider.edit'));
            }

            // The publish buttons live on the dashboard.
            if ($field === 'publish') {
                $exception->redirectTo(route('dashboard'));
            }

            // The service form lives on the services page.
            if ($field === 'services') {
                $exception->redirectTo(route('provider.services.index'));

                // The weekly time slots use the same limiters, but their forms live on their own page.
                if ($request->routeIs('provider.availability.*')) {
                    $exception->redirectTo(route('provider.availability.index'));
                }
            }

            throw $exception;
        };

        RateLimiter::for('booking-store', fn (Request $request): array => [
            Limit::perMinute(config()->integer('booking.rate_limit_store_per_minute'))
                ->by('minute|'.$request->ip())
                ->response($tooMany('throttle')),
            Limit::perHour(config()->integer('booking.rate_limit_store_per_hour'))
                ->by('hour|'.$request->ip())
                ->response($tooMany('throttle')),
        ]);

        RateLimiter::for('booking-cancel', fn (Request $request): Limit => Limit::perMinute(config()->integer('booking.rate_limit_cancel_per_minute'))
            ->by($request->ip())
            ->response($tooMany('cancel')));

        // The free slots are read with fetch() and JSON: Laravel's default 429 with Retry-After.
        RateLimiter::for('booking-slots', fn (Request $request): Limit => Limit::perMinute(config()->integer('booking.rate_limit_slots_per_minute'))
            ->by($request->ip()));

        // Per logged-in user, not per IP: the photo upload is behind the login, and decoding a big
        // image costs memory (about 70 MB for 4000x4000).
        RateLimiter::for('provider-avatar', fn (Request $request): Limit => Limit::perMinute(config()->integer('booking.rate_limit_avatar_per_minute'))
            ->by('user:'.$request->user()?->id)
            ->response($tooMany('avatar')));

        // Shared by provider.publish and provider.unpublish: same limiter name and same key, so the
        // two routes count together (the counter key is the limiter name plus the user).
        RateLimiter::for('provider-publish', fn (Request $request): Limit => Limit::perMinute(config()->integer('booking.rate_limit_publish_per_minute'))
            ->by('user:'.$request->user()?->id)
            ->response($tooMany('publish')));

        // New services of a logged-in provider, per user (not per IP).
        RateLimiter::for('provider-services', fn (Request $request): Limit => Limit::perMinute(config()->integer('booking.rate_limit_services_per_minute'))
            ->by('user:'.$request->user()?->id)
            ->response($tooMany('services')));

        // Edit, deactivate, activate and delete of a service share one counter per user. The
        // existing "services" answer already goes back to the services page.
        RateLimiter::for('provider-services-manage', fn (Request $request): Limit => Limit::perMinute(config()->integer('booking.rate_limit_services_manage_per_minute'))
            ->by('user:'.$request->user()?->id)
            ->response($tooMany('services')));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
