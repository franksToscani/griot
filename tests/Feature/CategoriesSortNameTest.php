<?php

use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use Database\Seeders\CategorySeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Finder\Finder;

// The categories in alphabetical order of the name shown in the page's language
// (Category::sortName(), PR 2 of the categories): in the filter of the list, in the two forms of
// the services and on the home page. Never the order of the saved Italian name.

/**
 * The nine categories of the seeder, each with an active service of a published provider: all
 * offered, so that the home page and the filter show them all, in one group.
 */
function csnProvider(): Provider
{
    (new CategorySeeder)->run();
    $provider = Provider::factory()->published()->create();

    foreach (Category::query()->get() as $category) {
        Service::factory()->for($provider)->for($category)->create(['is_active' => true]);
    }

    return $provider;
}

/**
 * The names of a list of categories in a prop, in their order.
 *
 * @return list<string>
 */
function csnNames(mixed $categories): array
{
    return collect($categories)->pluck('name')->values()->all();
}

dataset('csn locales', [
    'French' => ['fr', ['Cils et sourcils', 'Coupe femme', 'Coupe homme', 'Food', 'Locks', 'Maquillage', 'Mode afro', 'Ongles', 'Tresses']],
    'English' => ['en', ['Afro fashion', 'Braids', 'Food', 'Lashes & brows', 'Locs', 'Make-up', "Men's haircut", 'Nails', "Women's haircut"]],
]);

test('the filter of the list, the two forms of the services and the home page follow the name shown', function (string $locale, array $expected) {
    $provider = csnProvider();
    $service = $provider->services()->firstOrFail();

    $this->withCookie('locale', $locale)->get(route('providers.index'))
        ->assertInertia(fn (Assert $page) => $page->where('filterOptions.categories', fn ($categories) => csnNames($categories) === $expected));

    $this->withCookie('locale', $locale)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('categories', fn ($categories) => csnNames($categories) === $expected));

    $this->actingAs($provider->user)->withCookie('locale', $locale)->get(route('provider.services.index'))
        ->assertInertia(fn (Assert $page) => $page->where('categories', fn ($categories) => csnNames($categories) === $expected));

    $this->actingAs($provider->user)->withCookie('locale', $locale)->get(route('provider.services.edit', $service->id))
        ->assertInertia(fn (Assert $page) => $page->where('categories', fn ($categories) => csnNames($categories) === $expected));
})->with('csn locales');

test('never the order of the saved Italian name: the categories are sorted with sortName()', function () {
    $sources = [
        'app/Http/Requests/ProviderIndexRequest.php',
        'app/Http/Controllers/ProviderServiceIndexController.php',
        'app/Http/Controllers/ProviderServiceEditController.php',
        'app/Http/Controllers/HomeController.php',
    ];

    foreach ($sources as $path) {
        $source = (string) file_get_contents(base_path($path));

        expect($source)->toContain('->sortName()')
            ->not->toMatch('/Category::query\(\)[^;]*orderBy\([\'"]name[\'"]\)/');
    }

    // Nowhere in the application are the categories ordered by their saved name.
    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        expect($file->getContents())->not->toMatch('/Category::query\(\)[^;]*orderBy\([\'"]name[\'"]\)/', $file->getRelativePathname());
    }
});
