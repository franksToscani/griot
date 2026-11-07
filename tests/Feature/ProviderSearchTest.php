<?php

use App\Http\Requests\ProviderIndexRequest;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Support\LikePattern;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A provider with one service. Every name is given: the factories write random words, which a
 * search could find by chance.
 *
 * @param  array<string, mixed>  $attributes
 */
function searchProvider(string $name, string $service = 'Servizio', ?Category $category = null, array $attributes = [], bool $published = true, bool $active = true): Provider
{
    $provider = Provider::factory()->state(['name' => $name, 'is_published' => $published, ...$attributes])->create();
    $category ??= searchCategory('generica', 'Generica');

    Service::factory()->for($provider)->for($category)->state(['name' => $service, 'is_active' => $active])->create();

    return $provider;
}

/**
 * A category with the given slug and saved name, made once.
 */
function searchCategory(string $slug, string $name): Category
{
    return Category::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
}

/**
 * The names of the listed providers, in the order shown.
 *
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function searchedNames(array $query, ?string $locale = null): array
{
    $names = [];
    $test = $locale === null ? test() : test()->withCookie('locale', $locale);

    $test->get(route('providers.index', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$names) {
            $names = array_column($page->toArray()['props']['providers']['data'], 'name');
        });

    return $names;
}

/**
 * The source of a React file.
 */
function searchSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

test('an empty search is ignored: the whole list, and q is null', function (string $text) {
    searchProvider('Barberia Lagos');
    searchProvider('Trecce Sahel');

    expect(searchedNames(['q' => $text]))->toBe(['Barberia Lagos', 'Trecce Sahel']);

    $this->get(route('providers.index', ['q' => $text]))
        ->assertInertia(fn (Assert $page) => $page->where('filters.q', null));
})->with([
    'empty' => [''],
    'only spaces' => ['   '],
]);

test('one word finds the provider by its name, in any case and inside a word', function () {
    searchProvider('Barberia Lagos');
    searchProvider('Trecce Sahel');

    expect(searchedNames(['q' => 'sahel']))->toBe(['Trecce Sahel'])
        ->and(searchedNames(['q' => 'TRECC']))->toBe(['Trecce Sahel'])
        ->and(searchedNames(['q' => 'zanzibar']))->toBe([]);
});

test('one word finds the provider by the name of an active service', function () {
    searchProvider('Barberia Lagos', 'Taglio sfumato');
    searchProvider('Trecce Sahel', 'Box braids lunghe');

    expect(searchedNames(['q' => 'braids']))->toBe(['Trecce Sahel'])
        ->and(searchedNames(['q' => 'sfumato']))->toBe(['Barberia Lagos']);
});

test('an inactive service is never searched', function () {
    searchProvider('Trecce Sahel', 'Box braids', active: false);
    searchProvider('Barberia Lagos', 'Taglio');

    expect(searchedNames(['q' => 'braids']))->toBe([]);
});

test('an unpublished provider is never found', function () {
    searchProvider('Trecce Sahel', 'Box braids');
    searchProvider('Trecce Bozza', 'Box braids', published: false);

    expect(searchedNames(['q' => 'trecce']))->toBe(['Trecce Sahel'])
        ->and(searchedNames(['q' => 'braids']))->toBe(['Trecce Sahel']);
});

test('every word must appear, each in any of the fields', function () {
    searchProvider('Trecce Sahel', 'Box braids');
    searchProvider('Trecce Lagos', 'Cornrows');
    searchProvider('Barberia Sahel', 'Taglio');

    // "sahel" in the name and "box" in the service: both words, in two fields.
    expect(searchedNames(['q' => 'sahel box']))->toBe(['Trecce Sahel'])
        ->and(searchedNames(['q' => 'trecce   sahel']))->toBe(['Trecce Sahel'])
        ->and(searchedNames(['q' => 'trecce']))->toBe(['Trecce Lagos', 'Trecce Sahel'])
        ->and(searchedNames(['q' => 'sahel zanzibar']))->toBe([]);
});

test('only the first five words count', function () {
    searchProvider('Trecce Sahel', 'Box braids');

    // The sixth word matches nothing: it is dropped, so the provider is still found.
    expect(searchedNames(['q' => 'trecce sahel box braids trecce zanzibar']))->toBe(['Trecce Sahel'])
        // The fifth word still counts.
        ->and(searchedNames(['q' => 'trecce sahel box braids zanzibar']))->toBe([])
        ->and(Provider::SEARCH_WORDS)->toBe(5);
});

