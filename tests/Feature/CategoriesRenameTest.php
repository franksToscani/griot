<?php

use App\Models\Booking;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Inertia\Testing\AssertableInertia as Assert;

// The nine categories (docs/home.md): the data migration rename_and_merge_categories, the old slugs
// read as the new ones in the list's filter, the seeder. The migration is run by hand on a database
// that looks like production before it: the seven old categories, with services and bookings.

const CR_OLD = [
    'treccine' => 'Treccine',
    'barbiere' => 'Barbiere',
    'nails' => 'Nails',
    'ciglia' => 'Ciglia',
    'sopracciglia' => 'Sopracciglia',
    'couture' => 'Couture',
    'ristorazione' => 'Ristorazione',
];

const CR_NEW = ['taglio-uomo', 'taglio-donna', 'treccine', 'locs', 'unghie', 'make-up', 'ciglia-sopracciglia', 'moda-afro', 'food'];

function crMigration(): object
{
    return require database_path('migrations/2026_11_18_100000_rename_and_merge_categories.php');
}

/**
 * The seven categories of before, each with a service of its own, and a booking on each service.
 *
 * @return array<string, int> old slug => id of its service
 */
function crOldSite(): array
{
    $provider = Provider::factory()->published()->create();
    $services = [];

    foreach (CR_OLD as $slug => $name) {
        $category = Category::factory()->create(['slug' => $slug, 'name' => $name]);
        $service = Service::factory()->for($provider)->for($category)->create();
        Booking::factory()->for($provider)->for($service)->create();
        $services[$slug] = $service->id;
    }

    return $services;
}

/**
 * Every category by slug, with its name and the ids of its services.
 *
 * @return array<string, array{name: string, services: list<int>}>
 */
function crState(): array
{
    $state = [];

    foreach (Category::query()->orderBy('slug')->get() as $category) {
        $state[$category->slug] = [
            'name' => $category->name,
            'services' => Service::query()->where('category_id', $category->id)->orderBy('id')->pluck('id')->all(),
        ];
    }

    return $state;
}

function crCategoryOf(int $serviceId): string
{
    return Service::query()->findOrFail($serviceId)->category->slug;
}

// ---------------------------------------------------------------------------------------------
// The migration
// ---------------------------------------------------------------------------------------------

test('up renames on the same row, merges ciglia and sopracciglia, adds the three new ones', function () {
    $services = crOldSite();
    $ids = Category::query()->pluck('id', 'slug');
    $bookings = Booking::query()->orderBy('id')->get(['id', 'service_id'])->toArray();

    crMigration()->up();

    expect(Category::query()->orderBy('slug')->pluck('slug')->all())->toBe(collect(CR_NEW)->sort()->values()->all());

    // Renamed on the same row: the same id, the new slug and name.
    foreach (['barbiere' => ['taglio-uomo', 'Taglio uomo'], 'nails' => ['unghie', 'Unghie'], 'couture' => ['moda-afro', 'Moda afro'], 'ristorazione' => ['food', 'Food'], 'ciglia' => ['ciglia-sopracciglia', 'Ciglia e sopracciglia']] as $old => [$new, $name]) {
        $category = Category::query()->where('slug', $new)->sole();
        expect($category->id)->toBe($ids[$old])->and($category->name)->toBe($name)
            ->and(crCategoryOf($services[$old]))->toBe($new);
    }

    // The services of sopracciglia moved to the merged one; its row is gone.
    expect(crCategoryOf($services['sopracciglia']))->toBe('ciglia-sopracciglia')
        ->and(Category::query()->where('slug', 'sopracciglia')->exists())->toBeFalse()
        ->and(crCategoryOf($services['treccine']))->toBe('treccine');

    // The new ones, without services: "In arrivo".
    foreach (['taglio-donna' => 'Taglio donna', 'locs' => 'Locs', 'make-up' => 'Make-up'] as $slug => $name) {
        expect(Category::query()->where('slug', $slug)->sole()->name)->toBe($name)
            ->and(Category::query()->where('slug', $slug)->sole()->services()->count())->toBe(0);
    }

    // No service and no booking lost, every booking on its own service.
    expect(Service::query()->count())->toBe(7)
        ->and(Booking::query()->orderBy('id')->get(['id', 'service_id'])->toArray())->toBe($bookings);
});

test('when the old and the new slug both exist, the services move to the new one and the old row goes', function () {
    $services = crOldSite();
    // CategorySeeder run before the migration: the new rows exist already, one with a service.
    $new = Category::factory()->create(['slug' => 'taglio-uomo', 'name' => 'Taglio uomo']);
    $already = Service::factory()->for(Provider::factory())->for($new)->create();
    Category::factory()->create(['slug' => 'locs', 'name' => 'Locs']);

    crMigration()->up();

    expect(Category::query()->where('slug', 'barbiere')->exists())->toBeFalse()
        ->and(crCategoryOf($services['barbiere']))->toBe('taglio-uomo')
        ->and(crCategoryOf($already->id))->toBe('taglio-uomo')
        ->and(Category::query()->where('slug', 'taglio-uomo')->sole()->id)->toBe($new->id)
        ->and(Category::query()->where('slug', 'locs')->count())->toBe(1)
        ->and(Service::query()->count())->toBe(8);
});

