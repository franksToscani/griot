<?php

use App\Models\Availability;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// When a provider was published (issue #144, step 1): written when the profile goes from not
// published to published, null while it is not, never shown. The home page will sort by it.

/**
 * A provider of its own user, complete (an active service and an availability).
 */
function pdtProvider(bool $published = false): Provider
{
    $provider = $published
        ? Provider::factory()->published()->create()
        : Provider::factory()->create();
    Service::factory()->for($provider)->create();
    Availability::factory()->for($provider)->create();

    return $provider;
}

function pdtPost(Provider $provider, string $route): void
{
    test()->actingAs($provider->user)->post(route($route))->assertRedirect(route('dashboard'));
}

function pdtDate(Provider $provider): ?string
{
    return $provider->fresh()?->published_at?->toDateTimeString();
}

// ---------------------------------------------------------------------------------------------
// The switch
// ---------------------------------------------------------------------------------------------

test('publishing writes the moment the profile went from not published to published', function () {
    $provider = pdtProvider();
    expect($provider->published_at)->toBeNull();

    $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
    pdtPost($provider, 'provider.publish');

    expect($provider->fresh()->is_published)->toBeTrue()
        ->and(pdtDate($provider))->toBe('2026-11-05 10:00:00');
});

test('publishing again an already published profile keeps its date', function () {
    $provider = pdtProvider();
    $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
    pdtPost($provider, 'provider.publish');

    $this->travelTo(Carbon::parse('2026-11-08 18:30:00'));
    pdtPost($provider, 'provider.publish');

    expect(pdtDate($provider))->toBe('2026-11-05 10:00:00');
});

test('taking the profile off empties the date; published again, it counts as new', function () {
    $provider = pdtProvider();
    $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
    pdtPost($provider, 'provider.publish');

    $this->travelTo(Carbon::parse('2026-11-06 09:00:00'));
    pdtPost($provider, 'provider.unpublish');
    expect($provider->fresh()->is_published)->toBeFalse()
        ->and(pdtDate($provider))->toBeNull();

    // Taking off an already unpublished profile writes nothing, and the date stays empty.
    pdtPost($provider, 'provider.unpublish');
    expect(pdtDate($provider))->toBeNull();

    $this->travelTo(Carbon::parse('2026-11-20 15:45:00'));
    pdtPost($provider, 'provider.publish');
    expect(pdtDate($provider))->toBe('2026-11-20 15:45:00');
});

test('a profile that loses a requirement keeps its date until it is taken off (#93)', function () {
    $provider = pdtProvider();
    $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
    pdtPost($provider, 'provider.publish');

    // #93: no automatic unpublishing, only a warning on the dashboard.
    $provider->services()->update(['is_active' => false]);
    $this->actingAs($provider->user)->get(route('dashboard'))->assertOk();
    expect($provider->fresh()->is_published)->toBeTrue()
        ->and(pdtDate($provider))->toBe('2026-11-05 10:00:00');

    // The warning proposes the existing action: the date goes with it.
    pdtPost($provider, 'provider.unpublish');
    expect(pdtDate($provider))->toBeNull();
});

