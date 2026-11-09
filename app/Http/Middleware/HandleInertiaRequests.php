<?php

namespace App\Http\Middleware;

use App\Http\Resources\AuthUserResource;
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
            'auth' => [
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
            'translations' => fn () => $this->translations(),
            // The site's contacts for the footer of the public pages: email and WhatsApp, each
            // null when it is not configured or not valid (config/contact.php).
            'contact' => fn () => SiteContact::shared(),
        ];
    }

    /**
     * Translations for the current locale, read from lang/{locale}.json.
     *
     * @return array<string, string>
     */
    private function translations(): array
    {
        $path = lang_path(app()->getLocale().'.json');

        if (! is_file($path)) {
            return [];
        }

        /** @var array<string, string>|null $translations */
        $translations = json_decode((string) file_get_contents($path), true);

        return $translations ?? [];
    }
}
