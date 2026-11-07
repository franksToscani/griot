<?php

use App\Http\Requests\ProviderIndexRequest;
use App\Models\Category;
use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The band at the top of the home page (docs/home.md): the cities of the search, the form sent to
// the list of providers, the carousel in its right column, colours and texts. The contrasts of the
// band's tokens are measured in AccessibilityContrastTest.

function hhsSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

/**
 * The cities prop of the home page.
 *
 * @return list<string>
 */
function hhsCities(): array
{
    $cities = [];
    test()->get('/')->assertOk()->assertInertia(function (Assert $page) use (&$cities) {
        $cities = $page->toArray()['props']['cities'];
    });

    return $cities;
}

/**
 * A provider in a city, with one active service: something to book.
 */
function hhsProvider(string $city, bool $published = true): Provider
{
    $provider = Provider::factory()->state(['city' => $city, 'is_published' => $published])->create();
    $category = Category::query()->firstOrCreate(['slug' => 'treccine'], ['name' => 'Treccine']);
    Service::factory()->for($provider)->for($category)->create(['is_active' => true]);

    return $provider;
}

/**
 * The number of queries of one visit to the home page.
 */
function hhsQueries(): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get('/')->assertOk();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

/**
 * The sources of the band: the page, the band, the search, the carousel and their colours.
 */
function hhsBand(): string
{
    return hhsSource('pages/home.tsx').hhsSource('components/home/home-hero.tsx').hhsSource('components/home/home-search.tsx')
        .hhsSource('components/home/home-carousel.tsx').hhsSource('lib/home-hero.ts').hhsSource('lib/home-carousel.ts');
}

// ---------------------------------------------------------------------------------------------
// The data
// ---------------------------------------------------------------------------------------------

test('the cities of the search: only those of published providers, once each, in alphabetical order', function () {
    hhsProvider('Torino');
    hhsProvider('Bologna');
    hhsProvider('Bologna');
    hhsProvider('Milano');
    // Not published: its city is not offered.
    hhsProvider('Roma', published: false);

    expect(hhsCities())->toBe(['Bologna', 'Milano', 'Torino'])
        // The same list as the city filter of the list of providers, so every one is accepted.
        ->and(hhsCities())->toBe(Provider::publishedCities());
});

test('no published provider: no city, and the page still works', function () {
    hhsProvider('Roma', published: false);

    expect(hhsCities())->toBe([]);
});

test('the home page makes 4 queries without featured providers and 6 with them, however many there are', function () {
    // Without a provider with photos: the categories, the featured providers (none, and the empty
    // read of their photos), the cities.
    hhsProvider('Bologna');
    $withoutFeatured = hhsQueries();

    // With featured providers: their services and categories too.
    ProviderPhoto::factory()->for(hhsProvider('Milano'))->create();
    $withOneFeatured = hhsQueries();

    foreach (['Torino', 'Napoli', 'Bari', 'Roma'] as $city) {
        ProviderPhoto::factory()->for(hhsProvider($city))->create();
    }
    foreach (['barbiere' => 'Barbiere', 'nails' => 'Nails'] as $slug => $name) {
        Service::factory()->for(Provider::factory()->published())->for(Category::factory()->create(['slug' => $slug, 'name' => $name]))->create(['is_active' => true]);
    }

    expect($withoutFeatured)->toBe(4)
        ->and($withOneFeatured)->toBe(6)
        ->and(hhsQueries())->toBe(6);
});

test('what the form sends is the search and the filter of the list of providers', function () {
    hhsProvider('Bologna')->update(['name' => 'Trecce Sahel']);
    hhsProvider('Milano')->update(['name' => 'Trecce Lagos']);
    hhsProvider('Bologna')->update(['name' => 'Barberia Bologna']);
    Service::query()->where('provider_id', Provider::query()->where('name', 'Barberia Bologna')->value('id'))->update(['name' => 'Taglio']);

    // Both fields filled: each one narrows the list (Bologna has two, "trecce" two).
    $this->get(route('providers.index', ['q' => 'trecce', 'city' => 'Bologna']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['category' => null, 'city' => 'Bologna', 'q' => 'trecce'])
            ->has('providers.data', 1));

    // Both empty, as the native form sends them: the whole list.
    $this->get('/providers?q=&city=')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['category' => null, 'city' => null, 'q' => null])
            ->has('providers.data', 3));
});

// ---------------------------------------------------------------------------------------------
// The form
// ---------------------------------------------------------------------------------------------

test('a native GET form to the list of providers, which needs no JavaScript', function () {
    $search = hhsSource('components/home/home-search.tsx');

    expect($search)
        ->toMatch('/<form\s+role="search"\s+method="get"\s+action=\{index\(\)\.url\}\s+className=/')
        ->toContain("import { index } from '@/routes/providers';")
        // Nothing stops the browser: no handler, no Inertia visit.
        ->not->toMatch('/onSubmit|preventDefault|router\./');
    expect(hhsSource('components/home/home-hero.tsx'))->toContain('<HomeSearch cities={cities} />');
    expect(hhsSource('pages/home.tsx'))
        ->toContain('<HomeHero cities={cities} slides={slides} />')
        ->toContain('export default function Home({ categories, featured, cities }: Props) {');
});

