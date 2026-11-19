<?php

use App\Http\Middleware\PrivateProviderPage;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\User;
use App\Support\ProviderQrCode;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// The QR code of the provider's public page ("Il tuo link", docs/provider-link.md): SVG and PNG made
// by our server with bacon/bacon-qr-code, the address on APP_URL and nothing else, level M, a quiet
// zone of 4 modules, black on white. There is no QR decoder here: the tests compare every module of
// the images with the matrix the encoder gives for the exact address. The camera test is by hand.

const PQ_APP_URL = 'https://griot.example.test';
const PQ_OTHER_HOST = 'http://altro-indirizzo.example.test';

beforeEach(function () {
    config()->set('app.url', PQ_APP_URL);
    app()->setLocale('it');
});

function pqProvider(bool $published = true, string $slug = 'salone-awa'): Provider
{
    return Provider::factory()->create(['is_published' => $published, 'slug' => $slug]);
}

function pqGet(User $user, string $format, string $host = PQ_OTHER_HOST): TestResponse
{
    return test()->actingAs($user)->get($host.'/provider/qr.'.$format);
}

/**
 * The modules of the QR code of a text as the encoder makes them: rows of 0 and 1.
 *
 * @return list<list<int>>
 */
function pqMatrix(string $text, string $level = 'M'): array
{
    $rows = Encoder::encode($text, ErrorCorrectionLevel::$level(), 'UTF-8')->getMatrix()->getArray()->toArray();

    return array_map(fn (SplFixedArray $row) => array_map('intval', $row->toArray()), $rows);
}

/**
 * The modules of a PNG, read at the centre of each one, and whether the quiet zone is all white.
 *
 * @return array{0: list<list<int>>, 1: bool}
 */
function pqReadPng(string $png, int $modules, int $margin = ProviderQrCode::MARGIN): array
{
    $image = imagecreatefromstring($png);
    expect($image)->not->toBeFalse();
    $size = imagesx($image);
    $side = $size / ($modules + 2 * $margin);
    $dark = fn (int $x, int $y) => (imagecolorat($image, $x, $y) & 0xFF) < 128 ? 1 : 0;

    $rows = [];
    for ($y = 0; $y < $modules; $y++) {
        $row = [];
        for ($x = 0; $x < $modules; $x++) {
            $row[] = $dark((int) (($margin + $x + 0.5) * $side), (int) (($margin + $y + 0.5) * $side));
        }
        $rows[] = $row;
    }

    // Every pixel of the quiet zone, all around, is white.
    $edge = (int) floor($margin * $side) - 1;
    $white = $edge >= 0;
    for ($i = 0; $i < $size && $white; $i++) {
        for ($j = 0; $j <= $edge && $white; $j++) {
            $white = $dark($i, $j) === 0 && $dark($j, $i) === 0 && $dark($i, $size - 1 - $j) === 0 && $dark($size - 1 - $j, $i) === 0;
        }
    }

    return [$rows, $white];
}

/**
 * The modules of the SVG of bacon: one path in module units (M, L and Z only, even-odd fill), moved
 * by the quiet zone and scaled to the size. Each module is read at its centre.
 *
 * @return array{0: list<list<int>>, 1: string}
 */
function pqReadSvg(string $svg, int $modules): array
{
    expect(preg_match('/<path fill-rule="evenodd" d="([^"]+)"/', $svg, $path))->toBe(1);
    expect(preg_match('/<g transform="scale\(([\d.]+)\)"><g transform="translate\((\d+),(\d+)\)">/', $svg, $frame))->toBe(1);

    // The edges of every closed shape.
    $edges = [];
    preg_match_all('/([MLZ])(?:(-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?))?/', $path[1], $commands, PREG_SET_ORDER);
    [$start, $last] = [null, null];
    foreach ($commands as $command) {
        if ($command[1] === 'Z') {
            $edges[] = [$last, $start];
            $last = $start;

            continue;
        }
        $point = [(float) $command[2], (float) $command[3]];
        if ($command[1] === 'M') {
            [$start, $last] = [$point, $point];
        } else {
            $edges[] = [$last, $point];
            $last = $point;
        }
    }

    $rows = [];
    for ($y = 0; $y < $modules; $y++) {
        $row = [];
        for ($x = 0; $x < $modules; $x++) {
            [$px, $py] = [$x + 0.5, $y + 0.5];
            $inside = false;
            foreach ($edges as [[$x1, $y1], [$x2, $y2]]) {
                if (($y1 > $py) !== ($y2 > $py) && $px < ($x2 - $x1) * ($py - $y1) / ($y2 - $y1) + $x1) {
                    $inside = ! $inside;
                }
            }
            $row[] = $inside ? 1 : 0;
        }
        $rows[] = $row;
    }

    return [$rows, "scale {$frame[1]} translate {$frame[2]},{$frame[3]}"];
}

