<?php

use App\Http\Resources\AuthUserResource;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\BookingSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProviderSeeder;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

// Production hardening (issues #58, #59, #86, docs/production.md): the command that creates the
// providers, the shared user, the seeders, the trusted proxies, the security headers, the limit
// on the list, the example of the production environment and the PHP version.

afterEach(function () {
    // The trusted proxies live in a static of the middleware: never from one test to the next.
    TrustProxies::flushState();
});

/**
 * Run AppServiceProvider::configureTrustedProxies() with this value of app.trusted_proxies.
 */
function phTrust(string $proxies): void
{
    config(['app.trusted_proxies' => $proxies]);
    (fn () => $this->configureTrustedProxies())->call(new AppServiceProvider(app()));
}

/**
 * A route that tells what the application sees of a request.
 */
function phWhoAmI(): void
{
    Route::get('/_who-am-i', fn (Request $request) => [
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
        'url' => url('/providers'),
    ]);
}

/**
 * The keys of an env file: KEY => value, comments and blank lines left out.
 *
 * @return array<string, string>
 */
function phEnv(string $file): array
{
    $values = [];
    foreach (preg_split('/\R/', (string) file_get_contents(base_path($file))) ?: [] as $line) {
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $match) === 1) {
            $values[$match[1]] = trim($match[2], '"');
        }
    }

    return $values;
}

// ---------------------------------------------------------------------------------------------
// provider:create (#58)
// ---------------------------------------------------------------------------------------------

test('provider:create makes the user and an unpublished provider, the password shown once and working', function () {
    $code = Artisan::call('provider:create', ['name' => 'Trecce Sahel', 'email' => 'Sahel@Example.test', 'city' => 'Bologna']);
    $output = Artisan::output();

    expect($code)->toBe(0);
    $user = User::query()->where('email', 'sahel@example.test')->firstOrFail();
    $provider = $user->provider;

    expect($provider)->not->toBeNull()
        ->and($provider->name)->toBe('Trecce Sahel')
        ->and($provider->city)->toBe('Bologna')
        ->and($provider->is_published)->toBeFalse()
        ->and($provider->published_at)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull();

    // The password: random, in the output once, the one that opens the account.
    expect(preg_match('/: (\S{20})$/m', $output, $match))->toBe(1);
    $password = $match[1];
    expect(substr_count($output, $password))->toBe(1)
        ->and(Hash::check($password, $user->password))->toBeTrue()
        ->and($user->password)->not->toBe($password);

    // A second run: another password.
    Artisan::call('provider:create', ['name' => 'Trecce Lagos', 'email' => 'lagos@example.test', 'city' => 'Milano']);
    expect(Artisan::output())->not->toContain($password);
});

test('provider:create refuses an email already used, and wrong data, without creating anything', function (array $arguments) {
    User::factory()->create(['email' => 'taken@example.test']);
    $users = User::query()->count();

    $code = Artisan::call('provider:create', $arguments);

    expect($code)->toBe(1)
        ->and(User::query()->count())->toBe($users)
        ->and(Provider::query()->count())->toBe(0)
        ->and(Artisan::output())->not->toMatch('/: \S{20}$/m');
})->with([
    'email already used' => [['name' => 'Trecce Sahel', 'email' => 'taken@example.test', 'city' => 'Bologna']],
    'the same email in capitals' => [['name' => 'Trecce Sahel', 'email' => 'TAKEN@example.test', 'city' => 'Bologna']],
    'not an email' => [['name' => 'Trecce Sahel', 'email' => 'not-an-email', 'city' => 'Bologna']],
    'no city' => [['name' => 'Trecce Sahel', 'email' => 'new@example.test', 'city' => ' ']],
    'a name too long' => [['name' => str_repeat('a', 151), 'email' => 'new@example.test', 'city' => 'Bologna']],
]);

// ---------------------------------------------------------------------------------------------
// auth.user (#86)
// ---------------------------------------------------------------------------------------------

