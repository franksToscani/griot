<?php

namespace App\Http\Middleware;

use App\Http\Resources\AuthUserResource;
use App\Http\TranslationsProp;
use App\Support\SiteContact;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Only the fields the pages show (AuthUserResource), never the model with its relations.
            // A closure: read when the page is drawn, after the controller, so that the provider
            // the page already loaded gives its name (one name, the business one).
            'auth' => fn () => [
                'user' => $request->user() === null ? null : (new AuthUserResource($request->user()))->resolve($request),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Closures: read when the page is rendered, so a language set after this middleware (the
            // private page of a booking, UseBookingLocale) applies to the translations too.
            'locale' => fn () => app()->getLocale(),
            // The languages of the selector, in the order of the config: [{code, name}].
            'locales' => collect(config()->array('app.available_locales'))
                ->map(fn (string $name, string $code) => ['code' => $code, 'name' => $name])
                ->values()
                ->all(),
            // Once (#178): sent on the first visit and again only for another language or other
            // texts (App\Http\TranslationsProp), never at every navigation.
            'translations' => new TranslationsProp,
            // The site's contacts for the footer of the public pages: email and WhatsApp, each
            // null when it is not configured or not valid (config/contact.php).
            'contact' => fn () => SiteContact::shared(),
        ];
    }
}
