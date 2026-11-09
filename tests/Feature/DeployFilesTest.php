<?php

use App\Http\Controllers\RobotsController;
use App\Http\Middleware\NoIndexSite;
use Symfony\Component\Yaml\Yaml;

/*
 * The deploy on Render (issue #60, docs/production.md): the image (Dockerfile, docker/), the
 * Blueprint (render.yaml) and the private site (SITE_NOINDEX). Docker is not on the developers'
 * machines: the image is built and started by the docker-build job of the CI; these tests read the
 * files, so that a change that weakens them shows up here first.
 */

function dplSource(string $path): string
{
    $source = file_get_contents(base_path($path));
    expect($source)->not->toBeFalse("Missing: {$path}");

    return (string) $source;
}

/**
 * The lines of one stage of the Dockerfile, from its FROM to the next one.
 */
function dplStage(string $name): string
{
    preg_match('/^FROM [^\n]* AS '.preg_quote($name, '/').'\n(.*?)(?=^FROM |\z)/ms', dplSource('Dockerfile'), $stage);
    expect($stage)->not->toBeEmpty("No stage {$name} in the Dockerfile");

    return $stage[1];
}

/**
 * render.yaml, parsed.
 *
 * @return array<string, mixed>
 */
function dplBlueprint(): array
{
    $blueprint = Yaml::parseFile(base_path('render.yaml'));
    expect($blueprint)->toBeArray();

    return $blueprint;
}

/**
 * The environment of the web service of render.yaml, by key.
 *
 * @return array<string, array<string, mixed>>
 */
function dplEnv(): array
{
    $env = [];
    foreach (dplBlueprint()['services'][0]['envVars'] as $variable) {
        $env[$variable['key']] = $variable;
    }

    return $env;
}

/**
 * The extensions installed by one stage of the Dockerfile.
 *
 * @return list<string>
 */
function dplExtensions(string $stage): array
{
    preg_match('/install-php-extensions ([a-z_ ]+)/', dplStage($stage), $found);

    return preg_split('/\s+/', trim($found[1] ?? '')) ?: [];
}

// ---------------------------------------------------------------------------------------------
// The image
// ---------------------------------------------------------------------------------------------

test('the image is FrankenPHP with the same PHP major version as the CI, in three stages', function () {
    $dockerfile = dplSource('Dockerfile');
    preg_match("/php-version: '(\\d+\\.\\d+)'/", dplSource('.github/workflows/tests.yml'), $ci);

    expect($dockerfile)->toMatch('/^ARG FRANKENPHP_IMAGE=dunglas\/frankenphp:[\d.]+-php'.preg_quote($ci[1], '/').'-trixie$/m')
        ->toMatch('/^FROM \$\{FRANKENPHP_IMAGE\} AS vendor$/m')
        ->toMatch('/^FROM vendor AS assets$/m')
        ->toMatch('/^FROM \$\{FRANKENPHP_IMAGE\} AS runtime$/m');
    // Classic mode: no worker directive for FrankenPHP, no Octane.
    expect(dplSource('docker/Caddyfile'))->not->toMatch('/^\s*worker\b/m');
    expect(dplSource('composer.json'))->not->toContain('laravel/octane');
});

test('the vendor stage installs without the development packages, the assets stage builds with PHP and Node 22', function () {
    expect(dplStage('vendor'))->toContain('composer install --no-dev --optimize-autoloader --no-interaction');
    expect(dplStage('assets'))
        ->toContain('COPY --from=node /usr/local/bin/node /usr/local/bin/node')
        ->toContain('npm ci --no-audit --no-fund && npm run build');
    expect(dplSource('Dockerfile'))->toMatch('/^ARG NODE_IMAGE=node:22-/m');
    expect(dplStage('runtime'))
        ->toContain('COPY --from=vendor /app /app')
        ->toContain('COPY --from=assets /app/public/build /app/public/build');
});