test('auth.user holds the name, the email and the verification only, never the model or its relations', function () {
    $provider = Provider::factory()->create();
    $user = $provider->user;

    // The provider's area reads $request->user()->provider: before #86 it ended up in every page.
    $this->actingAs($user)->get(route('provider.edit'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.user', fn ($shared) => array_keys($shared->all()) === ['name', 'email', 'email_verified_at']));

    $this->actingAs($user)->get(route('dashboard'))->assertOk()
        ->assertInertia(function (Assert $page) use ($user) {
            $shared = $page->toArray()['props']['auth']['user'];
            expect($shared)->toBe(['name' => $user->name, 'email' => $user->email, 'email_verified_at' => $user->email_verified_at?->toJSON()]);
            expect(json_encode($page->toArray()['props']['auth']))->not->toMatch('/password|remember_token|two_factor|"id"|provider|created_at/');
        });

    app('auth')->forgetGuards();
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
});

test('the type of the pages says the same fields as the resource', function () {
    $resource = (string) file_get_contents(app_path('Http/Resources/AuthUserResource.php'));
    $type = (string) file_get_contents(resource_path('js/types/auth.ts'));

    preg_match_all("/'([a-z_]+)' => \\\$this->/", $resource, $fields);
    preg_match('/export type User = \{([^}]*)\};/', $type, $block);
    preg_match_all('/^\s*([a-z_]+)\??:/m', $block[1] ?? '', $typed);

    expect($fields[1])->toBe(['name', 'email', 'email_verified_at'])
        ->and($typed[1])->toBe($fields[1])
        ->and($type)->toMatch('/user: User \| null;/')
        ->not->toContain('[key: string]');
    expect((string) file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php')))
        ->toContain('(new AuthUserResource($request->user()))->resolve($request)')
        ->not->toMatch("/'user' => \\\$request->user\\(\\),/");
    expect(class_exists(AuthUserResource::class))->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Seeders (#59)
// ---------------------------------------------------------------------------------------------

test('outside local development and the tests, the development seeders refuse to run and write nothing', function (string $seeder, string $environment) {
    app()->detectEnvironment(fn () => $environment);

    // The real command, with --force as on a server (without it, production only asks first).
    expect(fn () => Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]))
        ->toThrow(RuntimeException::class, 'php artisan db:seed --class=CategorySeeder --force');
    expect(User::query()->count())->toBe(0)
        ->and(Provider::query()->count())->toBe(0)
        ->and(Booking::query()->count())->toBe(0);
})->with([DatabaseSeeder::class, ProviderSeeder::class, BookingSeeder::class])
    ->with(['production', 'staging']);

test('the categories can be seeded in production, on their own', function () {
    app()->detectEnvironment(fn () => 'production');

    // The command of docs/production.md.
    expect(Artisan::call('db:seed', ['--class' => CategorySeeder::class, '--force' => true]))->toBe(0);

    expect(Category::query()->count())->toBe(7)
        ->and(User::query()->count())->toBe(0);
    expect((string) file_get_contents(base_path('docs/production.md')))->toContain('php artisan db:seed --class=CategorySeeder --force');
});

// ---------------------------------------------------------------------------------------------
// Trusted proxies
// ---------------------------------------------------------------------------------------------

test('without TRUSTED_PROXIES the forwarded headers are ignored', function () {
    phWhoAmI();
    phTrust('');

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'griot.example.test'])
        ->get('/_who-am-i')
        ->assertExactJson(['ip' => '10.0.0.1', 'secure' => false, 'url' => config('app.url').'/providers']);
});

test('with TRUSTED_PROXIES=* the client\'s address and https come from the proxy', function () {
    phWhoAmI();
    phTrust('*');

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'griot.example.test', 'X-Forwarded-Port' => '443'])
        ->get('/_who-am-i')
        ->assertExactJson(['ip' => '203.0.113.7', 'secure' => true, 'url' => 'https://griot.example.test/providers']);
});

test('with a list of proxies only those are believed', function () {
    phWhoAmI();
    phTrust('10.0.0.1, 10.0.0.2');

    // Each request on a server is a new PHP process; here the untrusted one goes first.
    $headers = ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'];
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->withHeaders($headers)->get('/_who-am-i')
        ->assertJson(['ip' => '198.51.100.9', 'secure' => false]);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->withHeaders($headers)->get('/_who-am-i')
        ->assertJson(['ip' => '203.0.113.7', 'secure' => true]);
    expect(config('app.trusted_proxies'))->toBe('10.0.0.1, 10.0.0.2');
    expect((string) file_get_contents(config_path('app.php')))->toContain("'trusted_proxies' => env('TRUSTED_PROXIES', ''),");
});

// ---------------------------------------------------------------------------------------------
// Rate limit of the list
// ---------------------------------------------------------------------------------------------

test('the list and its search allow 60 requests a minute per client, then 429', function () {
    RateLimiter::clear('providers-list');
    phTrust('*');
    $from = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->withHeaders(['X-Forwarded-For' => $ip]);

    for ($i = 0; $i < 60; $i++) {
        $from('203.0.113.7')->get(route('providers.index', ['q' => 'trecce']))->assertOk();
    }
    $from('203.0.113.7')->get(route('providers.index'))->assertStatus(429);

    // Another customer behind the same proxy has a counter of their own.
    $from('203.0.113.8')->get(route('providers.index'))->assertOk();
    // The home page is not limited by it.
    $from('203.0.113.7')->get('/')->assertOk();
    expect(config('booking.rate_limit_providers_list_per_minute'))->toBe(60);
});

// ---------------------------------------------------------------------------------------------
// Security headers
// ---------------------------------------------------------------------------------------------

test('every response has the security headers, error pages and /up included; never HSTS outside production', function (string $path, int $status) {
    $response = $this->get($path)->assertStatus($status);

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse()
        // No full CSP yet (the inline script of the theme needs a nonce first).
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
})->with([
    'home' => ['/', 200],
    'list' => ['/providers', 200],
    'health' => ['/up', 200],
    'not found' => ['/una-pagina-che-non-esiste', 404],
]);