test('percent, underscore and the escape character are plain characters', function (string $text, string $found) {
    searchProvider('100% Afro');
    searchProvider('Nail_Art');
    searchProvider('Wow! Trecce');
    searchProvider('Afro Nail Art Wow');

    expect(searchedNames(['q' => $text]))->toBe([$found]);
})->with([
    'percent' => ['%', '100% Afro'],
    'underscore' => ['_', 'Nail_Art'],
    'escape character' => ['!', 'Wow! Trecce'],
    'percent inside a word' => ['0% a', '100% Afro'],
]);

test('the LIKE pattern escapes its wildcards and lowers the text', function () {
    expect(LikePattern::contains('50%_!Ab'))->toBe('%50!%!_!!ab%')
        ->and(LikePattern::ESCAPE)->toBe('!')
        ->and(LikePattern::words("  uno\tdue  tre ", 2))->toBe(['uno', 'due'])
        ->and(LikePattern::words('   ', 5))->toBe([]);
});

test('a search longer than 100 characters, or not a string, is ignored', function () {
    searchProvider('Barberia Lagos');
    searchProvider('Trecce Sahel');

    $long = 'sahel'.str_repeat('x', 96);
    expect(mb_strlen($long))->toBe(101)
        ->and(searchedNames(['q' => $long]))->toBe(['Barberia Lagos', 'Trecce Sahel'])
        ->and(searchedNames(['q' => ['sahel']]))->toBe(['Barberia Lagos', 'Trecce Sahel'])
        ->and(ProviderIndexRequest::SEARCH_MAX)->toBe(100);

    $this->get(route('providers.index', ['q' => $long]))
        ->assertInertia(fn (Assert $page) => $page->where('filters.q', null));

    // 100 characters (multibyte ones count as one) are still a search.
    $limit = 'sahèl'.str_repeat('è', 95);
    $this->get(route('providers.index', ['q' => $limit]))
        ->assertInertia(fn (Assert $page) => $page->where('filters.q', $limit)->has('providers.data', 0));
});

