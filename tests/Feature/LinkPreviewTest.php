<?php

use App\Models\Booking;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Support\LinkPreview;
use Illuminate\Testing\TestResponse;

// The previews of shared links (issue #138): the tags are in the HTML of the first response, for
// the crawlers of WhatsApp, Instagram and Facebook, which do not run JavaScript. Every request here
// is a normal GET, without the X-Inertia header: the raw HTML, not what a browser would build.

beforeEach(function () {
    // In production both come from APP_URL (config/app.php, config/filesystems.php).
    config(['app.url' => 'https://griot.example', 'filesystems.disks.media.url' => 'https://griot.example/storage']);
});

/**
 * A published provider with services in the given categories (slugs).
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<string>  $categories
 */
function lpProvider(array $attributes = [], array $categories = ['treccine']): Provider
{
    $provider = Provider::factory()->published()->create([
        'slug' => 'trecce-sahel',
        'name' => 'Trecce Sahel',
        'city' => 'Bologna',
        'bio' => 'Treccine africane, box braids e knotless.',
        'avatar_path' => null,
        ...$attributes,
    ]);

    foreach ($categories as $slug) {
        $category = Category::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        Service::factory()->for($provider)->for($category)->create();
    }

    return $provider;
}

/**
 * The content of the meta tags of a page (name or property), as name => content, decoded.
 *
 * @return array<string, string>
 */
function lpTags(string $html): array
{
    preg_match('#<head>(.*?)</head>#s', $html, $head);
    preg_match_all('#<meta (?:name|property)="([^"]+)" content="([^"]*)">#', $head[1] ?? '', $found, PREG_SET_ORDER);
    $tags = [];

    foreach ($found as [, $name, $content]) {
        $tags[$name] = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
    }

    if (preg_match('#<link rel="canonical" href="([^"]+)">#', $head[1] ?? '', $canonical) === 1) {
        $tags['canonical'] = html_entity_decode($canonical[1], ENT_QUOTES | ENT_HTML5);
    }

    return $tags;
}

/**
 * The <title> elements of a page.
 *
 * @return list<string>
 */
function lpTitles(string $html): array
{
    preg_match_all('#<title[^>]*>(.*?)</title>#s', $html, $titles);

    return array_map(fn (string $title) => html_entity_decode($title, ENT_QUOTES | ENT_HTML5), $titles[1]);
}

/**
 * A normal GET, as a crawler sends it: no X-Inertia, maybe a language cookie.
 */
function lpGet(string $url, ?string $locale = null): TestResponse
{
    $test = test();

    return ($locale === null ? $test : $test->withCookie('locale', $locale))->get($url)->assertOk();
}

// ---------------------------------------------------------------------------------------------
// The provider's page
// ---------------------------------------------------------------------------------------------

test('a published provider\'s page has its preview in the raw HTML, in each language', function (string $locale, string $title, string $ogLocale) {
    lpProvider();

    $html = (string) lpGet('/providers/trecce-sahel', $locale)->assertHeaderMissing('X-Robots-Tag')->getContent();
    $tags = lpTags($html);

    expect(lpTitles($html))->toBe([$title.' - Griot'])
        ->and($html)->toContain('<html lang="'.$locale.'"')
        ->not->toContain('name="robots"');

    expect($tags)->toMatchArray([
        'description' => 'Treccine africane, box braids e knotless.',
        'canonical' => 'https://griot.example/providers/trecce-sahel',
        'og:type' => 'profile',
        'og:site_name' => 'Griot',
        'og:title' => $title,
        'og:description' => 'Treccine africane, box braids e knotless.',
        'og:url' => 'https://griot.example/providers/trecce-sahel',
        'og:locale' => $ogLocale,
        'og:image' => 'https://griot.example/og-default.png',
        'og:image:type' => 'image/png',
        'og:image:width' => '1200',
        'og:image:height' => '630',
        'og:image:alt' => __('link_preview.image_alt', [], $locale),
        'twitter:card' => 'summary_large_image',
        'twitter:title' => $title,
        'twitter:description' => 'Treccine africane, box braids e knotless.',
        'twitter:image' => 'https://griot.example/og-default.png',
    ]);
})->with([
    'it' => ['it', 'Trecce Sahel - Treccine a Bologna', 'it_IT'],
    'fr' => ['fr', 'Trecce Sahel - Tresses à Bologna', 'fr_FR'],
    'en' => ['en', 'Trecce Sahel - Braids in Bologna', 'en_GB'],
]);