test('the runtime has exactly the extensions of composer.json plus pdo_pgsql, no intl nor bcmath', function () {
    $composer = json_decode(dplSource('composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $required = array_map(fn (string $key) => substr($key, 4), array_values(array_filter(array_keys($composer['require']), fn (string $key) => str_starts_with($key, 'ext-'))));
    $expected = [...$required, 'pdo_pgsql'];
    sort($expected);

    $runtime = dplExtensions('runtime');
    sort($runtime);

    expect($runtime)->toBe($expected)
        ->and($runtime)->not->toContain('intl')
        ->and($runtime)->not->toContain('bcmath')
        // The vendor stage checks the same platform (plus zip, to unpack the packages).
        ->and(array_values(array_diff(dplExtensions('vendor'), ['zip'])))->toEqualCanonicalizing($expected);
});

test('the runtime runs as a normal user, writes only in storage/ and bootstrap/cache/, and listens on $PORT', function () {
    $runtime = dplStage('runtime');

    expect($runtime)
        ->toMatch('/^USER griot$/m')
        ->toContain('chown -R griot:griot /app/storage /app/bootstrap/cache /data /config')
        ->toContain('XDG_DATA_HOME=/data')
        ->toContain('XDG_CONFIG_HOME=/config')
        ->not->toContain('setcap')
        ->not->toMatch('/^USER root$/m');
    // The last USER of the file is the normal one.
    preg_match_all('/^USER (\S+)$/m', dplSource('Dockerfile'), $users);
    expect(end($users[1]))->toBe('griot');
    expect(dplSource('docker/Caddyfile'))->toContain(':{$PORT:8080} {');
});

test('no .env and no development file goes into the image', function () {
    $ignored = array_map('trim', explode("\n", dplSource('.dockerignore')));

    expect($ignored)->toContain('.env', '.env.*', '.git', 'node_modules', 'vendor', 'tests', 'public/hot', 'public/build', 'storage/logs/*');
    expect(dplSource('Dockerfile'))->not->toMatch('/COPY[^\n]*\.env/');
});

test('php.ini accepts more than the validation of the photos, and the comments say so on purpose', function () {
    $ini = parse_ini_string(dplSource('docker/php.ini'), false, INI_SCANNER_RAW);
    expect($ini)->toBeArray();
    $megabytes = fn (string $value) => (int) rtrim($value, 'M');

    expect($ini)->toMatchArray([
        'upload_max_filesize' => '3M',
        'post_max_size' => '8M',
        'memory_limit' => '256M',
        'opcache.enable' => '1',
        'opcache.validate_timestamps' => '0',
        'opcache.memory_consumption' => '128',
        'expose_php' => 'Off',
    ]);

    // The validation in KB stays below PHP's limit: a file in between gets the clear message.
    foreach (['app/Http/Requests/StoreProviderPhotoRequest.php' => 2048, 'app/Http/Requests/StoreProviderAvatarRequest.php' => 1536] as $path => $kilobytes) {
        $source = dplSource($path);
        expect($source)->toContain("'max:{$kilobytes}',")
            ->and($source)->toContain('upload_max_filesize of 3M (docker/php.ini), on purpose')
            ->and($kilobytes)->toBeLessThan($megabytes($ini['upload_max_filesize']) * 1024);
    }
    expect($megabytes($ini['post_max_size']))->toBeGreaterThan($megabytes($ini['upload_max_filesize']));
    expect(dplSource('docs/gallery.md'))->toContain('è volutamente **più alto** di quello della validazione (2 MB)');
});

test('Caddy serves public/ through PHP, without HTTPS nor admin, with the header of the private site', function () {
    expect(dplSource('docker/Caddyfile'))
        ->toContain("admin off\n")
        ->toContain("auto_https off\n")
        ->toContain("frankenphp\n")
        ->toContain('root * /app/public')
        ->toContain('php_server')
        ->toContain('@noindex expression `"{$SITE_NOINDEX:false}" == "true"`')
        ->toContain('header @noindex >X-Robots-Tag "'.NoIndexSite::VALUE.'"');
});

test('the start script stops without APP_KEY, builds the caches and never migrates', function () {
    $script = dplSource('docker/start.sh');

    expect($script)
        ->toStartWith("#!/bin/sh\n")
        ->toContain('set -eu')
        ->toMatch('/if \[ -z "\$\{APP_KEY:-\}" \]; then\s+echo "griot-start: APP_KEY is missing\.[^"]*" >&2\s+exit 1\s+fi/')
        ->not->toMatch('/artisan\s+migrate/')
        ->toMatch('/php artisan config:cache\nphp artisan route:cache\nphp artisan view:cache\nphp artisan event:cache\n\nexec supervisord -c \/etc\/supervisor\/griot\.conf/');
    expect(dplSource('Dockerfile'))->toContain('CMD ["griot-start"]');
});

test('supervisord runs the web server, the queue worker and the scheduler; the container stops with the web server', function () {
    $conf = dplSource('docker/supervisord.conf');
    $program = function (string $name) use ($conf): string {
        preg_match('/^\[program:'.$name.'\]\n(.*?)(?=^\[|\z)/ms', $conf, $section);
        expect($section)->not->toBeEmpty("No program {$name}");

        return $section[1];
    };

    expect($program('web'))->toContain("command=frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile\n")
        ->toContain("autorestart=false\n");
    expect($program('queue'))->toContain("command=php /app/artisan queue:work --sleep=3 --max-time=3600 --memory=128\n")
        ->toContain("autorestart=true\n");
    expect($program('scheduler'))->toContain("command=php /app/artisan schedule:work\n")
        ->toContain("autorestart=true\n");
    expect($conf)
        ->toContain("pidfile=/tmp/supervisord.pid\n")
        ->toMatch('/\[eventlistener:stop-on-web-exit\]\ncommand=griot-stop-on-web-exit\nevents=PROCESS_STATE_EXITED,PROCESS_STATE_FATAL\n/')
        ->not->toMatch('/^user=root/m');

    expect(dplSource('docker/stop-on-web-exit.sh'))
        ->toContain('pidfile="${SUPERVISORD_PIDFILE:-/tmp/supervisord.pid}"')
        ->toContain('*"processname:web "*)')
        ->toContain('kill -TERM "$(cat "$pidfile")"')
        ->toContain("printf 'RESULT 2\\nOK'");
});

// ---------------------------------------------------------------------------------------------
// The Blueprint
// ---------------------------------------------------------------------------------------------

test('render.yaml: a Docker web service in Frankfurt, checked on /up, deployed after the checks, migrated before', function () {
    $blueprint = dplBlueprint();
    $web = $blueprint['services'][0];

    expect(count($blueprint['services']))->toBe(1)
        ->and($web)->toMatchArray([
            'type' => 'web',
            'name' => 'griot',
            'runtime' => 'docker',
            'dockerfilePath' => './Dockerfile',
            'region' => 'frankfurt',
            'plan' => '0.5c-512mb',
            'healthCheckPath' => '/up',
            'autoDeployTrigger' => 'checksPass',
            'preDeployCommand' => 'php artisan migrate --force',
        ]);
});

test('render.yaml: PostgreSQL 18 in Frankfurt, the same major version as the CI, given to the app as DB_URL', function () {
    preg_match('/image: postgres:(\d+)/', dplSource('.github/workflows/production-checks.yml'), $ci);
    $database = dplBlueprint()['databases'][0];

    expect($database)->toMatchArray(['name' => 'griot-db', 'region' => 'frankfurt', 'plan' => '0.1c-256mb', 'postgresMajorVersion' => $ci[1]])
        ->and(dplEnv()['DB_URL'])->toBe(['key' => 'DB_URL', 'fromDatabase' => ['name' => 'griot-db', 'property' => 'connectionString']])
        ->and(dplEnv()['DB_CONNECTION']['value'])->toBe('pgsql');
});

test('render.yaml: the values written in the file are the agreed ones, every other variable is sync: false', function () {
    $values = [];
    $synced = [];
    foreach (dplEnv() as $key => $variable) {
        if (array_key_exists('value', $variable)) {
            $values[$key] = $variable['value'];
        } elseif (($variable['sync'] ?? null) === false) {
            expect($variable)->toBe(['key' => $key, 'sync' => false]);
            $synced[] = $key;
        }
    }

    expect($values)->toBe([
        'APP_NAME' => 'Griot',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'TRUSTED_PROXIES' => '*',
        'SITE_NOINDEX' => 'true',
        'LOG_CHANNEL' => 'stderr',
        'LOG_LEVEL' => 'info',
        'DB_CONNECTION' => 'pgsql',
        'SESSION_DRIVER' => 'database',
        'SESSION_SECURE_COOKIE' => 'true',
        'CACHE_STORE' => 'database',
        'QUEUE_CONNECTION' => 'database',
        'MEDIA_DISK_DRIVER' => 's3',
        'MAIL_MAILER' => 'smtp',
        'MAIL_PORT' => '587',
        'PRIVACY_REVIEWED' => 'false',
        'TERMS_REVIEWED' => 'false',
    ]);
    expect($synced)->toEqualCanonicalizing([
        'APP_KEY', 'APP_URL',
        'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET', 'AWS_ENDPOINT', 'AWS_URL',
        'MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS',
        'CONTACT_EMAIL', 'CONTACT_WHATSAPP',
        'PRIVACY_CONTROLLER_NAME', 'PRIVACY_CONTACT_EMAIL',
        'TERMS_PUBLISHER_NAME', 'TERMS_PUBLISHER_ADDRESS', 'TERMS_PUBLISHER_VAT_NUMBER',
    ]);
});

test('render.yaml has no secret, no address and no contact, and every key is one the app reads', function () {
    $text = dplSource('render.yaml');

    expect($text)->not->toMatch('/base64:/')
        ->not->toMatch('/[\w.+-]+@[\w-]+\.[a-z]{2,}/i')
        ->not->toMatch('/\+?\d[\d \-]{7,}\d/')
        ->not->toMatch('/https?:\/\/(?!render\.com)/');

    $production = dplSource('.env.production.example');
    $config = implode("\n", array_map(fn (string $path) => (string) file_get_contents($path), glob(config_path('*.php')) ?: []));
    foreach (array_keys(dplEnv()) as $key) {
        expect(str_contains($config, "env('{$key}'") || preg_match("/^{$key}=/m", $production) === 1)->toBeTrue("Nobody reads {$key}");
    }
});

test('the docker-build job builds, starts and checks the image, with pinned actions only', function () {
    $job = Yaml::parseFile(base_path('.github/workflows/production-checks.yml'))['jobs']['docker-build'];
    $runs = implode("\n", array_map(fn (array $step) => (string) ($step['run'] ?? ''), $job['steps']));
    $uses = array_values(array_filter(array_map(fn (array $step) => $step['uses'] ?? null, $job['steps'])));

    expect($job['timeout-minutes'])->toBe(30)
        ->and($uses)->toHaveCount(1)
        ->and($uses[0])->toMatch('/^actions\/checkout@[0-9a-f]{40}$/');

    foreach ([
        'docker build --pull --tag "$IMAGE" .',
        '--env SITE_NOINDEX=true',
        'http://127.0.0.1:8080/up',
        "grep -qi '^x-robots-tag: noindex, nofollow'",
        'http://127.0.0.1:8080/favicon.ico',
        "test \"\$(curl -s http://127.0.0.1:8080/robots.txt)\" = \"\$(printf 'User-agent: *\\nDisallow: /')\"",
        'test "$(docker exec "$CONTAINER" id -u)" != "0"',
        "for module in pdo_pgsql gd exif 'Zend OPcache'; do",
        'gd_info()["WebP Support"]',
        'check upload_max_filesize 3M',
        'check post_max_size 8M',
        'check memory_limit 256M',
        'frankenphp ru[n] ',
        'docker logs "$CONTAINER"',
        'docker rm --force "$CONTAINER"',
    ] as $command) {
        expect($runs)->toContain($command);
    }

    $last = end($job['steps']);
    expect($last['if'])->toBe('always()');
});

test('docker top always asks ps for the pid, and the root check reads the uid column, never the header', function () {
    $workflow = dplSource('.github/workflows/production-checks.yml');

    // "docker top" hands the options to ps and refuses an output without the PID column.
    preg_match_all('/docker top "\$CONTAINER" -eo (\S+)/', $workflow, $calls);
    expect($calls[1])->not->toBeEmpty();
    foreach ($calls[1] as $columns) {
        expect(explode(',', $columns))->toContain('pid');
    }

    expect($workflow)
        ->not->toContain('-eo uid,args')
        ->toContain('processes="$(docker top "$CONTAINER" -eo pid,uid,args)"')
        // The header is skipped, the uid is the second field.
        ->toContain('body="$(printf \'%s\n\' "$processes" | tail -n +2)"')
        ->toContain('root="$(printf \'%s\n\' "$body" | awk \'$2 == 0\')"')
        // Not an empty list: the check cannot pass on nothing.
        ->toContain('for expected in supervisord frankenphp; do');
});

// ---------------------------------------------------------------------------------------------
// SITE_NOINDEX
// ---------------------------------------------------------------------------------------------

test('SITE_NOINDEX is off by default and on in the example of the private production', function () {
    expect(dplSource('config/app.php'))->toContain("'noindex' => (bool) env('SITE_NOINDEX', false),")
        ->and(config('app.noindex'))->toBeFalse();
    expect(dplSource('.env.example'))->toContain("\nSITE_NOINDEX=false\n");
    expect(dplSource('.env.production.example'))->toContain("\nSITE_NOINDEX=true\n");
});

test('with SITE_NOINDEX every response says noindex: a page, /up, an error, robots.txt', function (string $url, int $status) {
    config()->set('app.noindex', true);

    $this->get($url)->assertStatus($status)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
})->with([
    'home' => ['/', 200],
    'privacy' => ['/privacy', 200],
    'up' => ['/up', 200],
    'not found' => ['/a-page-that-does-not-exist', 404],
    'robots' => ['/robots.txt', 200],
]);

test('without SITE_NOINDEX the public pages and /up keep their own header', function () {
    config()->set('app.noindex', false);

    $this->get('/')->assertOk()->assertHeaderMissing('X-Robots-Tag');
    $this->get('/up')->assertOk()->assertHeaderMissing('X-Robots-Tag');
    // A public page decides by itself, as before (LinkPreview, NoIndexPrivatePages): no header.
    $this->get('/privacy')->assertOk()->assertHeaderMissing('X-Robots-Tag');
});

test('robots.txt is a route that follows the switch: off, the same text as the old file', function (bool $noindex, string $text) {
    config()->set('app.noindex', $noindex);

    $response = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect($response->getContent())->toBe($text);
})->with([
    'off' => [false, "User-agent: *\nDisallow:\n"],
    'on' => [true, "User-agent: *\nDisallow: /\n"],
]);

test('no robots.txt file in public/ hides the route any more', function () {
    expect(file_exists(public_path('robots.txt')))->toBeFalse()
        ->and(route('robots'))->toBe(url('/robots.txt'))
        ->and(RobotsController::OPEN)->toBe("User-agent: *\nDisallow:\n")
        ->and(RobotsController::PRIVATE)->toBe("User-agent: *\nDisallow: /\n");
    expect(dplSource('bootstrap/app.php'))->toContain('$middleware->append(NoIndexSite::class);');
});

test('robots.txt sets no cookie and starts no session, with the switch on and off', function (bool $noindex, string $text) {
    config()->set('app.noindex', $noindex);

    $response = $this->get('/robots.txt')->assertOk()->assertHeaderMissing('Set-Cookie');

    expect($response->getContent())->toBe($text)
        ->and($response->headers->getCookies())->toBe([]);
    // Outside the "web" group: no session, no cookie, no CSRF token for a crawler.
    expect(app('router')->getRoutes()->getByName('robots')?->gatherMiddleware())->not->toContain('web');
    if ($noindex) {
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
})->with([
    'off' => [false, "User-agent: *\nDisallow:\n"],
    'on' => [true, "User-agent: *\nDisallow: /\n"],
]);

// ---------------------------------------------------------------------------------------------
// The runbook (docs/deploy.md)
// ---------------------------------------------------------------------------------------------

test('docs/deploy.md has every section of the runbook', function () {
    $runbook = dplSource('docs/deploy.md');

    foreach ([
        '## 1. Che cosa c\'è e quanto costa',
        '## 2. Prima di cominciare',
        '## 3. Primo deploy passo per passo',
        '## 4. Posta con Gmail (fase privata)',
        '## 5. Foto',
        '## 6. Il primo professionista',
        '## 7. Deploy normale, log e job falliti',
        '## 8. Rollback',
        '## 9. Backup e ripristino di PostgreSQL (#63)',
        '## 10. Aggiornare un segreto',
        '## 11. Se la coda o lo scheduler si fermano',
        '## 12. Primo deploy: cose da controllare',
        '## 13. Da privato a pubblico',
    ] as $heading) {
        expect($runbook)->toContain("\n{$heading}\n");
    }

    expect($runbook)
        ->toContain('php artisan key:generate --show')
        ->toContain('php artisan db:seed --class=CategorySeeder --force')
        ->toContain('php artisan provider:create "Nome della vetrina" persona@example.test Città')
        ->toContain('php artisan queue:retry all')
        ->toContain('php artisan jobs:report-failed')
        ->toContain('https://render.com/docs/postgresql-backups');
    expect(dplSource('docs/production.md'))->toContain('[deploy.md](deploy.md)');
});

test('every variable that render.yaml leaves to the dashboard is explained in docs/deploy.md', function () {
    $runbook = dplSource('docs/deploy.md');
    $synced = array_keys(array_filter(dplEnv(), fn (array $variable) => ($variable['sync'] ?? null) === false));

    expect($synced)->not->toBeEmpty();
    foreach ($synced as $key) {
        // A row of the table of the variables, whatever the spaces the formatter puts.
        expect($runbook)->toMatch('/\|\s*`'.preg_quote($key, '/').'`\s*\|/');
    }
});

// CLAUDE.md is not in the repository (.gitignore): only the docs that GitHub sees.
test('the deploy docs hold no secret and no real contact', function (string $path) {
    $text = dplSource($path);
    $withoutExamples = (string) preg_replace('/[\w.+-]+@example\.(test|com)/', '', $text);

    expect($withoutExamples)->not->toMatch('/[\w.+-]+@[\w-]+\.[a-z]{2,}/i')
        ->and($text)->not->toMatch('/\+\d{2}[\s\d]{6,}/')
        ->and($text)->not->toMatch('/base64:[A-Za-z0-9+\/]{20,}/')
        // Keys that look like real ones: AWS, Scaleway.
        ->and($text)->not->toMatch('/\b(AKIA|SCW)[A-Z0-9]{16,}\b/')
        ->and($text)->not->toMatch('/postgres(ql)?:\/\/[^<\s]+:[^<\s@]+@/');
})->with(['docs/deploy.md', 'docs/production.md']);