test('the fields q and city, with their visible translated labels and the limit of the list', function () {
    $search = hhsSource('components/home/home-search.tsx');

    expect($search)
        // Since #144 step 9 the magnifying glass sits between the label and the field.
        ->toMatch("/<Label htmlFor=\"home-search-q\">\\{t\\('home\\.search\\.q'\\)\\}<\\/Label>[\\s\\S]*?<Input\\s+id=\"home-search-q\"\\s+type=\"search\"\\s+name=\"q\"\\s+maxLength=\\{SEARCH_MAX\\}/")
        ->toMatch("/<Label htmlFor=\"home-search-city\">\\s*\\{t\\('home\\.search\\.city'\\)\\}\\s*<\\/Label>[\\s\\S]*?<select\\s+id=\"home-search-city\"\\s+name=\"city\"\\s+defaultValue=\"\"/")
        // "All cities" sends an empty value; then one option per city of the prop.
        ->toMatch("/<option value=\"\">\\s*\\{t\\('home\\.search\\.all_cities'\\)\\}\\s*<\\/option>\\s*\\{cities\\.map\\(\\(city\\) => \\(\\s*<option key=\\{city\\} value=\\{city\\}>\\s*\\{city\\}\\s*<\\/option>/")
        ->toMatch("/<Button\\s+type=\"submit\"\\s+className=\\{cn\\('px-6 max-md:w-full', SIZE, HERO\\.button\\)\\}\\s*>\\s*\\{t\\('home\\.search\\.submit'\\)\\}\\s*<\\/Button>/")
        ->not->toMatch('/aria-label(?!ledby)/');
    // The limit of ProviderIndexRequest: a longer search would be ignored.
    expect($search)->toContain('const SEARCH_MAX = '.ProviderIndexRequest::SEARCH_MAX.';');
});

test('the select looks like the kit\'s fields: 44px on phones, a visible focus, a border in forced colours', function () {
    expect(hhsSource('components/home/home-search.tsx'))
        ->toMatch("/<select[\\s\\S]*?className=\\{cn\\(\\s*'[^']*\\bborder border-input\\b[^']*\\boutline-none\\b[^']*\\bfocus-visible:ring-\\[3px\\] focus-visible:outline-hidden\\b[^']*\\bmax-md:min-h-11\\b[^']*',\\s*SIZE,\\s*HERO\\.field,?\\s*\\)\\}/")
        // The text field is the kit's Input, 44px on phones already, with the band's focus ring;
        // since #144 step 9 the same height as the select and room for the magnifying glass.
        ->toMatch("/name=\"q\"\\s+maxLength=\\{SEARCH_MAX\\}\\s+placeholder=\\{t\\('home\\.search\\.placeholder'\\)\\}\\s+className=\\{cn\\('pl-10', SIZE, HERO\\.field\\)\\}/");
});

// ---------------------------------------------------------------------------------------------
// The band
// ---------------------------------------------------------------------------------------------

test('from edge to edge, square corners, a height in rem; its content in the site\'s container', function () {
    $hero = hhsSource('components/home/home-hero.tsx');

    expect($hero)
        ->toMatch('/<div className=\{HERO\.band\}>\s*<div\s+className=\{cn\(\s*\'mx-auto grid w-full max-w-6xl gap-10 px-4 py-12 md:min-h-\[32rem\] md:items-center md:py-16 lg:py-20\',\s*slides\.length > 0 && \'md:grid-cols-2\',?\s*\)\}/')
        // Search first, carousel after: on phones they stack in this order.
        ->toMatch('/<HomeSearch cities=\{cities\} \/>[\s\S]*<HomeCarousel slides=\{slides\} \/>/');
    // Square corners on the band and the photos; only the dots are round. No 100vw, which makes
    // a page scroll sideways when the scroll bar takes room.
    expect($hero.hhsSource('lib/home-hero.ts'))->not->toContain('rounded');
    expect(preg_replace('/rounded-full/', '', hhsSource('components/home/home-carousel.tsx')))->not->toContain('rounded');
    expect(hhsBand())->not->toMatch('/\b(w-screen|w-\[100vw\]|-mx-)/');

    // The public layout leaves the width to the home page only.
    expect(hhsSource('layouts/public-layout.tsx'))
        ->toContain("const FULL_WIDTH_PAGES = ['home'];")
        ->toMatch("/className=\\{cn\\(\\s*'w-full flex-1 outline-none',\\s*!fullWidth && 'mx-auto max-w-6xl px-4 py-6',?\\s*\\)\\}/");
    expect(hhsSource('pages/home.tsx'))->toContain('<div className="mx-auto flex w-full max-w-6xl flex-col gap-10 px-4 py-10">');
});

