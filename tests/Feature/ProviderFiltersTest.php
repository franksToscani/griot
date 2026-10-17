<?php

use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A provider with one active service in the given category.
 *
 * @param  array<string, mixed>  $attributes
 */
function providerOffering(Category $category, array $attributes = [], bool $published = true): Provider
{
    $provider = Provider::factory()->state(['is_published' => $published, ...$attributes])->create();
    Service::factory()->for($provider)->for($category)->create();

    return $provider;
}

/**
 * The names of the listed providers, in the order shown.
 *
 * @param  array<string, string>  $query
 * @return list<string>
 */
function listedProviderNames(array $query): array
{
    $names = [];

    test()->get(route('providers.index', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$names) {
            $names = array_column($page->toArray()['props']['providers']['data'], 'name');
        });

    return $names;
}

test('the category filter shows providers with an active service in that category', function () {
    $braids = Category::factory()->create(['slug' => 'treccine']);
    $barber = Category::factory()->create(['slug' => 'barbiere']);
    providerOffering($braids, ['name' => 'Trecce Sahel']);
    providerOffering($barber, ['name' => 'Barberia Lagos']);

    expect(listedProviderNames(['category' => 'treccine']))->toBe(['Trecce Sahel']);
});

test('an inactive service does not put its provider in that category', function () {
    $braids = Category::factory()->create(['slug' => 'treccine']);
    providerOffering($braids, ['name' => 'Trecce Sahel']);
    $inactive = Provider::factory()->published()->create(['name' => 'Solo inattivo']);
    Service::factory()->inactive()->for($inactive)->for($braids)->create();

    expect(listedProviderNames(['category' => 'treccine']))->toBe(['Trecce Sahel']);
});

test('the city filter shows only providers in that city', function () {
    $category = Category::factory()->create();
    providerOffering($category, ['name' => 'Trecce Sahel', 'city' => 'Bologna']);
    providerOffering($category, ['name' => 'Barberia Lagos', 'city' => 'Milano']);

    expect(listedProviderNames(['city' => 'Milano']))->toBe(['Barberia Lagos']);
});

test('the filters combine', function () {
    $braids = Category::factory()->create(['slug' => 'treccine']);
    $barber = Category::factory()->create(['slug' => 'barbiere']);
    providerOffering($braids, ['name' => 'Trecce Bologna', 'city' => 'Bologna']);
    providerOffering($braids, ['name' => 'Trecce Milano', 'city' => 'Milano']);
    providerOffering($barber, ['name' => 'Barberia Milano', 'city' => 'Milano']);

    expect(listedProviderNames(['category' => 'treccine', 'city' => 'Milano']))->toBe(['Trecce Milano']);
});

test('unknown or empty filter values are ignored', function () {
    $category = Category::factory()->create();
    providerOffering($category, ['name' => 'Barberia Lagos', 'city' => 'Milano']);
    providerOffering($category, ['name' => 'Trecce Sahel', 'city' => 'Bologna']);

    expect(listedProviderNames(['category' => 'sconosciuta', 'city' => 'Atlantide']))
        ->toBe(['Barberia Lagos', 'Trecce Sahel']);
    expect(listedProviderNames(['category' => '', 'city' => 'Milano']))->toBe(['Barberia Lagos']);

    $this->get(route('providers.index', ['category' => 'sconosciuta', 'city' => 'Milano']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.category', null)
            ->where('filters.city', 'Milano'));
});

test('an unpublished provider is never listed, even when it matches the filters', function () {
    $braids = Category::factory()->create(['slug' => 'treccine']);
    providerOffering($braids, ['name' => 'Trecce Sahel', 'city' => 'Bologna']);
    providerOffering($braids, ['name' => 'Bozza', 'city' => 'Bologna'], published: false);

    expect(listedProviderNames(['category' => 'treccine', 'city' => 'Bologna']))->toBe(['Trecce Sahel']);
});

test('the filter options contain only offered categories and published cities', function () {
    $braids = Category::factory()->create(['name' => 'Treccine', 'slug' => 'treccine']);
    $nails = Category::factory()->create(['name' => 'Nails', 'slug' => 'nails']);
    $onlyUnpublished = Category::factory()->create(['slug' => 'couture']);
    $onlyInactive = Category::factory()->create(['slug' => 'ciglia']);
    Category::factory()->create(['slug' => 'ristorazione']);

    providerOffering($braids, ['city' => 'Torino']);
    providerOffering($nails, ['city' => 'Bologna']);
    providerOffering($braids, ['city' => 'Bologna']);
    providerOffering($onlyUnpublished, ['city' => 'Roma'], published: false);
    Service::factory()->inactive()->for(Provider::factory()->published()->create(['city' => 'Milano']))->for($onlyInactive)->create();

    $this->get(route('providers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filterOptions.categories', [
                ['id' => $nails->id, 'slug' => 'nails', 'name' => 'Nails'],
                ['id' => $braids->id, 'slug' => 'treccine', 'name' => 'Treccine'],
            ])
            // Milano is listed: its provider is published, even if it has no active service.
            ->where('filterOptions.cities', ['Bologna', 'Milano', 'Torino']));
});

test('the filters survive the pagination', function () {
    $category = Category::factory()->create();
    foreach (range(1, 13) as $index) {
        providerOffering($category, ['city' => 'Bologna']);
    }
    providerOffering($category, ['city' => 'Milano']);

    $this->get(route('providers.index', ['city' => 'Bologna', 'category' => 'sconosciuta']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('providers.data', 12)
            ->where('providers.meta.last_page', 2)
            // The valid filter is kept in the page links, the invalid one is dropped.
            ->where('providers.links.next', route('providers.index', ['city' => 'Bologna', 'page' => 2])));

    $this->get(route('providers.index', ['city' => 'Bologna', 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('providers.data', 1)
            ->where('filters.city', 'Bologna'));
});

test('a filter combination without results returns an empty list and the active filters', function () {
    $braids = Category::factory()->create(['slug' => 'treccine']);
    providerOffering($braids, ['city' => 'Bologna']);
    providerOffering(Category::factory()->create(), ['city' => 'Milano']);

    $this->get(route('providers.index', ['category' => 'treccine', 'city' => 'Milano']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('providers.data', 0)
            ->where('filters', ['category' => 'treccine', 'city' => 'Milano']));
});

test('the number of queries does not grow with the number of filtered providers', function () {
    $braids = Category::factory()->create(['slug' => 'treccine']);
    $query = ['category' => 'treccine', 'city' => 'Bologna'];

    $countQueries = function () use ($query): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('providers.index', $query))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    providerOffering($braids, ['city' => 'Bologna']);
    $withOneProvider = $countQueries();

    foreach (range(1, 5) as $index) {
        $provider = providerOffering($braids, ['city' => 'Bologna']);
        Service::factory()->count(2)->for($provider)->create();
    }
    $withSixProviders = $countQueries();

    expect($withSixProviders)->toBe($withOneProvider);
});
