<?php

namespace App\Providers;

use App\Models\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