function pqSource(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

// ---------------------------------------------------------------------------------------------
// The files
// ---------------------------------------------------------------------------------------------

test('the SVG: its type, a download without personal data, never stored nor indexed', function () {
    $response = pqGet(pqProvider()->user, 'svg')->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('Content-Disposition', 'attachment; filename="griot-qr.svg"')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($response->getContent())
        ->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
        ->toContain('width="400" height="400" viewBox="0 0 400 400"')
        // Black on white.
        ->toContain('fill="#ffffff"')
        ->toContain('fill="#000000"');
});

test('the PNG: its type, 1024 px, a download without personal data, never stored nor indexed', function () {
    $response = pqGet(pqProvider()->user, 'png')->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Disposition', 'attachment; filename="griot-qr.png"')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $size = getimagesizefromstring((string) $response->getContent());
    expect($size)->not->toBeFalse()
        ->and([$size[0], $size[1], $size['mime']])->toBe([1024, 1024, 'image/png']);
});

test('the PNG holds exactly the modules of the address, with a white quiet zone of 4 modules', function () {
    $expected = pqMatrix(PQ_APP_URL.'/providers/salone-awa');
    [$modules, $white] = pqReadPng((string) pqGet(pqProvider()->user, 'png')->getContent(), count($expected));

    expect($modules)->toBe($expected)
        ->and($white)->toBeTrue();
});

test('the SVG holds exactly the modules of the address, moved by a quiet zone of 4 modules', function () {
    $expected = pqMatrix(PQ_APP_URL.'/providers/salone-awa');
    [$modules, $frame] = pqReadSvg((string) pqGet(pqProvider()->user, 'svg')->getContent(), count($expected));

    expect($modules)->toBe($expected)
        // 400 / (modules + 2 × 4), rounded by bacon to 3 decimals.
        ->and($frame)->toBe('scale '.round(400 / (count($expected) + 8), 3).' translate 4,4');
});

test('the address is the one of APP_URL, not of the request, without parameters, and the dashboard\'s', function () {
    $provider = pqProvider();
    $png = (string) pqGet($provider->user, 'png', 'http://indirizzo-vecchio.example.test')->getContent();
    $public = PQ_APP_URL.'/providers/salone-awa';

    expect(ProviderQrCode::content($provider))->toBe($public)->not->toContain('?')->not->toContain('#');
    // The images follow the address: another one, or a parameter more, gives other modules.
    expect(pqReadPng($png, count(pqMatrix($public)))[0])->toBe(pqMatrix($public))
        ->and(pqMatrix($public))->not->toBe(pqMatrix('http://indirizzo-vecchio.example.test/providers/salone-awa'))
        ->and(pqMatrix($public))->not->toBe(pqMatrix($public.'?utm_source=qr'));

    $this->actingAs($provider->user)->get(PQ_OTHER_HOST.'/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('provider.public_url', $public));
});

test('level M, not more: a longer address keeps big modules', function () {
    // The levels of bacon are single instances: the same object for the same level.
    expect(ProviderQrCode::errorCorrection())->toBe(ErrorCorrectionLevel::M())
        ->and(ProviderQrCode::MARGIN)->toBe(4)
        ->and(ProviderQrCode::PNG_SIZE)->toBe(1024);
    // The same address in H has more modules: the PNG would not match the matrix of M.
    expect(count(pqMatrix(PQ_APP_URL.'/providers/salone-awa', 'H')))->toBeGreaterThan(count(pqMatrix(PQ_APP_URL.'/providers/salone-awa')));
});

test('GD makes the PNG, never Imagick', function () {
    expect(pqSource('app/Support/ProviderQrCode.php'))
        ->toContain('new GDLibRenderer(self::PNG_SIZE, self::MARGIN)')
        ->toContain('new SvgImageBackEnd')
        ->not->toContain('ImagickImageBackEnd');
    expect(extension_loaded('gd'))->toBeTrue()
        ->and(pqSource('Dockerfile'))->toMatch('/install-php-extensions [^\n]*\bgd\b/');
});

// ---------------------------------------------------------------------------------------------
// Who gets it
// ---------------------------------------------------------------------------------------------

test('a guest goes to the login page, an unverified user to the verification, a user without a provider to the setup', function (string $format) {
    $this->get('/provider/qr.'.$format)->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    Provider::factory()->published()->create(['user_id' => $unverified->id]);
    $this->actingAs($unverified)->get('/provider/qr.'.$format)->assertRedirect(route('verification.notice'));

    $this->actingAs(User::factory()->create())->get('/provider/qr.'.$format)->assertRedirect(route('provider.setup'));
})->with(['svg', 'png']);

test('an unpublished page has no QR code: 404', function (string $format) {
    pqGet(pqProvider(published: false)->user, $format)->assertNotFound();
})->with(['svg', 'png']);

test('no id and no slug in the address: always the provider of the session', function () {
    $mine = pqProvider(slug: 'salone-awa');
    $other = pqProvider(slug: 'altro-salone');

    foreach (['svg', 'png'] as $format) {
        $route = app('router')->getRoutes()->getByName("provider.qr.{$format}");
        expect($route?->uri())->toBe("provider/qr.{$format}")
            ->and($route?->parameterNames())->toBe([])
            ->and($route?->gatherMiddleware())->toContain('auth', 'verified', PrivateProviderPage::class);
    }

    // Whatever is added to the address, the QR code is the provider's own.
    $png = (string) $this->actingAs($mine->user)->get('/provider/qr.png?slug=altro-salone&provider='.$other->id)->getContent();
    $expected = pqMatrix(PQ_APP_URL.'/providers/salone-awa');
    expect(pqReadPng($png, count($expected))[0])->toBe($expected);
});

test('the private pages keep their headers', function () {
    $provider = pqProvider();
    $booking = Booking::factory()->for($provider)->create();

    $this->actingAs($provider->user)->get('/provider/bookings')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get('/bookings/'.$booking->public_token)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

// ---------------------------------------------------------------------------------------------
// The card and the texts
// ---------------------------------------------------------------------------------------------

test('the card shows the QR code only when published, with its own text, the downloads and the warning', function () {
    $card = pqSource('resources/js/components/provider-link-card.tsx');
    $start = (int) strpos($card, '{published ? (');
    $else = (int) strpos($card, ') : (', $start);
    expect($start)->toBeGreaterThan(0)
        ->and(substr($card, $start, $else - $start))->toContain('<QrCode />')
        ->and(substr_count($card, '<QrCode />'))->toBe(1);

    preg_match('/function QrCode\(\) \{[\s\S]*\n\}/', $card, $qr);
    expect($qr[0] ?? '')
        ->toMatch("/<img\\s+src=\\{qrSvg\\(\\)\\.url\\}\\s+alt=\\{t\\('provider\\.link\\.qr_alt'\\)\\}\\s+width=\\{192\\}\\s+height=\\{192\\}/")
        // At least 160 px, never wider than the card.
        ->toContain('className="h-auto w-48 max-w-full min-w-40 rounded-md border bg-white"')
        ->toMatch("/<a href=\\{qrSvg\\(\\)\\.url\\} download>\\s*<Download aria-hidden=\"true\" \\/>\\s*\\{t\\('provider\\.link\\.qr_svg'\\)\\}\\s*<\\/a>/")
        ->toMatch("/<a href=\\{qrPng\\(\\)\\.url\\} download>\\s*<Download aria-hidden=\"true\" \\/>\\s*\\{t\\('provider\\.link\\.qr_png'\\)\\}\\s*<\\/a>/")
        ->toContain("{t('provider.link.qr_warning')}")
        ->toMatch('/<section\s+aria-labelledby="provider-qr-title"/')
        ->toContain('<h3 id="provider-qr-title" className="font-semibold">')
        ->not->toContain('target="_blank"');
    expect($card)
        ->toContain("import { png as qrPng, svg as qrSvg } from '@/routes/provider/qr';")
        ->not->toMatch('/aria-label(?!ledby)/')
        ->not->toMatch('/animate-|transition|motion-/');
});

test('the texts in the three languages, with the French spaces', function () {
    $expected = [
        'it' => ['QR code', 'QR code della tua pagina Griot', 'Scarica SVG', 'Scarica PNG', "Se cambi l'indirizzo della tua pagina, i QR code già stampati non funzioneranno più."],
        'fr' => ['QR code', 'QR code de votre page Griot', 'Télécharger le SVG', 'Télécharger le PNG', "Si vous changez l'adresse de votre page, les QR codes déjà imprimés ne fonctionneront plus."],
        'en' => ['QR code', 'QR code of your Griot page', 'Download SVG', 'Download PNG', 'If you change the address of your page, QR codes already printed will stop working.'],
    ];

    foreach ($expected as $locale => $texts) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

        foreach (['qr_title', 'qr_alt', 'qr_svg', 'qr_png', 'qr_warning'] as $i => $key) {
            expect($json["provider.link.{$key}"] ?? null)->toBe($texts[$i], "{$locale} {$key}");
            if ($locale === 'fr') {
                expect(preg_match('/ [:?!;](?![a-z_])/', $json["provider.link.{$key}"]))->toBe(0, "fr {$key}");
            }
        }
    }
});

// ---------------------------------------------------------------------------------------------
// The library
// ---------------------------------------------------------------------------------------------

test('composer.json declares the library already locked by Fortify, and the QR sources call no other service', function () {
    $composer = json_decode(pqSource('composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode(pqSource('composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $bacon = collect($lock['packages'])->firstWhere('name', 'bacon/bacon-qr-code');

    expect($composer['require']['bacon/bacon-qr-code'] ?? null)->toBe('^3.1')
        ->and($bacon['version'] ?? null)->toStartWith('v3.');

    foreach (['app/Support/ProviderQrCode.php', 'app/Http/Controllers/ProviderQrCodeController.php'] as $file) {
        expect(pqSource($file))
            ->not->toMatch('#https?://#')
            ->not->toContain('Http::')
            ->not->toContain('curl_')
            ->not->toContain('file_get_contents')
            ->not->toContain('fopen');
    }
});