test('with a photo, the preview shows it, at its absolute address', function () {
    lpProvider(['avatar_path' => 'avatars/salone.webp']);

    $tags = lpTags((string) lpGet('/providers/trecce-sahel')->getContent());

    expect($tags['og:image'])->toBe('https://griot.example/storage/avatars/salone.webp')
        ->and($tags['og:image:alt'])->toBe('Foto di Trecce Sahel')
        ->and($tags['twitter:card'])->toBe('summary')
        ->and($tags['twitter:image'])->toBe($tags['og:image'])
        // Its size and type are not known here: no tag says them.
        ->and($tags)->not->toHaveKey('og:image:width')
        ->not->toHaveKey('og:image:type');
});

test('a hostile bio stays text: quotes and < > do not break the HTML', function () {
    lpProvider(['name' => 'Salone "Awa" & Co', 'bio' => '<script>alert("x")</script> Treccine "belle" & <b>care</b> > tutte'], []);

    $html = (string) lpGet('/providers/trecce-sahel')->getContent();
    preg_match('#<head>(.*?)</head>#s', $html, $head);
    $tags = lpTags($html);

    expect($head[1])->not->toContain('<script>alert')
        ->not->toContain('"belle"')
        ->not->toContain('<b>care');
    // The tags were not cut short by a quote: each one is whole, and the text is plain.
    expect($tags['description'])->toBe('alert("x") Treccine "belle" & care > tutte')
        ->and($tags['og:title'])->toBe('Salone "Awa" & Co a Bologna')
        ->and(lpTitles($html))->toBe(['Salone "Awa" & Co a Bologna - Griot']);
});

test('a long bio is cut on a word, on one line, with an ellipsis', function () {
    $bio = "Prima riga del salone.\n\n".str_repeat('Treccine africane fatte con cura e pazienza, ', 8);
    lpProvider(['bio' => $bio]);

    $description = lpTags((string) lpGet('/providers/trecce-sahel')->getContent())['description'];

    expect(mb_strlen($description))->toBeLessThanOrEqual(LinkPreview::DESCRIPTION_LENGTH)
        ->and($description)->toStartWith('Prima riga del salone. Treccine')
        ->toEndWith('…')
        ->not->toContain("\n");
    // The start of the bio, spaces made single, cut after a whole word.
    $plain = (string) preg_replace('/\s+/', ' ', $bio);
    $kept = mb_substr($description, 0, -1);
    expect(str_starts_with($plain, $kept))->toBeTrue()
        ->and(mb_substr($plain, mb_strlen($kept), 1))->toBeIn([' ', ',']);
    expect(LinkPreview::excerpt($bio))->toBe($description);
});

test('an empty bio gets a sentence with the name and the city', function (string $locale, string $sentence) {
    lpProvider(['bio' => null]);

    $tags = lpTags((string) lpGet('/providers/trecce-sahel', $locale)->getContent());

    expect($tags['description'])->toBe($sentence)
        ->and($tags['og:description'])->toBe($sentence);
})->with([
    'it' => ['it', 'Prenota online da Trecce Sahel a Bologna.'],
    'fr' => ['fr', 'Réservez en ligne chez Trecce Sahel à Bologna.'],
    'en' => ['en', 'Book online with Trecce Sahel in Bologna.'],
]);