test('the h1 is the same, alone in the band; no heading in the search nor in the slides', function () {
    expect(hhsSource('components/home/home-hero.tsx'))
        ->toMatch("/<h1 className=\"[^\"]*\">\\s*\\{t\\('home\\.intro\\.title'\\)\\}\\s*<\\/h1>\\s*<p className=\"max-w-xl md:text-lg\">\\s*\\{t\\('home\\.intro\\.text'\\)\\}\\s*<\\/p>/");
    expect(__('home.intro.title', [], 'it'))->toBe('Il savoir-faire africano, vicino a te');
    expect(hhsSource('components/home/home-search.tsx').hhsSource('components/home/home-carousel.tsx'))->not->toMatch('/<h[1-6]\b/');
    // The old button "Trova un professionista" and its block are gone.
    expect(file_exists(resource_path('js/components/home/home-intro.tsx')))->toBeFalse();
    expect(hhsBand())->not->toContain('HomeIntro');
});

test('the colours of the band are its tokens only, defined in both themes', function () {
    expect(hhsSource('components/home/home-hero.tsx'))->toContain('<div className={HERO.band}>');
    expect(hhsSource('components/home/home-search.tsx'))->toContain("className={cn('pl-10', SIZE, HERO.field)}");
    expect(hhsSource('components/home/home-carousel.tsx'))->toContain('HERO.button')->toContain('HERO.ring');

    $css = (string) file_get_contents(resource_path('css/app.css'));
    expect($css)->toContain('--color-hero: var(--hero);')->toContain('--color-hero-foreground: var(--hero-foreground);');
    foreach (['light', 'dark'] as $theme) {
        expect(accTokens($theme))->toHaveKeys(['hero', 'hero-foreground']);
    }

    // No palette colour, hex, rgb or oklch written by hand.
    expect(hhsBand())
        ->not->toMatch('/\b(bg|text|border|ring)-(red|green|blue|yellow|emerald|teal|gray|slate|zinc|neutral|white|black)\b/')
        ->not->toMatch('/rgb\(|oklch\(|-\[#/');
});

// ---------------------------------------------------------------------------------------------
// The carousel in the band
// ---------------------------------------------------------------------------------------------

test('no autoplay, no timer, no effect; nothing that moves, sticks or scrolls smoothly', function () {
    expect(hhsBand())
        ->not->toMatch('/\b(setInterval|setTimeout|requestAnimationFrame|autoPlay|autoplay)\b/')
        ->not->toMatch('/\b(transition|animate-|duration-|translate-)/')
        ->not->toMatch('/scroll-smooth|scroll-behavior|sticky|\bfixed\b/');
});

test('the first photo at once with a high priority, the others lazy', function () {
    $carousel = hhsSource('components/home/home-carousel.tsx');

    expect($carousel)
        ->toMatch("/\\{\\.\\.\\.\\(position === 0\\s*\\?\\s*\\{\\s*loading: 'eager',\\s*fetchPriority: 'high',?\\s*\\}\\s*:\\s*\\{ loading: 'lazy' \\}\\)\\}/")
        ->toContain('sizes={PHOTO_SIZES}')
        ->toContain('srcSet={slide.image.srcSet}');
    expect(substr_count($carousel, "fetchPriority: 'high'"))->toBe(1)
        ->and(substr_count($carousel, "loading: 'eager'"))->toBe(1)
        ->and(substr_count($carousel, "loading: 'lazy'"))->toBe(1);
});

test('the link of a slide goes to the list filtered on its category', function () {
    expect(hhsSource('components/home/home-carousel.tsx'))
        ->toMatch('/<Link\s+href=\{providersIndex\(\{\s*query: \{ category: slide\.slug \},?\s*\}\)\}\s*>\s*\{t\(\'home\.carousel\.cta\'\)\}/');
    expect(__('home.carousel.cta', [], 'it'))->toBe('Vedi i professionisti');
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts of the search exist in Italian, French and English', function () {
    $keys = ['home.search.q', 'home.search.placeholder', 'home.search.city', 'home.search.all_cities', 'home.search.submit'];

    foreach (['it', 'fr', 'en'] as $locale) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

        foreach ($keys as $key) {
            expect($json)->toHaveKey($key);
            expect($json[$key])->not->toBe('')->not->toMatch('/:[a-z_]+/');
            // French: a non-breaking space before : ? ! ;, never a plain one.
            expect(preg_match('/ [:?!;](?![a-z])/', $json[$key]))->toBe(0, "{$locale} {$key}");
        }
    }

    expect(__('home.search.q', [], 'it'))->toBe('Servizio o professionista')
        ->and(__('home.search.all_cities', [], 'it'))->toBe('Tutte le città')
        ->and(__('home.search.submit', [], 'fr'))->toBe('Rechercher')
        ->and(__('home.search.city', [], 'en'))->toBe('City');
});

test('no real contact in the band: no email address, no phone number', function () {
    $sources = hhsBand().hhsSource('layouts/public-layout.tsx');
    foreach (['it', 'fr', 'en'] as $locale) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        $sources .= implode("\n", array_filter($json, fn (string $key) => str_starts_with($key, 'home.'), ARRAY_FILTER_USE_KEY));
    }

    expect($sources)
        ->not->toMatch('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/')
        ->not->toMatch('/(\+|00)\d[\d ]{7,}/')
        ->not->toContain('wa.me/');
});
