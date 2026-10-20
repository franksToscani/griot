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