test('idempotent: run twice, the second run changes nothing', function () {
    crOldSite();
    $migration = crMigration();

    $migration->up();
    $first = crState();
    $migration->up();

    expect(crState())->toBe($first);
});

test('on a database without categories (a new install, the tests) it creates nothing: the seeder does', function () {
    crMigration()->up();

    expect(Category::query()->count())->toBe(0);
});

test('down: the old slugs and names back, sopracciglia again but empty, the unused new ones gone', function () {
    $services = crOldSite();
    $migration = crMigration();
    $migration->up();
    // A provider already uses a new category: it cannot be deleted, it stays.
    $locs = Service::factory()->for(Provider::factory())->for(Category::query()->where('slug', 'locs')->sole())->create();

    $migration->down();

    expect(Category::query()->orderBy('slug')->pluck('name', 'slug')->all())->toBe([
        'barbiere' => 'Barbiere',
        'ciglia' => 'Ciglia',
        'couture' => 'Couture',
        'locs' => 'Locs',
        'nails' => 'Nails',
        'ristorazione' => 'Ristorazione',
        'sopracciglia' => 'Sopracciglia',
        'treccine' => 'Treccine',
    ]);

    // It cannot split the merged services: the ones of sopracciglia stay in ciglia.
    expect(crCategoryOf($services['sopracciglia']))->toBe('ciglia')
        ->and(Category::query()->where('slug', 'sopracciglia')->sole()->services()->count())->toBe(0)
        ->and(crCategoryOf($services['barbiere']))->toBe('barbiere')
        ->and(crCategoryOf($locs->id))->toBe('locs')
        ->and(Service::query()->count())->toBe(8)
        ->and(Booking::query()->count())->toBe(7);
});

// ---------------------------------------------------------------------------------------------
// The old slugs in the list's filter
// ---------------------------------------------------------------------------------------------

test('an old slug in the address filters on its new category, without a redirect', function (string $old, string $new) {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 08:00', 'UTC'));
    $category = Category::factory()->create(['slug' => $new, 'name' => $new]);
    $inside = Provider::factory()->published()->create(['name' => 'Dentro']);
    Service::factory()->for($inside)->for($category)->create();
    $outside = Provider::factory()->published()->create(['name' => 'Fuori']);
    Service::factory()->for($outside)->create();

    $this->get(route('providers.index', ['category' => $old]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.category', $new)
            ->has('providers.data', 1)
            ->where('providers.data.0.name', 'Dentro'));
})->with([
    ['barbiere', 'taglio-uomo'],
    ['nails', 'unghie'],
    ['couture', 'moda-afro'],
    ['ristorazione', 'food'],
    ['ciglia', 'ciglia-sopracciglia'],
    ['sopracciglia', 'ciglia-sopracciglia'],
]);

test('the aliases are written in one place, with a note to remove them', function () {
    $request = (string) file_get_contents(app_path('Http/Requests/ProviderIndexRequest.php'));

    expect($request)->toContain('public const OLD_CATEGORY_SLUGS = [')
        ->toContain('To remove in a few months')
        ->toMatch("/\\\$this->merge\\(\\['category' => self::OLD_CATEGORY_SLUGS\\[\\\$category\\]\\]\\);/");
});

// ---------------------------------------------------------------------------------------------
// The seeder and the texts
// ---------------------------------------------------------------------------------------------

test('the seeder has the nine slugs, never an old one, each with its name and sentence in three languages', function () {
    $this->seed(CategorySeeder::class);

    expect(Category::query()->orderBy('slug')->pluck('slug')->all())->toBe(collect(CR_NEW)->sort()->values()->all());

    foreach (['it', 'fr', 'en'] as $locale) {
        $texts = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        foreach (CR_NEW as $slug) {
            expect($texts)->toHaveKey("categories.{$slug}")->toHaveKey("home.carousel.sentence.{$slug}");
        }

        foreach (array_keys(CR_OLD) as $slug) {
            if ($slug !== 'treccine') {
                expect($texts)->not->toHaveKey("categories.{$slug}")->not->toHaveKey("home.carousel.sentence.{$slug}");
            }
        }
    }

    expect(__('categories.taglio-uomo', [], 'en'))->toBe("Men's haircut")
        ->and(__('categories.make-up', [], 'fr'))->toBe('Maquillage')
        ->and(__('categories.food', [], 'fr'))->toBe('Food')
        ->and(__('categories.ciglia-sopracciglia', [], 'it'))->toBe('Ciglia e sopracciglia');
});

test('the photos of "Taglio uomo" and "Unghie" are those of barbiere and nails, the files unchanged', function () {
    $photos = (string) file_get_contents(resource_path('js/lib/home-photos.ts'));

    expect($photos)->toMatch("/'taglio-uomo': \\{[\\s\\S]*?url: barbiere640/")
        ->toMatch('/unghie: \{[\s\S]*?url: nails640/')
        ->not->toMatch('/^\s+(barbiere|nails): \{/m');
});
