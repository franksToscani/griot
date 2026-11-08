<?php

use App\Http\Requests\ProviderIndexRequest;
use App\Models\Category;
use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The public list of providers with its rich card and its order (docs/search.md): who is listed
// (something to book), the card's data (cover, lowest price, number of services), the four
// orders with a stable tie on the id, the queries, and the sources of the card and of "Ordina
// per".

function plcSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

/**
 * A published provider with one active service at this price.
 *
 * @param  array<string, mixed>  $attributes
 */
function plcProvider(string $name, string $price = '50.00', array $attributes = []): Provider
{
    $provider = Provider::factory()->published()->create(['name' => $name, ...$attributes]);
    Service::factory()->for($provider)->create(['price' => $price]);

    return $provider;
}

/**
 * The props of one page of the list.
 *
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function plcProps(array $query = []): array
{
    $props = [];
    test()->get(route('providers.index', $query))->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    return $props;
}

/**
 * The ids listed on one page, in order.
 *
 * @param  array<string, mixed>  $query
 * @return list<int>
 */
function plcIds(array $query = []): array
{
    return array_column(plcProps($query)['providers']['data'], 'id');
}

/**
 * The number of queries of one visit.
 *
 * @param  array<string, mixed>  $query
 */
function plcQueries(string $route, array $query = []): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get(route($route, $query))->assertOk();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

// ---------------------------------------------------------------------------------------------
// Who is listed
// ---------------------------------------------------------------------------------------------

test('a published provider without any service is not listed', function () {
    $listed = plcProvider('Trecce Sahel');
    Provider::factory()->published()->create(['name' => 'Senza servizi']);

    expect(plcIds())->toBe([$listed->id]);
});

test('a published provider with inactive services only is not listed, not even by a filter or a search', function () {
    $listed = plcProvider('Trecce Sahel', attributes: ['city' => 'Bologna']);
    $braids = Category::factory()->create(['slug' => 'treccine']);
    Service::factory()->for($listed)->for($braids)->create();
    $hidden = Provider::factory()->published()->create(['name' => 'Trecce Ferme', 'city' => 'Bologna']);
    Service::factory()->inactive()->for($hidden)->for($braids)->create(['name' => 'Trecce']);

    expect(plcIds())->toBe([$listed->id])
        ->and(plcIds(['city' => 'Bologna']))->toBe([$listed->id])
        ->and(plcIds(['q' => 'trecce']))->toBe([$listed->id])
        ->and(plcIds(['category' => 'treccine']))->toBe([$listed->id]);
});

// ---------------------------------------------------------------------------------------------
// The card's data
// ---------------------------------------------------------------------------------------------

test('the cover is the first photo by position, then id; null without photos', function () {
    $withPhotos = plcProvider('Con foto');
    ProviderPhoto::factory()->for($withPhotos)->create(['position' => 3]);
    $first = ProviderPhoto::factory()->for($withPhotos)->create(['position' => 1, 'width' => 1600, 'height' => 1200]);
    ProviderPhoto::factory()->for($withPhotos)->create(['position' => 1]);
    plcProvider('Senza foto');

    $cards = collect(plcProps()['providers']['data'])->keyBy('name');

    expect($cards['Senza foto']['cover'])->toBeNull()
        ->and($cards['Con foto']['cover'])->toBe([
            'width' => 1600,
            'height' => 1200,
            'variants' => array_map(fn (int $side) => ['width' => $first->widthFor($side), 'url' => $first->urlFor($side)], $first->sizes()),
        ])
        // The same shape as on the home page: no alternative text, nothing else.
        ->and(array_keys($cards['Con foto']['cover']))->toBe(['width', 'height', 'variants']);
});

test('the lowest price and the number of services count the active services only', function () {
    $provider = plcProvider('Trecce Sahel', '45.50');
    Service::factory()->for($provider)->create(['price' => '30.00']);
    Service::factory()->inactive()->for($provider)->create(['price' => '10.00']);
    Service::factory()->inactive()->for($provider)->create(['price' => '20.00']);

    $card = plcProps()['providers']['data'][0];

    // As the database gives a decimal, always with two decimals.
    expect($card['min_price'])->toBe('30.00')
        ->and($card['services_count'])->toBe(2);
});

test('the date of publication is never in the list', function () {
    plcProvider('Trecce Sahel');

    $card = plcProps(['sort' => 'recent'])['providers']['data'][0];

    expect($card)->not->toHaveKey('published_at')
        ->and(array_keys($card))->toBe(['id', 'slug', 'name', 'city', 'avatar_url', 'categories', 'cover', 'min_price', 'services_count']);
    $this->get(route('providers.index', ['sort' => 'recent']))->assertDontSee('published_at');
});

// ---------------------------------------------------------------------------------------------
// The orders
// ---------------------------------------------------------------------------------------------

