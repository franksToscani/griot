<?php

use App\Models\Booking;
use App\Models\Provider;
use App\Support\LinkPreview;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// "Il tuo link" (docs/production.md): the whole address of the provider's public page in the
// dashboard, with Copy, Share and WhatsApp once the page is published, and under the address field
// of the profile. Always on APP_URL, never on the host of the request: when the domain changes,
// APP_URL changes and every link follows. Fake addresses only.

const PL_APP_URL = 'https://griot.example.test';

beforeEach(function () {
    config()->set('app.url', PL_APP_URL);
    app()->setLocale('it');
});

function plSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * The dashboard of a provider, asked from another host than APP_URL (an old address, a proxy).
 */
function plDashboard(Provider $provider, string $host = 'http://altro-indirizzo.example.test'): TestResponse
{
    return test()->actingAs($provider->user)->get($host.'/dashboard');
}

// ---------------------------------------------------------------------------------------------
// The address
// ---------------------------------------------------------------------------------------------

test('public_url is on APP_URL even when the request comes from another host', function () {
    $provider = Provider::factory()->published()->create(['slug' => 'salone-awa']);

    plDashboard($provider)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('provider.public_url', PL_APP_URL.'/providers/salone-awa'));

    // The route alone would follow the request: that is what PublicUrl avoids.
    expect(route('providers.show', 'salone-awa'))->not->toStartWith(PL_APP_URL);
    expect((string) file_get_contents(app_path('Http/Resources/ProviderAreaResource.php')))
        ->toContain("'public_url' => PublicUrl::to(route('providers.show', \$this->slug, absolute: false)),");
});

test('a new APP_URL changes the link at once, with or without a final slash', function (string $appUrl, string $expected) {
    config()->set('app.url', $appUrl);
    $provider = Provider::factory()->published()->create(['slug' => 'salone-awa']);

    plDashboard($provider)->assertInertia(fn (Assert $page) => $page->where('provider.public_url', $expected));
})->with([
    'new domain' => ['https://nuovo-dominio.example.test', 'https://nuovo-dominio.example.test/providers/salone-awa'],
    'final slash' => ['https://nuovo-dominio.example.test/', 'https://nuovo-dominio.example.test/providers/salone-awa'],
]);

test('PublicUrl joins APP_URL and a path with one slash, whatever the slashes given', function () {
    expect(PublicUrl::to(''))->toBe(PL_APP_URL)
        ->and(PublicUrl::to('/'))->toBe(PL_APP_URL)
        ->and(PublicUrl::to('providers/x'))->toBe(PL_APP_URL.'/providers/x')
        ->and(PublicUrl::to('/providers/x/'))->toBe(PL_APP_URL.'/providers/x')
        ->and(PublicUrl::to('og-default.png'))->toBe(PL_APP_URL.'/og-default.png');
});

test('LinkPreview still writes its addresses on APP_URL, through PublicUrl', function () {
    $provider = Provider::factory()->published()->create(['slug' => 'salone-awa']);

    $html = (string) $this->get('http://altro-indirizzo.example.test/providers/salone-awa')->assertOk()->getContent();
    expect($html)
        ->toContain('<link rel="canonical" href="'.PL_APP_URL.'/providers/salone-awa">')
        ->toContain('<meta property="og:url" content="'.PL_APP_URL.'/providers/salone-awa">');
    // Every address of the preview's tags is on APP_URL, none on the host of the request.
    preg_match_all('/<(?:link rel="canonical"|meta (?:property|name)="(?:og|twitter):[^"]+")[^>]*>/', $html, $tags);
    expect($tags[0])->not->toBeEmpty();
    foreach ($tags[0] as $tag) {
        expect($tag)->not->toContain('altro-indirizzo.example.test');
    }

    $source = (string) file_get_contents(app_path('Support/LinkPreview.php'));
    expect($source)->toContain('PublicUrl::to(request()->path())')
        ->toContain('PublicUrl::to(self::DEFAULT_IMAGE)')
        ->not->toContain('function absolute(');
    expect(app(LinkPreview::class))->toBeInstanceOf(LinkPreview::class);
});

