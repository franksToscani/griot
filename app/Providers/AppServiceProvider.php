<?php

namespace App\Providers;

use App\Models\Provider;
use App\Support\LinkPreview;
use App\Support\ProductionMailer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // The preview of the page being answered: one per request, set by the public controllers.
        $this->app->scoped(LinkPreview::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureProductionMailer();
        $this->configureTrustedProxies();
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

            // The photo forms live on the edit page: back there, whatever the "previous" address is.
            if ($field === 'avatar' || $field === 'photo') {
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

                // The answers to the bookings, too: their buttons live on the bookings page.
                if ($request->routeIs('provider.bookings.*')) {
                    $exception->redirectTo(route('provider.bookings.index'));
                }
            }

            // The provider's own bookings: the form will live on the bookings page.
            if ($field === 'booking') {
                $exception->redirectTo(route('provider.bookings.index'));
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

        // The gallery: uploads and removals together, per logged-in user. Each upload decodes
        // a photo and writes three sizes.
        RateLimiter::for('provider-photos', fn (Request $request): Limit => Limit::perMinute(config()->integer('booking.rate_limit_photos_per_minute'))
            ->by('user:'.$request->user()?->id)
            ->response($tooMany('photo')));

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

        // The free slots and the new bookings of a logged-in provider share one counter per user,
        // apart from the services and the answers to the bookings. The slots are read with fetch()
        // and JSON: the usual 429 with Retry-After; the booking form goes back to the bookings page.
        // The public list and its search (docs/search.md): a wide limit per IP, against a script
        // that would read every page (each one is 7 or 8 queries). Laravel's 429 page.
        RateLimiter::for('providers-list', fn (Request $request): Limit => Limit::perMinute(config()->integer('booking.rate_limit_providers_list_per_minute'))
            ->by($request->ip()));

        RateLimiter::for('provider-bookings', fn (Request $request): Limit => Limit::perMinute(config()->integer('booking.rate_limit_provider_bookings_per_minute'))
            ->by('user:'.$request->user()?->id)
            ->response($tooMany('booking')));
    }

    /**
     * The proxies whose X-Forwarded-* headers are believed (config app.trusted_proxies, from
     * TRUSTED_PROXIES): "*" or a list of addresses. Behind the hosting service's proxy, without
     * this, every customer would have the proxy's address (one rate limit for all) and the links
     * would be http. Empty: no proxy at all, set every time (the middleware keeps it in a static).
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = trim(config()->string('app.trusted_proxies'));

        if ($proxies === '') {
            TrustProxies::flushState();

            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
    }

    /**
     * In production, refuse a mailer that does not send (ProductionMailer) where an email can leave:
     * a web request stops here, before anything else is configured; the queue workers and the
     * scheduler stop before they start. The other commands (package:discover in "composer install"
     * without .env, migrate, the build) are never stopped.
     */
    protected function configureProductionMailer(): void
    {
        if (! $this->app->runningInConsole()) {
            ProductionMailer::ensureItSends($this->app);
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            ProductionMailer::ensureItSendsBefore($event->command, $this->app);
        });
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