test('the same name keeps its place from page 1 to page 2: the id breaks the tie', function () {
    $ids = [];
    foreach (range(1, 13) as $index) {
        $ids[] = plcProvider('Trecce Sahel')->id;
    }

    expect([...plcIds(), ...plcIds(['page' => 2])])->toBe($ids);
});

test('by name A to Z by default and with sort=name, the id after the name', function () {
    $zeta = plcProvider('Zeta');
    $alfaOne = plcProvider('Alfa');
    $alfaTwo = plcProvider('Alfa');
    $beta = plcProvider('Beta');
    $expected = [$alfaOne->id, $alfaTwo->id, $beta->id, $zeta->id];

    expect(plcIds())->toBe($expected)
        ->and(plcIds(['sort' => 'name']))->toBe($expected)
        ->and(plcProps(['sort' => 'name'])['filters']['sort'])->toBe('name');
});

test('the most recently published first, the higher id first on the same moment', function () {
    $old = plcProvider('A', attributes: ['published_at' => now()->subMonths(2)]);
    $sameOne = plcProvider('B', attributes: ['published_at' => now()->subDay()]);
    $sameTwo = plcProvider('C', attributes: ['published_at' => now()->subDay()]);
    $new = plcProvider('D', attributes: ['published_at' => now()->subHour()]);

    expect(plcIds(['sort' => 'recent']))->toBe([$new->id, $sameTwo->id, $sameOne->id, $old->id]);
});

test('by lowest price up, the id after the price', function () {
    $dear = plcProvider('A', '90.00');
    $cheapOne = plcProvider('B', '25.00');
    $cheapTwo = plcProvider('C', '25.00');
    // Its cheapest active service counts, not the inactive one nor the dearer one.
    $mixed = plcProvider('D', '60.00');
    Service::factory()->for($mixed)->create(['price' => '40.00']);
    Service::factory()->inactive()->for($mixed)->create(['price' => '5.00']);

    expect(plcIds(['sort' => 'price_asc']))->toBe([$cheapOne->id, $cheapTwo->id, $mixed->id, $dear->id]);
});

test('by lowest price down, the id after the price', function () {
    $cheap = plcProvider('A', '25.00');
    $dearOne = plcProvider('B', '90.00');
    $dearTwo = plcProvider('C', '90.00');
    $middle = plcProvider('D', '100.00');
    Service::factory()->for($middle)->create(['price' => '40.00']);

    expect(plcIds(['sort' => 'price_desc']))->toBe([$dearOne->id, $dearTwo->id, $middle->id, $cheap->id]);
});

test('an unknown sort is ignored like the other filters: the order by name, sort null', function (mixed $sort) {
    $beta = plcProvider('Beta', '10.00');
    $alfa = plcProvider('Alfa', '90.00');

    $props = plcProps(['sort' => $sort]);

    expect(array_column($props['providers']['data'], 'id'))->toBe([$alfa->id, $beta->id])
        ->and($props['filters']['sort'])->toBeNull();
})->with(['price', 'PRICE_ASC', 'random', '', 'an array' => [['recent']]]);

test('the order is a closed list, kept in the links of the pagination with the filters', function () {
    expect(ProviderIndexRequest::SORTS)->toBe(['name', 'recent', 'price_asc', 'price_desc']);

    foreach (range(1, 13) as $index) {
        plcProvider("Trecce {$index}", attributes: ['city' => 'Bologna']);
    }

    $props = plcProps(['sort' => 'price_desc', 'city' => 'Bologna']);

    expect($props['filters'])->toBe(['category' => null, 'city' => 'Bologna', 'q' => null, 'max_price' => null, 'with_photo' => null, 'sort' => 'price_desc'])
        ->and($props['providers']['links']['next'])->toBe(route('providers.index', ['city' => 'Bologna', 'sort' => 'price_desc', 'page' => 2]));
});

// ---------------------------------------------------------------------------------------------
// The queries
// ---------------------------------------------------------------------------------------------

test('the list makes 7 queries, 8 with a search, however many providers', function () {
    $braids = Category::factory()->create(['slug' => 'treccine', 'name' => 'Treccine']);
    $one = plcProvider('Trecce Sahel 1');
    Service::factory()->for($one)->for($braids)->create();
    ProviderPhoto::factory()->for($one)->create();

    $counts = fn () => [
        plcQueries('providers.index'),
        plcQueries('providers.index', ['q' => 'trecce sahel']),
        plcQueries('providers.index', ['sort' => 'price_asc', 'city' => $one->city, 'category' => 'treccine']),
    ];

    expect($counts())->toBe([7, 8, 7]);

    foreach (range(2, 8) as $index) {
        $provider = plcProvider("Trecce Sahel {$index}", attributes: ['city' => $one->city]);
        Service::factory()->count(2)->for($provider)->for($braids)->create();
        ProviderPhoto::factory()->count(2)->for($provider)->sequence(['position' => 1], ['position' => 2])->create();
    }

    expect($counts())->toBe([7, 8, 7]);
});

