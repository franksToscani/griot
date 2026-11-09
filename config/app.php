<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Griot'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'it'),

    // Italian is the reference language, always complete: a key missing in another language shows
    // the Italian text, never the key itself.
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'it'),

    /*
    |--------------------------------------------------------------------------
    | Available Locales
    |--------------------------------------------------------------------------
    |
    | The languages a visitor can choose with the language selector, each with
    | its own name, written in that language: Italian, French and English,
    | open to everyone. The selector is hidden while there is only one. The
    | site always starts in the "locale" above.
    |
    | APP_PREVIEW_LOCALES adds languages that are known (in $names) but not
    | open to everyone yet, to review them: only if their lang/{code}.json
    | exists. Today every known language is open; a new one is added to
    | $names first, then opened in $locales once it is complete.
    |
    */

    'available_locales' => (function (): array {
        // Every known language, open or not: only these can be previewed.
        $names = ['it' => 'Italiano', 'fr' => 'Français', 'en' => 'English'];
        $locales = ['it' => $names['it'], 'fr' => $names['fr'], 'en' => $names['en']];

        foreach (explode(',', (string) env('APP_PREVIEW_LOCALES', '')) as $code) {
            $code = trim($code);

            if (isset($names[$code]) && is_file(base_path("lang/{$code}.json"))) {
                $locales[$code] = $names[$code];
            }
        }

        return $locales;
    })(),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The proxies in front of the application (docs/production.md): "*" behind the load balancer
    | of the hosting service, or a list of addresses separated by commas. Their X-Forwarded-For,
    | -Proto, -Host and -Port headers give the client's address (for the rate limits) and the https
    | scheme (for the links). Empty, as in local development: no proxy is trusted.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    /*
    |--------------------------------------------------------------------------
    | Private Site (no indexing)
    |--------------------------------------------------------------------------
    |
    | SITE_NOINDEX=true while the site is online but private (docs/production.md): every response
    | says "X-Robots-Tag: noindex, nofollow" and /robots.txt disallows everything. False, the
    | default: the pages decide by themselves (App\Support\LinkPreview) and /robots.txt allows all.
    |
    */

    'noindex' => (bool) env('SITE_NOINDEX', false),

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', '')),
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache", "array"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