test('HSTS only in production and only on a secure request; the private booking page keeps its own headers', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['mail.default' => 'smtp']);

    $this->get('/')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');

    $booking = Booking::factory()->create();
    $this->get(route('bookings.show', $booking->public_token))->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($this->get(route('bookings.show', $booking->public_token))->headers->get('Cache-Control'))->toContain('no-store');
});

// ---------------------------------------------------------------------------------------------
// Production
// ---------------------------------------------------------------------------------------------

test('in production the main pages answer without errors', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['mail.default' => 'smtp', 'app.debug' => false]);
    $provider = Provider::factory()->published()->create();
    Service::factory()->for($provider)->create();

    $this->get('/')->assertOk();
    $this->get(route('providers.index'))->assertOk();
    $this->get(route('providers.index', ['q' => 'trecce', 'sort' => 'price_asc']))->assertOk();
    $this->get(route('providers.show', $provider->slug))->assertOk();
    $this->get(route('login'))->assertOk();
    $this->get('/register')->assertNotFound();
});

test('the example of the production environment: every key known, safe values, no secret, no real contact', function () {
    $example = phEnv('.env.production.example');
    // The config of the application and Laravel's own defaults (BCRYPT_ROUNDS is read there).
    $config = '';
    foreach ([...(glob(config_path('*.php')) ?: []), ...(glob(base_path('vendor/laravel/framework/config/*.php')) ?: [])] as $file) {
        $config .= (string) file_get_contents($file);
    }

    // Every key is one the application reads (or Vite's), and the important ones are there.
    foreach (array_keys($example) as $key) {
        expect(str_contains($config, "env('{$key}'") || $key === 'VITE_APP_NAME')->toBeTrue("{$key} is read by no config file");
    }
    foreach (['APP_KEY', 'APP_URL', 'TRUSTED_PROXIES', 'DB_CONNECTION', 'DB_PASSWORD', 'MAIL_MAILER', 'MAIL_FROM_ADDRESS', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_BUCKET', 'CONTACT_EMAIL', 'CONTACT_WHATSAPP', 'PRIVACY_CONTROLLER_NAME', 'PRIVACY_CONTACT_EMAIL', 'PRIVACY_REVIEWED'] as $key) {
        expect($example)->toHaveKey($key);
    }

    expect($example['APP_ENV'])->toBe('production')
        ->and($example['APP_DEBUG'])->toBe('false')
        ->and($example['SESSION_SECURE_COOKIE'])->toBe('true')
        ->and($example['LOG_CHANNEL'])->toBe('stderr')
        ->and($example['LOG_LEVEL'])->toBe('info')
        ->and($example['MEDIA_DISK_DRIVER'])->toBe('s3')
        ->and($example['TRUSTED_PROXIES'])->toBe('*')
        ->and([$example['SESSION_DRIVER'], $example['CACHE_STORE'], $example['QUEUE_CONNECTION']])->toBe(['database', 'database', 'database'])
        ->and($example['MAIL_MAILER'])->not->toBeIn(['log', 'array'])
        ->and($example['PRIVACY_REVIEWED'])->toBe('false');

    // The secrets: placeholders or empty, never a value.
    foreach (['APP_KEY', 'DB_PASSWORD', 'DB_URL', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'MAIL_PASSWORD'] as $secret) {
        expect($example[$secret])->toBeIn(['', '<da-compilare>'], $secret);
    }
    // No real contact: only example.test, no phone number.
    $file = (string) file_get_contents(base_path('.env.production.example'));
    preg_match_all('/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $file, $emails);
    expect(array_unique($emails[1]))->toBe(['example.test'])
        ->and($file)->not->toMatch('/(\+|00)\d[\d ]{7,}/')
        ->and($file)->not->toMatch('/base64:/');
    // The local example keeps its comment on APP_DEBUG.
    expect((string) file_get_contents(base_path('.env.example')))->toMatch('/# true only in local development[^\n]*\n[^\n]*\nAPP_DEBUG=true/');
});

test('PHP 8.4 at least, in composer.json and in the lock; the CI runs a version that satisfies it', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require']['php'])->toBe('^8.4')
        ->and($lock['platform']['php'])->toBe('^8.4');
    expect((string) file_get_contents(base_path('.github/workflows/tests.yml')))
        ->toContain("php-version: '8.5'")
        ->toContain("node-version: '22'");
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80400);
});

test('no real contact in the new files: the command, its texts, the example and the docs', function () {
    $sources = (string) file_get_contents(app_path('Console/Commands/CreateProviderCommand.php'))
        .(string) file_get_contents(base_path('docs/production.md'))
        .(string) file_get_contents(base_path('tests/Feature/ProductionHardeningTest.php'));

    preg_match_all('/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $sources, $emails);
    expect(array_values(array_diff(array_unique(array_map('strtolower', $emails[1])), ['example.test'])))->toBe([])
        ->and($sources)->not->toMatch('/(\+|00)39\d{6,}/');
});