test('the home page keeps its queries and its covers: 4 without featured providers, 6 with them', function () {
    plcProvider('Senza foto');
    expect(plcQueries('home'))->toBe(4);

    $provider = plcProvider('Con foto');
    $cover = ProviderPhoto::factory()->for($provider)->create(['position' => 1]);
    ProviderPhoto::factory()->for($provider)->create(['position' => 2]);
    expect(plcQueries('home'))->toBe(6);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('featured.0.cover.width', $cover->width)
        ->where('featured.0.cover.variants.0.url', $cover->urlFor($cover->sizes()[0])));

    // One subquery and one loader for both pages.
    expect((string) file_get_contents(app_path('Home/FeaturedProviders.php')))
        ->toContain('->withCoverPhotoId()')
        ->toContain('ProviderCovers::attach($providers);');
    expect((string) file_get_contents(app_path('Http/Controllers/ProviderController.php')))
        ->toContain('->withCoverPhotoId()')
        ->toContain('ProviderCovers::attach($providers->getCollection());');
});

// ---------------------------------------------------------------------------------------------
// The card
// ---------------------------------------------------------------------------------------------

test('the card is one link, its cover decorative and lazy, the name an h2', function () {
    $card = plcSource('components/provider-card.tsx');

    expect(substr_count($card, '<Link'))->toBe(1)
        ->and($card)->toMatch('/^\s*<Link\s+href=\{show\(provider\.slug\)\}/m')
        ->not->toMatch('/<a\b|<button\b/')
        ->and(preg_match_all('/<img[\s\S]*?\/>/', $card, $images))->toBe(1);
    expect($images[0][0])
        ->toContain('alt=""')
        ->toContain('loading="lazy"')
        ->toContain('decoding="async"')
        ->toContain('width={provider.cover.width}')
        ->toContain('height={provider.cover.height}')
        ->toContain('srcSet={srcSet(provider.cover.variants)}')
        ->toContain('sizes={COVER_SIZES}')
        ->toContain('aspect-[4/3]');
    expect($card)
        ->toMatch('/<h2 className="truncate font-medium">\{provider\.name\}<\/h2>/')
        // Without a cover the avatar, in a box of the same shape.
        ->toMatch('/<div className="flex aspect-\[4\/3\][^"]*">\s*<ProviderAvatar\s+name=\{provider\.name\}\s+avatarUrl=\{provider\.avatar_url\}/')
        ->toMatch("/t\\('providers\\.card\\.summary', \\{\\s*services,\\s*price: t\\('providers\\.card\\.from_price', \\{\\s*price: formatPrice\\(locale, provider\\.min_price\\),?\\s*\\}\\),?\\s*\\}\\)/")
        ->toContain("t('providers.card.services_one')")
        ->toContain("t('providers.card.services', { count: provider.services_count })");
});

test('the card moves only as the cards of the home page, never with a transition of its own, never on scroll', function () {
    $card = plcSource('components/provider-card.tsx');

    expect($card)
        ->toContain("import { CARD_MOTION } from '@/lib/motion';")
        ->toMatch("/focus-visible:outline-hidden',\\s*touchTarget,\\s*CARD_MOTION\\.card,?\\s*\\)\\}/")
        ->toContain('CARD_MOTION.frame')
        ->toContain('CARD_MOTION.photo,');
    expect(withoutMotionSafe($card.plcSource('pages/providers/index.tsx').plcSource('components/provider-sort.tsx')))
        ->not->toMatch('/\b(transition|duration-|animate-|translate-|scale-)/');
    expect($card.plcSource('pages/providers/index.tsx'))->not->toMatch('/useReveal|reveal-item/');
});

// ---------------------------------------------------------------------------------------------
// "Ordina per"
// ---------------------------------------------------------------------------------------------