test('a refused publication and a new profile leave the date empty', function () {
    $incomplete = Provider::factory()->create();
    $this->actingAs($incomplete->user)->post(route('provider.publish'))->assertSessionHasErrors('publish');
    expect(pdtDate($incomplete))->toBeNull();

    $user = User::factory()->create();
    $this->actingAs($user)->post(route('provider.store'), [
        'name' => 'Salone Nuovo',
        'city' => 'Bologna',
        'slug' => 'salone-nuovo',
    ])->assertSessionHasNoErrors();
    expect($user->fresh()->provider->published_at)->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// The schema and the migration
// ---------------------------------------------------------------------------------------------

test('the column is a nullable timestamp after is_published, with an index for the order', function () {
    $columns = Schema::getColumnListing('providers');
    expect($columns)->toContain('published_at');
    // after() places it on MySQL; SQLite, the tests' database, always adds a column at the end.
    if (DB::getDriverName() === 'mysql') {
        expect(array_search('published_at', $columns, true))->toBe(array_search('is_published', $columns, true) + 1);
    }

    $column = collect(Schema::getColumns('providers'))->firstWhere('name', 'published_at');
    expect($column['nullable'])->toBeTrue()
        ->and(strtolower((string) $column['type_name']))->toBeIn(['datetime', 'timestamp']);

    $index = collect(Schema::getIndexes('providers'))->firstWhere('name', 'providers_published_index');
    expect($index['columns'])->toBe(['is_published', 'published_at'])
        ->and($index['unique'])->toBeFalse();
});

test('the migration fills the published providers with updated_at, and its down removes the column', function () {
    $migration = require database_path('migrations/2026_11_05_100000_add_published_at_to_providers_table.php');
    $migration->down();
    expect(Schema::hasColumn('providers', 'published_at'))->toBeFalse();

    // Rows as they were before the column: the query builder, the model knows the column.
    $user = fn () => User::factory()->create()->id;
    DB::table('providers')->insert([
        ['user_id' => $user(), 'slug' => 'pubblicato', 'name' => 'A', 'city' => 'Bologna', 'is_published' => true, 'created_at' => '2026-01-01 08:00:00', 'updated_at' => '2026-03-04 12:30:00'],
        ['user_id' => $user(), 'slug' => 'bozza', 'name' => 'B', 'city' => 'Bologna', 'is_published' => false, 'created_at' => '2026-01-01 08:00:00', 'updated_at' => '2026-03-05 12:30:00'],
    ]);

    $migration->up();

    expect(DB::table('providers')->where('slug', 'pubblicato')->value('published_at'))->toBe('2026-03-04 12:30:00')
        ->and(DB::table('providers')->where('slug', 'bozza')->value('published_at'))->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// The factory and the seeder
// ---------------------------------------------------------------------------------------------

test('the factory: published() has a date in the past, every other provider none', function () {
    $published = Provider::factory()->published()->create();

    expect($published->is_published)->toBeTrue()
        ->and($published->published_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($published->published_at->isPast())->toBeTrue()
        ->and(Provider::factory()->create()->published_at)->toBeNull()
        ->and(Provider::factory()->make()->published_at)->toBeNull();
});

test('in the seeder the published providers have different dates, the unpublished one none', function () {
    $this->seed(DatabaseSeeder::class);
    $first = Provider::query()->published()->pluck('published_at', 'slug')->map(fn ($date) => (string) $date)->all();

    expect(Provider::query()->published()->whereNull('published_at')->count())->toBe(0)
        ->and(Provider::query()->where('is_published', false)->whereNotNull('published_at')->count())->toBe(0)
        ->and(array_unique($first))->toHaveCount(count($first))
        ->and(Provider::query()->published()->orderByDesc('published_at')->value('slug'))->toBe('trecce-sahel');

    // A second run keeps the dates.
    $this->travel(2)->days();
    $this->seed(DatabaseSeeder::class);
    expect(Provider::query()->published()->pluck('published_at', 'slug')->map(fn ($date) => (string) $date)->all())->toBe($first);
});

// ---------------------------------------------------------------------------------------------
// Never shown
// ---------------------------------------------------------------------------------------------

test('no page and no resource shows the date', function (string $page, bool $asOwner) {
    $provider = pdtProvider(published: true);
    expect($provider->published_at)->not->toBeNull();

    if ($asOwner) {
        $this->actingAs($provider->user);
    }

    $url = match ($page) {
        'list' => route('providers.index'),
        'public page' => route('providers.show', $provider->slug),
        default => route($page),
    };

    $this->get($url)
        ->assertOk()
        ->assertDontSee('published_at')
        ->assertDontSee($provider->published_at->toIso8601String(), false);
})->with([
    'the list, guest' => ['list', false],
    'the public page, guest' => ['public page', false],
    'the public page, owner' => ['public page', true],
    'the dashboard' => ['dashboard', true],
    'the edit page' => ['provider.edit', true],
    'the preview' => ['provider.preview', true],
    'the services' => ['provider.services.index', true],
]);

test('the model serialized whole hides it, as auth.user with its provider', function () {
    $provider = pdtProvider(published: true);

    expect($provider->toArray())->not->toHaveKey('published_at')
        ->and($provider->user->load('provider')->toArray()['provider'])->not->toHaveKey('published_at');

    foreach (File::files(app_path('Http/Resources')) as $file) {
        expect((string) file_get_contents($file->getPathname()))->not->toContain('published_at', $file->getFilename());
    }
});

// ---------------------------------------------------------------------------------------------
// The source
// ---------------------------------------------------------------------------------------------

test('only PublishProvider changes is_published in the app, always with published_at', function () {
    // Every write of is_published in app/ and database/ (not the migrations): an assignment, or a
    // key of an array (create, update, forceFill, fill, a factory state). The Resources only read it,
    // the model only casts it.
    $reads = ["'is_published' => \$this->is_published,", "'is_published' => 'boolean',"];
    $writes = [];

    foreach ([...File::allFiles(app_path()), ...File::allFiles(database_path('factories')), ...File::allFiles(database_path('seeders'))] as $file) {
        foreach (file($file->getPathname()) ?: [] as $number => $line) {
            if (preg_match('/->is_published\s*=(?!=)|[\'"]is_published[\'"]\s*=>/', $line) === 1 && ! in_array(trim($line), $reads, true)) {
                $writes[] = str_replace(base_path().'/', '', $file->getPathname()).': '.trim($line);
            }
        }
    }

    sort($writes);
    expect($writes)->toBe([
        // A new profile starts unpublished: the column's default, null.
        'app/Profile/CreateProviderProfile.php: $provider->is_published = false;',
        'app/Profile/PublishProvider.php: $locked->is_published = false;',
        'app/Profile/PublishProvider.php: $locked->is_published = true;',
        "database/factories/ProviderFactory.php: 'is_published' => false,",
        "database/factories/ProviderFactory.php: 'is_published' => true,",
        "database/seeders/ProviderSeeder.php: 'is_published' => \$data['is_published'],",
        "database/seeders/ProviderSeeder.php: 'is_published' => false,",
        "database/seeders/ProviderSeeder.php: 'is_published' => true,",
        "database/seeders/ProviderSeeder.php: 'is_published' => true,",
        "database/seeders/ProviderSeeder.php: 'is_published' => true,",
    ]);

    // Each write goes with its date.
    $publish = (string) file_get_contents(app_path('Profile/PublishProvider.php'));
    expect($publish)
        ->toMatch('/\$locked->is_published = true;\s*\$locked->published_at = now\(\);\s*\$locked->save\(\);/')
        ->toMatch('/\$locked->is_published = false;\s*\$locked->published_at = null;\s*\$locked->save\(\);/')
        ->toMatch('/if \(\$locked->is_published\) \{\s*return;\s*\}/');
    expect((string) file_get_contents(database_path('factories/ProviderFactory.php')))
        ->toMatch("/'is_published' => false,\\s*'published_at' => null,/")
        ->toMatch("/'is_published' => true,\\s*(\\/\\/[^\\n]*\\s*)?'published_at' => fake\\(\\)->dateTimeBetween\\(/");
    expect((string) file_get_contents(database_path('seeders/ProviderSeeder.php')))
        ->toMatch("/'is_published' => \\\$data\\['is_published'\\],\\s*(\\/\\/[^\\n]*\\s*)*'published_at' => \\\$data\\['is_published'\\]/");
});