test('the title names at most two categories, and no category gives name and city only', function () {
    lpProvider([], ['treccine', 'barbiere', 'nails']);
    $tags = lpTags((string) lpGet('/providers/trecce-sahel')->getContent());
    expect($tags['og:title'])->toMatch('/^Trecce Sahel - [^,]+, [^,]+… a Bologna$/');

    Provider::query()->delete();
    lpProvider(['slug' => 'senza-servizi'], []);
    expect(lpTags((string) lpGet('/providers/senza-servizi')->getContent())['og:title'])->toBe('Trecce Sahel a Bologna');
});

test('a provider that is not published, or does not exist, is a 404 that says nothing of it', function (string $slug) {
    Provider::factory()->create(['slug' => 'nascosto', 'name' => 'Salone Nascosto', 'city' => 'Lecce', 'bio' => 'Bio segreta', 'is_published' => false]);

    $html = (string) $this->get("/providers/{$slug}")->assertNotFound()->getContent();

    expect($html)->not->toContain('Salone Nascosto')
        ->not->toContain('Lecce')
        ->not->toContain('Bio segreta')
        ->not->toContain('og:')
        ->toContain('<meta name="robots" content="noindex">');
})->with(['nascosto', 'non-esiste']);

// ---------------------------------------------------------------------------------------------
// The other public pages
// ---------------------------------------------------------------------------------------------

test('the list and the privacy page have the preview of the site', function (string $url, string $title, string $description) {
    $html = (string) lpGet($url)->assertHeaderMissing('X-Robots-Tag')->getContent();
    $tags = lpTags($html);

    expect(lpTitles($html))->toBe([$title.' - Griot'])
        ->and($tags['og:title'])->toBe($title)
        ->and($tags['description'])->toBe($description)
        ->and($tags['og:type'])->toBe('website')
        ->and($tags['canonical'])->toBe('https://griot.example/'.ltrim($url, '/'))
        ->and($tags['og:image'])->toBe('https://griot.example/og-default.png');
})->with([
    'list' => ['/providers', 'Trova un professionista', 'Trova un professionista vicino a te e prenota online, senza creare un account.'],
    'privacy' => ['/privacy', 'Informativa sulla privacy', 'Come Griot usa i dati di chi prenota e per quanto tempo li conserva.'],
]);

test('the addresses are built on APP_URL, not on the host of the request', function () {
    lpProvider();

    $html = (string) $this->get('http://altro-host.example/providers/trecce-sahel')->assertOk()->getContent();

    expect(lpTags($html)['og:url'])->toBe('https://griot.example/providers/trecce-sahel')
        ->and($html)->not->toContain('altro-host.example/providers');
});

// ---------------------------------------------------------------------------------------------
// Private pages
// ---------------------------------------------------------------------------------------------