test('"Ordina per" is a native select with a visible label, 44px on phones, next to the number of results', function () {
    $sort = plcSource('components/provider-sort.tsx');

    expect($sort)
        ->toMatch("/<Label htmlFor=\"provider-sort\">\\{t\\('providers\\.sort\\.label'\\)\\}<\\/Label>\\s*<select\\s+id=\"provider-sort\"\\s+name=\"sort\"\\s+value=\\{filters\\.sort \\?\\? 'name'\\}/")
        ->toContain("const SORTS: Sort[] = ['name', 'recent', 'price_asc', 'price_desc'];")
        ->toContain('{t(`providers.sort.${sort}`)}')
        ->toMatch('/outline-none[^\']*focus-visible:outline-hidden[^\']*max-md:min-h-11/')
        // The same filters, from page 1, the page kept mounted: the status is read again.
        ->toMatch("/index\\(\\{\\s*query: \\{ \\.\\.\\.filters, sort: value === 'name' \\? null : value \\},?\\s*\\}\\)/")
        ->toContain('{ preserveScroll: true, preserveState: true }')
        ->not->toMatch('/\bpage[:=]/')
        ->not->toMatch('/aria-label(?!ledby)/');

    $page = plcSource('pages/providers/index.tsx');
    expect($page)
        // Since the list's PR B one row: "Filtri (n)", "Ordina per", the number of results.
        ->toMatch('/<div className="flex flex-wrap items-end gap-4">\s*<ProviderFiltersPanel[^>]*\/>\s*<ProviderSort filters=\{filters\} \/>\s*(\{\/\*[\s\S]*?\*\/\}\s*)?<p role="status" className="text-muted-foreground">\s*\{resultsText\(\s*t,\s*providers\.meta\.total,\s*filters,\s*filtered,?\s*\)\}\s*<\/p>\s*<\/div>/')
        // The status is outside every condition: before the first one of the page.
        ->and(strpos($page, 'role="status"'))->toBeLessThan(strpos($page, '&&'));
});

test('"Azzera filtri" takes the filters away and keeps the order', function () {
    $filters = plcSource('components/provider-filters.tsx');

    expect($filters)
        ->toMatch('/export function resetFilters\(filters: Filters\) \{\s*return index\(\{ query: \{ sort: filters\.sort \} \}\);\s*\}/')
        // The order is not a filter: only the keys of the one list count (ProviderListFiltersTest).
        ->toMatch('/return keys\.some\(\(key\) => filters\[key\] !== null\);/')
        // A search sent by the browser keeps it too: sort is among the hidden fields.
        ->toMatch("/const hidden = \\[[^\\]]*'sort' as const,?\\s*\\];/");
    // Since the list's PR B one button, in the page, whenever a filter is on.
    expect(plcSource('pages/providers/index.tsx'))
        ->toMatch('/\{filtered && \(\s*<div>\s*<Button variant="outline" asChild>\s*<Link href=\{resetFilters\(filters\)\} preserveScroll>/')
        ->not->toMatch('/href=\{index\(\)\}/');
});

// ---------------------------------------------------------------------------------------------
// Texts and contacts
// ---------------------------------------------------------------------------------------------

test('the texts of the card and of the order exist in Italian, French and English', function () {
    $json = fn (string $locale) => json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        sort($found[0]);

        return $found[0];
    };
    $keys = [
        'providers.card.services_one', 'providers.card.services', 'providers.card.from_price', 'providers.card.summary',
        'providers.sort.label', 'providers.sort.name', 'providers.sort.recent', 'providers.sort.price_asc', 'providers.sort.price_desc',
    ];

    foreach ($keys as $key) {
        foreach (['it', 'fr', 'en'] as $locale) {
            expect($json($locale))->toHaveKey($key)
                ->and($placeholders($json($locale)[$key]))->toBe($placeholders($json('it')[$key]), "{$locale} {$key}");
        }
        expect(preg_match('/ [:?!;](?![a-z])/', $json('fr')[$key]))->toBe(0, "fr {$key}");
    }
    expect(array_keys($json('fr')))->toBe(array_keys($json('it')))
        ->and(array_keys($json('en')))->toBe(array_keys($json('it')));

    expect(__('providers.sort.label', [], 'it'))->toBe('Ordina per')
        ->and(__('providers.card.summary', ['services' => __('providers.card.services', ['count' => 3], 'it'), 'price' => __('providers.card.from_price', ['price' => '30,00 €'], 'it')], 'it'))->toBe('3 servizi · da 30,00 €')
        ->and(__('providers.card.from_price', ['price' => '30,00 €'], 'fr'))->toBe('à partir de 30,00 €')
        ->and(__('providers.card.services_one', [], 'en'))->toBe('1 service');
});

test('no real contact in the list: no email address, no phone number', function () {
    $sources = plcSource('components/provider-card.tsx').plcSource('components/provider-sort.tsx').plcSource('pages/providers/index.tsx')
        .(string) file_get_contents(app_path('Http/Controllers/ProviderController.php'))
        .(string) file_get_contents(app_path('Support/ProviderCovers.php'));
    foreach (['it', 'fr', 'en'] as $locale) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        $sources .= implode("\n", array_filter($json, fn (string $key) => str_starts_with($key, 'providers.'), ARRAY_FILTER_USE_KEY));
    }

    expect($sources)
        ->not->toMatch('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/')
        ->not->toMatch('/(\+|00)\d[\d ]{7,}/')
        ->not->toContain('mailto:')
        ->not->toContain('wa.me/');
});
