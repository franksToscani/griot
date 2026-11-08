<?php

use App\Http\Requests\ProviderIndexRequest;
use App\Models\Category;
use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The filters of the public list (docs/providers-list.md): maximum price and "solo con foto" on
// the server, the one list of the filters, the search bar shared with the home page, the panel of
// the filters, the row of the results and ResultsLayout.

function plfSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

/**
 * A published provider with one active service at this price.
 *
 * @param  array<string, mixed>  $attributes
 */
function plfProvider(string $name, string $price, array $attributes = []): Provider
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
function plfProps(array $query = []): array
{
    $props = [];
    test()->get(route('providers.index', $query))->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    return $props;
}

/**
 * The names listed on one page, in order.
 *
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function plfNames(array $query = []): array
{
    return array_column(plfProps($query)['providers']['data'], 'name');
}

// ---------------------------------------------------------------------------------------------
// Maximum price
// ---------------------------------------------------------------------------------------------

test('the maximum price keeps who has an active service at that price or less, the limit included', function () {
    plfProvider('A', '30.00');
    plfProvider('B', '39.90');
    plfProvider('C', '39.91');
    $both = plfProvider('D', '80.00');
    Service::factory()->for($both)->create(['price' => '20.00']);

    expect(plfNames(['max_price' => '39.90']))->toBe(['A', 'B', 'D'])
        ->and(plfNames(['max_price' => '30']))->toBe(['A', 'D'])
        ->and(plfNames(['max_price' => '20.00']))->toBe(['D'])
        ->and(plfNames(['max_price' => '19.99']))->toBe([]);
});

test('the comma counts as a dot, and the price comes back as "39.90"', function () {
    plfProvider('A', '39.90');
    plfProvider('B', '45.00');

    $props = plfProps(['max_price' => ' 39,9 ']);

    expect(array_column($props['providers']['data'], 'name'))->toBe(['A'])
        ->and($props['filters']['max_price'])->toBe('39.90')
        ->and(plfProps(['max_price' => '9999.99'])['filters']['max_price'])->toBe('9999.99')
        ->and(plfProps(['max_price' => '7'])['filters']['max_price'])->toBe('7.00');
});

test('a maximum price that is not valid is ignored, without an error', function (mixed $price) {
    plfProvider('A', '30.00');
    plfProvider('B', '90.00');

    $props = plfProps(['max_price' => $price]);

    expect(array_column($props['providers']['data'], 'name'))->toBe(['A', 'B'])
        ->and($props['filters']['max_price'])->toBeNull();
})->with(['0', '0.00', '0,0', '', 'abc', '-5', '10000', '12.345', '1.000,50', '25,5,0', 'an array' => [['30']]]);

test('only the active services count for the maximum price', function () {
    $sleeping = plfProvider('Dormiente', '90.00');
    Service::factory()->inactive()->for($sleeping)->create(['price' => '10.00']);
    plfProvider('Attivo', '15.00');

    expect(plfNames(['max_price' => '20']))->toBe(['Attivo']);
});

test('the maximum price combines with category, city, search, the photos and the order', function () {
    $braids = Category::factory()->create(['slug' => 'treccine', 'name' => 'Treccine']);
    foreach ([['Trecce Uno', '25.00', 'Bologna'], ['Trecce Due', '35.00', 'Bologna'], ['Trecce Tre', '20.00', 'Milano'], ['Trecce Care', '90.00', 'Bologna']] as [$name, $price, $city]) {
        $provider = Provider::factory()->published()->create(['name' => $name, 'city' => $city]);
        Service::factory()->for($provider)->for($braids)->create(['price' => $price, 'name' => 'Trecce box']);
    }
    $other = plfProvider('Barberia', '10.00', ['city' => 'Bologna']);
    ProviderPhoto::factory()->for(Provider::query()->where('name', 'Trecce Due')->firstOrFail())->create();

    $query = ['max_price' => '40', 'category' => 'treccine', 'city' => 'Bologna', 'q' => 'trecce'];

    expect(plfNames($query))->toBe(['Trecce Due', 'Trecce Uno'])
        ->and(plfNames([...$query, 'sort' => 'price_desc']))->toBe(['Trecce Due', 'Trecce Uno'])
        ->and(plfNames([...$query, 'sort' => 'price_asc']))->toBe(['Trecce Uno', 'Trecce Due'])
        ->and(plfNames([...$query, 'with_photo' => '1']))->toBe(['Trecce Due'])
        ->and(plfNames(['max_price' => '40', 'city' => 'Bologna']))->toContain($other->name);
});

// ---------------------------------------------------------------------------------------------
// With photos
// ---------------------------------------------------------------------------------------------

test('"solo con foto" keeps who has a photo of the work; only the value 1 turns it on', function (mixed $value, bool $on) {
    $withPhoto = plfProvider('Con foto', '30.00');
    ProviderPhoto::factory()->for($withPhoto)->create();
    plfProvider('Senza foto', '30.00');

    $props = plfProps(['with_photo' => $value]);

    expect(array_column($props['providers']['data'], 'name'))->toBe($on ? ['Con foto'] : ['Con foto', 'Senza foto'])
        ->and($props['filters']['with_photo'])->toBe($on ? '1' : null);
})->with([
    'on' => ['1', true],
    'true' => ['true', false],
    'zero' => ['0', false],
    'yes' => ['yes', false],
    'empty' => ['', false],
    'an array' => [['1'], false],
]);

// ---------------------------------------------------------------------------------------------
// The one list of the filters
// ---------------------------------------------------------------------------------------------

test('one list of the filters: filters(), the props of the page and the panel come from it', function () {
    expect(ProviderIndexRequest::FILTERS)->toBe(['category', 'city', 'q', 'max_price', 'with_photo'])
        // Counted by "Filtri (n)": not the city, not the search, not the order.
        ->and(ProviderIndexRequest::PANEL_FILTERS)->toBe(['category', 'max_price', 'with_photo']);

    $props = plfProps();

    expect(array_keys($props['filters']))->toBe([...ProviderIndexRequest::FILTERS, 'sort'])
        ->and($props['filterKeys'])->toBe(['all' => ProviderIndexRequest::FILTERS, 'panel' => ProviderIndexRequest::PANEL_FILTERS]);

    $request = (string) file_get_contents(app_path('Http/Requests/ProviderIndexRequest.php'));
    $controller = (string) file_get_contents(app_path('Http/Controllers/ProviderController.php'));
    expect($request)->toMatch('/foreach \(\[\.\.\.self::FILTERS, \'sort\'\] as \$key\) \{\s*\$filters\[\$key\] = \$this->validFilter\(\$key, \$failed\);/');
    expect($controller)
        ->toContain('->appends(array_filter($filters));')
        ->toMatch("/'filterKeys' => \\[\\s*'all' => ProviderIndexRequest::FILTERS,\\s*'panel' => ProviderIndexRequest::PANEL_FILTERS,\\s*\\]/");
});

test('the page never writes the filters by hand: "Azzera filtri", the hidden fields and "Filtri (n)" read the list', function () {
    $filters = plfSource('components/provider-filters.tsx');
    $sources = $filters.plfSource('components/provider-filters-panel.tsx').plfSource('pages/providers/index.tsx');

    expect($filters)
        ->toMatch('/export function hasActiveFilters\(\s*filters: Filters,\s*keys: ProviderFilterKey\[\],?\s*\): boolean \{\s*return keys\.some\(\(key\) => filters\[key\] !== null\);/')
        ->toMatch('/export function activePanelFilters\(\s*filters: Filters,\s*keys: ProviderFilterKeys,?\s*\): number \{\s*return keys\.panel\.filter\(\(key\) => filters\[key\] !== null\)\.length;/')
        ->toMatch('/\\.\\.\\.keys\\.all\\.filter\\(\\(key\\) => !BAR_FIELDS\\.includes\\(key\\)\\)/');
    expect(plfSource('pages/providers/index.tsx'))
        ->toContain('const filtered = hasActiveFilters(filters, filterKeys.all);');
    expect(plfSource('components/provider-filters-panel.tsx'))
        ->toContain('const count = activePanelFilters(filters, keys);');
    // No condition on the names of the filters, no list of them written again.
    expect($sources)
        ->not->toMatch('/filters\.(category|city|q|max_price|with_photo) !== null \|\|/')
        ->not->toMatch("/\\['category', 'city'/");
});

test('the filters are kept by the links of the pagination', function () {
    foreach (range(1, 13) as $index) {
        $provider = plfProvider("Trecce {$index}", '25.00', ['city' => 'Bologna']);
        ProviderPhoto::factory()->for($provider)->create();
    }

    $props = plfProps(['max_price' => '30,5', 'with_photo' => '1', 'city' => 'Bologna', 'sort' => 'recent']);

    expect($props['providers']['meta']['total'])->toBe(13)
        ->and($props['providers']['links']['next'])->toBe(route('providers.index', ['city' => 'Bologna', 'max_price' => '30.50', 'with_photo' => '1', 'sort' => 'recent', 'page' => 2]))
        ->and(plfNames(['max_price' => '30.50', 'with_photo' => '1', 'city' => 'Bologna', 'sort' => 'recent', 'page' => 2]))->toHaveCount(1);
});

test('the list keeps 7 queries, 8 with a search, with the new filters too', function () {
    $count = function (array $query): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('providers.index', $query))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $one = plfProvider('Trecce Sahel 1', '25.00', ['city' => 'Bologna']);
    ProviderPhoto::factory()->for($one)->create();

    $counts = fn () => [
        $count(['max_price' => '40', 'with_photo' => '1']),
        $count(['max_price' => '40', 'with_photo' => '1', 'q' => 'trecce', 'city' => 'Bologna', 'sort' => 'price_asc']),
    ];
    expect($counts())->toBe([7, 8]);

    foreach (range(2, 6) as $index) {
        $provider = plfProvider("Trecce Sahel {$index}", '30.00', ['city' => 'Bologna']);
        Service::factory()->count(2)->for($provider)->create(['price' => '35.00']);
        ProviderPhoto::factory()->for($provider)->create();
    }
    expect($counts())->toBe([7, 8]);
});

// ---------------------------------------------------------------------------------------------
// The search bar, shared with the home page
// ---------------------------------------------------------------------------------------------

test('SearchFields is the search of both the home page and the list; only the list sends it with JavaScript', function () {
    $fields = plfSource('components/search-fields.tsx');
    $home = plfSource('components/home/home-search.tsx');
    $list = plfSource('components/provider-filters.tsx');

    expect($fields)->toContain('export function SearchFields(')
        ->not->toMatch('#<form|onSubmit|preventDefault|router\.#');
    expect($home)
        ->toContain("import { SearchFields } from '@/components/search-fields';")
        ->toMatch('/<form\s+role="search"\s+method="get"\s+action=\{index\(\)\.url\}\s+className="[^"]*"\s*>\s*<SearchFields\b/')
        ->not->toMatch('#onSubmit|preventDefault|router\.#');
    expect($list)
        ->toContain("import { SearchFields } from '@/components/search-fields';")
        ->toMatch('/onSubmit=\{search\}[\s\S]*<SearchFields\b/')
        ->toMatch('/const search = \\(event: FormEvent<HTMLFormElement>\\) => \\{\\s*event\\.preventDefault\\(\\);/')
        ->toMatch('/router\.visit\(index\(\{ query: \{ \.\.\.filters, \.\.\.changes \} \}\), \{\s*preserveScroll: true,\s*preserveState: true,?\s*\}\)/');
    // The city is a native select, applied with "Cerca": no Radix Select left in the list.
    expect($fields)->toMatch('/<select\s+id=\{cityId\}\s+name="city"/');
    expect($list.plfSource('pages/providers/index.tsx').plfSource('components/provider-filters-panel.tsx'))
        ->not->toMatch('/SelectTrigger|@\/components\/ui\/select/');
});

// ---------------------------------------------------------------------------------------------
// The panel of the filters
// ---------------------------------------------------------------------------------------------

test('the panel is the kit\'s Sheet, named by its title and description from t()', function () {
    $panel = plfSource('components/provider-filters-panel.tsx');

    expect($panel)
        ->toMatch("/import \\{[^}]*Sheet,[^}]*SheetContent,[^}]*SheetDescription,[^}]*SheetHeader,[^}]*SheetTitle,[^}]*SheetTrigger,?[^}]*\\} from '@\\/components\\/ui\\/sheet';/")
        ->toMatch("/<SheetTitle>\\{t\\('providers\\.panel\\.title'\\)\\}<\\/SheetTitle>\\s*<SheetDescription>\\s*\\{t\\('providers\\.panel\\.description'\\)\\}\\s*<\\/SheetDescription>/")
        // "Filtri", or "Filtri (n)" with the filters of the panel on.
        ->toMatch("/<SheetTrigger asChild>\\s*<Button type=\"button\" variant=\"outline\">\\s*\\{count > 0\\s*\\? t\\('providers\\.panel\\.open_count', \\{ count \\}\\)\\s*: t\\('providers\\.panel\\.open'\\)\\}/")
        ->not->toMatch('/aria-label(?!ledby)/');
    // The kit's Sheet is Radix's Dialog: it keeps the focus inside, closes with Esc and gives the
    // focus back to the trigger. Nothing here takes the focus or the keys by hand.
    expect(plfSource('components/ui/sheet.tsx'))->toContain('import * as SheetPrimitive from "@radix-ui/react-dialog"');
    expect($panel)
        ->toMatch('/<Sheet open=\{open\} onOpenChange=\{changeOpen\}>/')
        ->not->toMatch('/\.focus\(|onKeyDown|tabIndex|onEscapeKeyDown|onCloseAutoFocus|trapFocus|autoFocus/');
});

test('every field of the panel has its visible label; the price an example and a decimal keyboard', function () {
    $panel = plfSource('components/provider-filters-panel.tsx');

    expect($panel)
        ->toMatch("/<Label htmlFor=\"panel-category\">\\s*\\{t\\('providers\\.filters\\.category'\\)\\}\\s*<\\/Label>\\s*<select\\s+id=\"panel-category\"\\s+name=\"category\"/")
        ->toMatch("/<option value=\"\">\\s*\\{t\\('providers\\.filters\\.all_categories'\\)\\}\\s*<\\/option>/")
        ->toMatch("/<Label htmlFor=\"panel-max-price\">\\s*\\{t\\('providers\\.panel\\.max_price'\\)\\}\\s*<\\/Label>\\s*<Input\\s+id=\"panel-max-price\"\\s+name=\"max_price\"\\s+type=\"text\"\\s+inputMode=\"decimal\"/")
        ->toContain('aria-describedby="panel-max-price-help"')
        ->toMatch("/<p\\s+id=\"panel-max-price-help\"[^>]*>\\s*\\{t\\('providers\\.panel\\.max_price_help'\\)\\}/")
        ->toMatch("/<Checkbox\\s+id=\"panel-with-photo\"\\s+name=\"with_photo\"\\s+value=\"1\"[\\s\\S]*?\\/>\\s*<Label htmlFor=\"panel-with-photo\">\\s*\\{t\\('providers\\.panel\\.with_photo'\\)\\}/")
        ->not->toMatch('/<Label[^>]*sr-only/');
});

test('"Applica" visits the list with the panel\'s fields and closes it; "Azzera" empties the fields only', function () {
    $panel = plfSource('components/provider-filters-panel.tsx');

    expect($panel)
        ->toMatch("/<Button type=\"submit\">\\s*\\{t\\('providers\\.panel\\.apply'\\)\\}\\s*<\\/Button>\\s*<Button type=\"button\" variant=\"outline\" onClick=\\{clear\\}>\\s*\\{t\\('providers\\.panel\\.clear'\\)\\}/")
        ->toMatch("/visitList\\(filters, \\{\\s*category: category === '' \\? null : category,\\s*max_price: maxPrice\\.trim\\(\\) === '' \\? null : maxPrice\\.trim\\(\\),\\s*with_photo: withPhoto \\? '1' : null,?\\s*\\}\\);\\s*setOpen\\(false\\);/")
        ->toMatch("/const clear = \\(\\) => \\{\\s*setCategory\\(''\\);\\s*setMaxPrice\\(''\\);\\s*setWithPhoto\\(false\\);\\s*\\};/")
        // Opening starts again from the filters of the page.
        ->toMatch("/if \\(next\\) \\{\\s*setCategory\\(filters\\.category \\?\\? ''\\);\\s*setMaxPrice\\(filters\\.max_price \\?\\? ''\\);\\s*setWithPhoto\\(filters\\.with_photo !== null\\);/")
        // Without JavaScript the browser would send the search, the city and the order too.
        ->toMatch('/<form\s+method="get"\s+action=\{index\(\)\.url\}\s+onSubmit=\{apply\}/')
        ->toContain('<input type="hidden" name="sort" value={filters.sort} />');
});

test('44px targets on phones in the panel and in the bar', function () {
    $panel = plfSource('components/provider-filters-panel.tsx');

    expect($panel)->toMatch("/const selectClass =\\s*'[^']*\\bmax-md:min-h-11\\b[^']*';/");
    expect(plfSource('components/ui/input.tsx'))->toContain('max-md:min-h-11');
    expect(plfSource('components/ui/button.tsx'))->toContain('max-md:min-h-11 max-md:min-w-11');
    // The checkbox has its invisible 44px area (TouchAndMotionTest).
    expect(plfSource('components/ui/checkbox.tsx'))->toContain('max-md:after:-inset-3.5');
    // The bar: 44px under 768 px.
    expect(plfSource('components/provider-filters.tsx'))->toContain("const LOOK = { size: 'h-11 md:h-10', field: '', button: '' };");
});

// ---------------------------------------------------------------------------------------------
// The page
// ---------------------------------------------------------------------------------------------

test('the row of the results: "Filtri (n)", "Ordina per", then the status, outside every condition', function () {
    $page = plfSource('pages/providers/index.tsx');

    expect($page)
        ->toMatch('/<ProviderSearchBar\s+filters=\{filters\}\s+keys=\{filterKeys\}\s+cities=\{filterOptions\.cities\}\s*\/>\s*<div className="flex flex-wrap items-end gap-4">\s*<ProviderFiltersPanel\s+filters=\{filters\}\s+keys=\{filterKeys\}\s+categories=\{filterOptions\.categories\}\s*\/>\s*<ProviderSort filters=\{filters\} \/>[\s\S]*?<p role="status"/')
        ->and(substr_count($page, 'role="status"'))->toBe(1);
    // Before the first condition of the page: always there.
    $body = substr($page, (int) strpos($page, 'export default function'));
    expect(strpos($body, 'role="status"'))->toBeLessThan(strpos($body, '&&'));
});

test('ResultsLayout: one column without an aside; with one the list first, a grid from 1024 px, nothing sticks', function () {
    $layout = plfSource('components/results-layout.tsx');

    expect($layout)
        ->toMatch('/aside\?: ReactNode;/')
        ->toMatch('/if \(aside === undefined\) \{\s*return <div className="min-w-0">\{children\}<\/div>;\s*\}/')
        ->toMatch('/<div className="[^"]*lg:grid lg:grid-cols-\[minmax\(0,1fr\)_minmax\(0,40%\)\]">\s*<div className="min-w-0">\{children\}<\/div>\s*<aside className="min-w-0">\{aside\}<\/aside>/')
        ->not->toMatch('/\b(sticky|fixed)\b|position:/');
    // Today no map: no aside, not even an empty one.
    expect(plfSource('pages/providers/index.tsx'))
        ->toMatch('/<ResultsLayout>\s*\{providers\.data\.length > 0 && \(\s*<ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">/')
        ->not->toMatch('/<aside|aside=/');
});

test('the list lays out its own width in the site\'s container, without sticking or scrolling sideways', function () {
    expect(plfSource('layouts/public-layout.tsx'))->toContain("const FULL_WIDTH_PAGES = ['home', 'providers/index'];");

    $sources = plfSource('pages/providers/index.tsx').plfSource('components/results-layout.tsx').plfSource('components/provider-filters.tsx')
        .plfSource('components/provider-filters-panel.tsx').plfSource('components/search-fields.tsx').plfSource('components/provider-sort.tsx')
        .plfSource('components/provider-card.tsx');

    expect(plfSource('pages/providers/index.tsx'))->toContain('<div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-6">');
    expect($sources)
        ->not->toMatch('/\b(sticky|fixed)\b/')
        ->not->toMatch('/\b(w-screen|w-\[100vw\]|-mx-|overflow-x-(auto|scroll))\b/')
        ->not->toMatch('/(?<![\w:-])(min-w|w)-([1-9]\d*|\[[^\]]+\])(?![\w-])/');
    // The covers follow the grid: a third of the container at most.
    expect(plfSource('components/provider-card.tsx'))->toContain("'(min-width: 1152px) 23rem, (min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw'");
});

// ---------------------------------------------------------------------------------------------
// Texts and contacts
// ---------------------------------------------------------------------------------------------

test('the texts of the search and of the panel exist in Italian, French and English', function () {
    $json = fn (string $locale) => json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        sort($found[0]);

        return $found[0];
    };
    $keys = [
        'search.q', 'search.placeholder', 'search.city', 'search.all_cities', 'search.submit',
        'providers.panel.open', 'providers.panel.open_count', 'providers.panel.title', 'providers.panel.description',
        'providers.panel.max_price', 'providers.panel.max_price_help', 'providers.panel.with_photo', 'providers.panel.apply', 'providers.panel.clear',
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
    // The old keys of the two searches are gone: one set for both.
    foreach (['home.search.q', 'home.search.submit', 'providers.filters.search', 'providers.filters.search_submit', 'providers.filters.city', 'providers.filters.all_cities'] as $old) {
        expect($json('it'))->not->toHaveKey($old);
    }

    expect(__('providers.panel.open_count', ['count' => 2], 'it'))->toBe('Filtri (2)')
        ->and(__('providers.panel.apply', [], 'fr'))->toBe('Appliquer')
        ->and(__('search.q', [], 'en'))->toBe('Service or professional');
});

test('no real contact in the filters of the list: no email address, no phone number', function () {
    $sources = plfSource('components/search-fields.tsx').plfSource('components/provider-filters.tsx').plfSource('components/provider-filters-panel.tsx')
        .plfSource('components/results-layout.tsx').plfSource('pages/providers/index.tsx')
        .(string) file_get_contents(app_path('Http/Requests/ProviderIndexRequest.php'));
    foreach (['it', 'fr', 'en'] as $locale) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        $sources .= implode("\n", array_filter($json, fn (string $key) => str_starts_with($key, 'search.') || str_starts_with($key, 'providers.'), ARRAY_FILTER_USE_KEY));
    }

    expect($sources)
        ->not->toMatch('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/')
        ->not->toMatch('/(\+|00)\d[\d ]{7,}/')
        ->not->toContain('mailto:')
        ->not->toContain('wa.me/');
});