test('a private page is not indexed and has no preview at all', function (Closure $request) {
    $response = $request($this);
    $html = (string) $response->getContent();

    expect($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($html)->toContain('<meta name="robots" content="noindex, nofollow">')
        ->not->toContain('property="og:')
        ->not->toContain('name="twitter:')
        ->not->toContain('name="description"')
        ->not->toContain('rel="canonical"')
        ->and(lpTitles($html))->toBe(['Griot']);
})->with([
    'booking page' => [function ($test) {
        $booking = Booking::factory()->create(['customer_name' => 'Mariam Kone']);

        return tap($test->get(route('bookings.show', $booking->public_token))->assertOk(), fn ($r) => expect((string) $r->getContent())->not->toMatch('/<head>[\s\S]*Mariam Kone[\s\S]*<\/head>/'));
    }],
    'provider\'s preview' => [fn ($test) => $test->actingAs(lpProvider()->user)->get(route('provider.preview'))->assertOk()],
    'dashboard' => [fn ($test) => $test->actingAs(lpProvider()->user)->get(route('dashboard'))->assertOk()],
    'bookings of the provider' => [fn ($test) => $test->actingAs(lpProvider()->user)->get(route('provider.bookings.index'))->assertOk()],
    'settings' => [fn ($test) => $test->actingAs(lpProvider()->user)->get(route('profile.edit'))->assertOk()],
    'login' => [fn ($test) => $test->get(route('login'))->assertOk()],
    'register' => [fn ($test) => $test->get(route('register'))->assertOk()],
    'forgot password' => [fn ($test) => $test->get(route('password.request'))->assertOk()],
    'verify email' => [fn ($test) => $test->actingAs(User::factory()->unverified()->create())->get(route('verification.notice'))->assertOk()],
    'confirm password' => [fn ($test) => $test->actingAs(User::factory()->create())->get(route('password.confirm'))->assertOk()],
]);

test('a public page does not make the next request public', function () {
    lpProvider();

    lpGet('/providers/trecce-sahel')->assertHeaderMissing('X-Robots-Tag');

    // Same application, next request: a fresh preview, private again.
    $html = (string) $this->get(route('login'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->getContent();
    expect($html)->toContain('<meta name="robots" content="noindex, nofollow">')
        ->not->toContain('Trecce Sahel');
});

test('the provider\'s preview of his own page says nothing of him in the head', function () {
    $provider = lpProvider(['name' => 'Salone Privato']);

    $html = (string) $this->actingAs($provider->user)->get(route('provider.preview'))->assertOk()->getContent();
    preg_match('#<head>(.*?)</head>#s', $html, $head);

    expect($head[1])->not->toContain('Salone Privato')
        ->not->toContain('og:');
});

// ---------------------------------------------------------------------------------------------
// The HTML, the image, the texts
// ---------------------------------------------------------------------------------------------

test('one <title> only, and no tag of the preview inside a script', function () {
    lpProvider();

    $html = (string) lpGet('/providers/trecce-sahel')->getContent();

    expect(lpTitles($html))->toHaveCount(1);

    preg_match_all('#<script\b[^>]*>(.*?)</script>#s', $html, $scripts);
    foreach ($scripts[1] as $script) {
        expect($script)->not->toContain('og:')->not->toContain('twitter:card');
    }

    // The tags come before the page: in the <head>, not in the props of Inertia.
    expect(strpos($html, 'property="og:title"'))->toBeLessThan(strpos($html, '</head>'));
});

test('the default image exists, is a PNG of 1200x630 and comes from its SVG source', function () {
    $png = (string) file_get_contents(public_path(LinkPreview::DEFAULT_IMAGE));

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(unpack('Nwidth/Nheight', substr($png, 16, 8)))->toBe(['width' => 1200, 'height' => 630])
        ->and(LinkPreview::DEFAULT_IMAGE_WIDTH)->toBe(1200)
        ->and(LinkPreview::DEFAULT_IMAGE_HEIGHT)->toBe(630);

    // The source: the palette's colours, the name of the site, no other text.
    $svg = (string) file_get_contents(resource_path('images/og-default.svg'));
    expect($svg)->toContain('width="1200" height="630"')
        ->toContain('fill="#005f48"')
        ->toContain('>Griot</text>')
        ->toContain('M41.9 22.1A14 14 0 1 0 46 32H33')
        ->and(substr_count($svg, '<text'))->toBe(1);
});

test('the texts of the previews exist in Italian, French and English, with the same placeholders', function () {
    $keys = [
        'link_preview.site.description' => [],
        'link_preview.provider.title' => [':name', ':categories', ':city'],
        'link_preview.provider.title_no_categories' => [':name', ':city'],
        'link_preview.provider.description' => [':name', ':city'],
        'link_preview.privacy.description' => [],
        'link_preview.image_alt' => [],
    ];

    foreach (['it', 'fr', 'en'] as $locale) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

        foreach ($keys as $key => $placeholders) {
            expect($json)->toHaveKey($key);
            preg_match_all('/:[a-z_]+/', $json[$key], $found);
            sort($found[0]);
            sort($placeholders);
            expect($found[0])->toBe($placeholders, "{$locale} {$key}");
        }
    }
});