test('the search is trimmed and kept in the props', function () {
    searchProvider('Trecce Sahel');

    $this->get(route('providers.index', ['q' => '  sahel  ']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('providers/index')
            ->where('filters', ['category' => null, 'city' => null, 'q' => 'sahel'])
            ->has('providers.data', 1)
            ->where('providers.data.0.name', 'Trecce Sahel')
            ->where('providers.meta.total', 1)
            ->has('filterOptions.categories')
            ->has('filterOptions.cities'));
});

test('the search finds the category by slug, saved name and the name in the active language', function () {
    $braids = searchCategory('treccine', 'Treccine');
    $barber = searchCategory('barbiere', 'Barbiere');
    searchProvider('Sahel', 'Cornrows', $braids);
    searchProvider('Lagos', 'Sfumatura', $barber);

    // Slug and saved name, in any language.
    expect(searchedNames(['q' => 'treccine']))->toBe(['Sahel'])
        ->and(searchedNames(['q' => 'barbiere'], 'en'))->toBe(['Lagos'])
        // The name of the active language (lang/*.json): "Braids" in English, "Tresses" in French.
        ->and(searchedNames(['q' => 'braids'], 'en'))->toBe(['Sahel'])
        ->and(searchedNames(['q' => 'tresses'], 'fr'))->toBe(['Sahel'])
        // Only the active one: in Italian "braids" is no category name.
        ->and(searchedNames(['q' => 'braids']))->toBe([]);
});

test('a category is searched only through active services', function () {
    $braids = searchCategory('treccine', 'Treccine');
    searchProvider('Sahel', 'Cornrows', $braids, active: false);
    searchProvider('Lagos', 'Taglio');

    expect(searchedNames(['q' => 'treccine']))->toBe([]);
});

test('the search combines with category and city', function () {
    $braids = searchCategory('treccine', 'Treccine');
    $barber = searchCategory('barbiere', 'Barbiere');
    searchProvider('Sahel Bologna', 'Box', $braids, ['city' => 'Bologna']);
    searchProvider('Sahel Milano', 'Box', $braids, ['city' => 'Milano']);
    searchProvider('Sahel Barber', 'Taglio', $barber, ['city' => 'Milano']);

    expect(searchedNames(['q' => 'sahel', 'city' => 'Milano']))->toBe(['Sahel Barber', 'Sahel Milano'])
        ->and(searchedNames(['q' => 'sahel', 'category' => 'treccine']))->toBe(['Sahel Bologna', 'Sahel Milano'])
        ->and(searchedNames(['q' => 'sahel', 'category' => 'treccine', 'city' => 'Milano']))->toBe(['Sahel Milano']);

    $this->get(route('providers.index', ['q' => 'sahel', 'category' => 'treccine', 'city' => 'Milano']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['category' => 'treccine', 'city' => 'Milano', 'q' => 'sahel']));
});

test('the page links keep the search', function () {
    foreach (range(1, 13) as $index) {
        searchProvider(sprintf('Trecce %02d', $index), attributes: ['city' => 'Bologna']);
    }
    searchProvider('Barberia Lagos', attributes: ['city' => 'Bologna']);

    $this->get(route('providers.index', ['q' => 'trecce', 'city' => 'Bologna']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('providers.data', 12)
            ->where('providers.meta.total', 13)
            ->where('providers.links.next', route('providers.index', ['city' => 'Bologna', 'q' => 'trecce', 'page' => 2])));

    expect(searchedNames(['q' => 'trecce', 'city' => 'Bologna', 'page' => 2]))->toBe(['Trecce 13']);
});

test('the number of queries of a search does not grow with the providers found', function () {
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('providers.index', ['q' => 'trecce sahel']))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    searchProvider('Trecce Sahel 1');
    $withOne = $countQueries();

    foreach (range(2, 6) as $index) {
        searchProvider("Trecce Sahel {$index}");
    }

    expect($countQueries())->toBe($withOne);
});

test('the search field has a visible label, a submit button and sends q with the other filters', function () {
    $source = searchSource('components/provider-filters.tsx');

    expect($source)
        ->toMatch('/<form\s+role="search"\s+method="get"\s+action=\{index\(\)\.url\}\s+onSubmit=\{search\}/')
        ->toMatch('/<Label htmlFor="filter-search">\s*\{t\(\'providers\.filters\.search\'\)\}\s*<\/Label>/')
        ->toMatch('/<Input\s+id="filter-search"\s+type="search"\s+name="q"\s+maxLength=\{SEARCH_MAX\}\s+value=\{text\}/')
        ->toMatch('/<Button type="submit">\s*\{t\(\'providers\.filters\.search_submit\'\)\}\s*<\/Button>/')
        ->toMatch('/<input\s+type="hidden"\s+name="category"/')
        ->toMatch('/<input type="hidden" name="city"/')
        ->toContain('const SEARCH_MAX = 100;')
        // Every visit of the filters keeps the page mounted: the status changes and is read.
        ->toMatch('/preserveScroll: true,\s+preserveState: true,/')
        ->not->toContain('aria-label');
});

test('the field follows the search of the page and empties with it', function () {
    $source = searchSource('components/provider-filters.tsx');

    expect($source)
        ->toContain("useState(filters.q ?? '')")
        ->toMatch("/if \\(filters\\.q !== shownSearch\\) \\{\\s+setShownSearch\\(filters\\.q\\);\\s+setText\\(filters\\.q \\?\\? ''\\);/")
        ->toMatch("/apply\\('q', text\\.trim\\(\\) === '' \\? null : text\\.trim\\(\\)\\)/")
        ->toMatch('/filters\.category !== null \|\| filters\.city !== null \|\| filters\.q !== null/');
});

test('the number of results, or the empty state, is announced in a status always on the page', function () {
    $source = searchSource('pages/providers/index.tsx');

    expect($source)
        ->toMatch('/<p role="status" className="text-muted-foreground">\s*\{resultsText\(t, providers\.meta\.total, filters\)\}\s*<\/p>/')
        ->toContain("t('providers.list.results_one')")
        ->toContain("t('providers.list.results', { count: total })")
        ->toContain("t('providers.list.empty_search')")
        ->toContain("t('providers.list.empty_filtered')")
        ->toContain("t('providers.list.empty')")
        ->not->toContain('aria-label');

    // The status is outside the condition on the results: it is there with and without them.
    expect(strpos($source, 'role="status"'))->toBeLessThan(strpos($source, 'providers.data.length === 0'));
});

test('the keys of the search are in the three languages, with the same placeholders', function () {
    $keys = [
        'providers.filters.search',
        'providers.filters.search_submit',
        'providers.list.results',
        'providers.list.results_one',
        'providers.list.empty_search',
    ];

    foreach (['it', 'fr', 'en'] as $locale) {
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        foreach ($keys as $key) {
            expect($translations)->toHaveKey($key);
            expect($translations[$key])->not->toBe('');
            preg_match_all('/:[a-z_]+/', $translations[$key], $placeholders);
            expect($placeholders[0])->toBe($key === 'providers.list.results' ? [':count'] : [], "{$locale} {$key}");
        }
    }
});