test('the profile page gets the origin, and its whole link is the dashboard\'s', function () {
    $provider = Provider::factory()->published()->create(['slug' => 'salone-awa']);
    $dashboardUrl = null;

    plDashboard($provider)->assertInertia(function (Assert $page) use (&$dashboardUrl) {
        $dashboardUrl = $page->toArray()['props']['provider']['public_url'];
    });
    $this->actingAs($provider->user)->get('http://altro-indirizzo.example.test/provider/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('provider/edit')->where('app_url', PL_APP_URL));

    // The page writes app_url followed by the route of Wayfinder, "/providers/<slug>".
    expect(PL_APP_URL.'/providers/'.$provider->slug)->toBe($dashboardUrl);
    expect(plSource('js/pages/provider/edit.tsx'))
        ->toContain("import { show } from '@/routes/providers';")
        ->toContain('url: `${app_url}${show(slug.trim()).url}`,')
        ->toContain('const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;')
        ->toContain('aria-describedby="slug-help slug-address"')
        ->toMatch('/<p\s+id="slug-address"\s+className="text-sm break-all"\s*>/')
        // The warning about the old links stays.
        ->toContain("{t('provider.edit.slug_help')}");
    // The same rule as the server.
    expect((string) file_get_contents(app_path('Http/Requests/UpdateProviderRequest.php')))->toContain("'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',");
});

// ---------------------------------------------------------------------------------------------
// Only in the provider's area
// ---------------------------------------------------------------------------------------------

test('public_url is never in the public pages nor in the shared props', function () {
    $provider = Provider::factory()->published()->create(['slug' => 'salone-awa']);
    $booking = Booking::factory()->for($provider)->create();

    // Also for the provider logged in, on the public pages.
    foreach (['/', '/providers', '/providers/salone-awa', '/privacy', '/bookings/'.$booking->public_token] as $path) {
        $this->actingAs($provider->user)->get($path)->assertOk()->assertInertia(function (Assert $page) {
            $props = $page->toArray()['props'];
            // (toContain() takes several needles, not a message: one needle at a time.)
            expect(json_encode($props, JSON_THROW_ON_ERROR))->not->toContain('public_url')
                ->and($props)->not->toHaveKey('app_url');
            expect(array_keys($props['provider'] ?? []))->not->toContain('public_url');
        });
    }
    // The shared props of the area carry only the slug, as before.
    plDashboard($provider)->assertInertia(fn (Assert $page) => $page
        ->where('provider_nav.public_slug', 'salone-awa')
        ->missing('provider_nav.public_url')
        ->missing('public_url'));
});

test('the private pages keep their headers', function () {
    $provider = Provider::factory()->published()->create();
    $booking = Booking::factory()->for($provider)->create();

    $this->actingAs($provider->user)->get('/provider/bookings')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get('/bookings/'.$booking->public_token)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

// ---------------------------------------------------------------------------------------------
// The card
// ---------------------------------------------------------------------------------------------

test('the dashboard shows the card under the status, with the address and the publication', function (bool $published) {
    $provider = Provider::factory()->create(['is_published' => $published, 'slug' => 'salone-awa']);

    plDashboard($provider)->assertInertia(fn (Assert $page) => $page
        ->where('provider.is_published', $published)
        ->where('provider.public_url', PL_APP_URL.'/providers/salone-awa'));

    expect(plSource('js/pages/dashboard.tsx'))
        ->toContain("import { ProviderLinkCard } from '@/components/provider-link-card';")
        ->toMatch('/<\/Card>\s*<ProviderLinkCard\s+url=\{provider\.public_url\}\s+published=\{provider\.is_published\}\s*\/>/');
})->with(['published' => [true], 'unpublished' => [false]]);

test('unpublished: only the address and the warning; published: Copy, Share and WhatsApp', function () {
    $card = plSource('js/components/provider-link-card.tsx');
    // The two branches of {published ? (...) : (...)}.
    $start = (int) strpos($card, '{published ? (');
    $else = (int) strpos($card, ') : (', $start);
    $end = (int) strpos($card, '</CardContent>', $else);
    expect($start)->toBeGreaterThan(0)->and($else)->toBeGreaterThan($start)->and($end)->toBeGreaterThan($else);
    [$whenPublished, $whenNot] = [substr($card, $start, $else - $start), substr($card, $else, $end - $else)];

    expect($whenNot)
        ->toContain("{t('provider.link.unpublished')}")
        ->toContain('id="provider-link-unpublished"')
        ->not->toContain('<Button')
        ->not->toContain('<a');
    expect($whenPublished)
        ->toContain("{t('provider.link.copy')}")
        ->toContain("{t('provider.link.share')}")
        ->toContain("{t('provider.link.whatsapp')}")
        ->not->toContain('provider.link.unpublished');
    // The address is there in both cases, before the choice.
    expect(strpos($card, 'id="provider-link"'))->toBeLessThan(strpos($card, '{published ? ('))
        ->and($card)->toMatch('/aria-describedby=\{\s*published \? undefined : \'provider-link-unpublished\'\s*\}/');
});

test('the address is a labelled read-only field, on several lines, that wraps', function () {
    expect(plSource('js/components/provider-link-card.tsx'))
        ->toMatch('/<Label htmlFor="provider-link">\s*\{t\(\'provider\.link\.label\'\)\}\s*<\/Label>/')
        ->toMatch('/<Textarea\s+ref=\{field\}\s+id="provider-link"\s+readOnly\s+rows=\{2\}\s+value=\{url\}/')
        ->toContain('className="min-h-0 resize-none break-all"');
});

test('Copy: the clipboard, else the address selected with a message; the result in a status line always there', function () {
    $card = plSource('js/components/provider-link-card.tsx');

    expect($card)
        ->toMatch('/try \{\s*await navigator\.clipboard\.writeText\(url\);\s*setStatus\(t\(\'provider\.link\.copied\'\)\);\s*\} catch \{/')
        ->toMatch('/field\.current\?\.focus\(\);\s*field\.current\?\.select\(\);\s*setStatus\(t\(\'provider\.link\.copy_fallback\'\)\);/')
        ->toMatch('/<p role="status" className="text-sm">\s*\{status\}\s*<\/p>/')
        ->toMatch('/<Button\s+type="button"\s+variant="outline"\s+onClick=\{copy\}\s*>/');
});

test('Share: only where the browser has it, a closed sheet says nothing', function () {
    $card = plSource('js/components/provider-link-card.tsx');

    expect($card)
        ->toContain("const canShare = typeof navigator !== 'undefined' && 'share' in navigator;")
        ->toMatch('/\{canShare && \(\s*<Button\s+type="button"\s+variant="outline"\s+onClick=\{share\}\s*>/')
        ->toMatch("/if \\(error instanceof DOMException && error\\.name === 'AbortError'\\) \\{\\s*return;\\s*\\}\\s*setStatus\\(t\\('provider\\.link\\.share_failed'\\)\\);/")
        // The link only: no data of the provider's area.
        ->toContain('await navigator.share({ url });');
    // navigator.share is called in share() only, and share() only from the button under canShare.
    expect(substr_count($card, 'navigator.share('))->toBe(1)
        ->and(substr_count($card, 'onClick={share}'))->toBe(1);
});

test('WhatsApp: the text of the language, through lib/whatsapp.ts, in a new tab without the Referer', function () {
    expect(plSource('js/components/provider-link-card.tsx'))
        ->toContain("import { whatsappShareLink } from '@/lib/whatsapp';")
        ->toMatch("/href=\\{whatsappShareLink\\(\\s*t\\('provider\\.link\\.whatsapp_text', \\{\\s*link: url,\\s*\\}\\),\\s*\\)\\}\\s*target=\"_blank\"\\s*rel=\"noopener noreferrer\"\\s*>/")
        ->toMatch("/\\{t\\('provider\\.link\\.whatsapp'\\)\\}\\s*<NewTabHint \\/>/")
        ->not->toContain('wa.me');

    // The address of WhatsApp without a number, the text encoded once.
    $script = sys_get_temp_dir().'/griot-whatsapp-'.bin2hex(random_bytes(6)).'.mts';
    file_put_contents($script, "import * as h from '".resource_path('js/lib/whatsapp.ts')."';\nconsole.log(JSON.stringify(h.whatsappShareLink(".json_encode('Prenota da me su Griot: '.PL_APP_URL.'/providers/salone-awa?x=1&y=2').')));');
    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }
    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(json_decode($result->output()))->toBe('https://wa.me/?text='.rawurlencode('Prenota da me su Griot: '.PL_APP_URL.'/providers/salone-awa?x=1&y=2'));
});

test('no hand-written aria-label, icons hidden, the kit\'s buttons (44 px on phones, focus ring), nothing that moves', function () {
    $card = plSource('js/components/provider-link-card.tsx');

    expect($card)->not->toMatch('/aria-label(?!ledby)/')
        // Copy, Share, WhatsApp and the two downloads of the QR code (ProviderQrCodeTest).
        ->and(substr_count($card, 'aria-hidden="true"'))->toBe(5)
        ->and($card)->not->toMatch('/animate-|transition|motion-/')
        ->not->toContain('<button');
    expect(plSource('js/pages/provider/edit.tsx'))->not->toMatch('/aria-label(?!ledby)/');
    // The kit's Button: at least 44 px below 768 px, a visible ring on focus.
    expect(plSource('js/components/ui/button.tsx'))->toContain('max-md:min-h-11 max-md:min-w-11')->toContain('focus-visible:ring-[3px]');
});

// ---------------------------------------------------------------------------------------------
// Texts
// ---------------------------------------------------------------------------------------------

test('the texts in the three languages, with the French spaces', function () {
    $nb = "\u{00A0}";
    $expected = [
        'it' => [
            'provider.link.title' => 'Il tuo link',
            'provider.link.copied' => 'Link copiato.',
            'provider.link.copy_fallback' => 'Premi Ctrl+C o tieni premuto per copiare.',
            'provider.link.whatsapp_text' => 'Prenota da me su Griot: :link',
            'provider.link.unpublished' => 'La tua pagina non è ancora pubblicata: chi apre il link vede « Pagina non trovata ».',
            'provider.edit.slug_address' => 'Indirizzo completo: :url',
        ],
        'fr' => [
            'provider.link.title' => 'Votre lien',
            'provider.link.copied' => 'Lien copié.',
            'provider.link.copy_fallback' => 'Appuyez sur Ctrl+C ou maintenez le doigt appuyé pour copier.',
            'provider.link.whatsapp_text' => "Réserve chez moi sur Griot{$nb}: :link",
            'provider.link.unpublished' => "Votre page n'est pas encore publiée{$nb}: qui ouvre le lien voit «{$nb}Page introuvable{$nb}».",
            'provider.edit.slug_address' => "Adresse complète{$nb}: :url",
        ],
        'en' => [
            'provider.link.title' => 'Your link',
            'provider.link.copied' => 'Link copied.',
            'provider.link.copy_fallback' => 'Press Ctrl+C, or touch and hold, to copy.',
            'provider.link.whatsapp_text' => 'Book with me on Griot: :link',
            'provider.link.unpublished' => 'Your page is not published yet: anyone who opens the link sees “Page not found”.',
            'provider.edit.slug_address' => 'Full address: :url',
        ],
    ];
    $keys = ['title', 'description', 'label', 'copy', 'copied', 'copy_fallback', 'share', 'share_failed', 'whatsapp', 'whatsapp_text', 'unpublished'];

    foreach ($expected as $locale => $texts) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

        foreach ($texts as $key => $text) {
            expect($json[$key] ?? null)->toBe($text, "{$locale} {$key}");
        }
        foreach ([...array_map(fn (string $key) => "provider.link.{$key}", $keys), 'provider.edit.slug_address'] as $key) {
            expect($json)->toHaveKey($key);
            if ($locale === 'fr') {
                expect(preg_match('/ [:?!;](?![a-z_])/', $json[$key]))->toBe(0, "fr {$key}");
            }
        }
        // The warning quotes the title of the 404 page, in each language.
        expect($json['provider.link.unpublished'])->toContain($json['errors.404.title']);
    }
});
