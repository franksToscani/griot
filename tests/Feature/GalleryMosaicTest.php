<?php

use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Support\MediaDisk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

// The mosaic of the provider's work on the public page, PR D (issue #143, docs/gallery.md): the
// data of the page, the mosaic's small rules run with Node, its source and its texts.

beforeEach(function () {
    Storage::fake(MediaDisk::NAME);
});

function gmoSource(string $path = 'js/components/provider-mosaic.tsx'): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * The provider props of the public page.
 *
 * @return array<string, mixed>
 */
function gmoPageProps(Provider $provider): array
{
    $props = [];

    test()->get(route('providers.show', $provider->slug))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $page->component('providers/show');
            $props = $page->toArray()['props']['provider'];
        });

    return $props;
}

/**
 * Run resources/js/lib/gallery.ts with Node and give back what the calls printed.
 *
 * @return array<string, mixed>
 */
function gmoRun(string $calls): array
{
    $script = sys_get_temp_dir().'/griot-mosaic-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path('js/lib/gallery.ts');
    file_put_contents($script, "import * as g from '{$helper}';\nconsole.log(JSON.stringify({$calls}));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------------------------
// The data
// ---------------------------------------------------------------------------------------------

test('the public page gets the photos in their order, with exactly the fields of the mosaic', function () {
    $provider = Provider::factory()->published()->create();
    $second = ProviderPhoto::factory()->for($provider)->create(['position' => 2, 'width' => 900, 'height' => 600, 'alt_text' => null]);
    $first = ProviderPhoto::factory()->for($provider)->create(['position' => 1, 'alt_text' => 'Box braids lunghe']);
    $portrait = ProviderPhoto::factory()->for($provider)->create(['position' => 3, 'width' => 1200, 'height' => 1600]);
    ProviderPhoto::factory()->create(['position' => 1]);

    $photos = gmoPageProps($provider)['photos'];
    $url = fn (ProviderPhoto $photo, int $side) => MediaDisk::get()->url("gallery/{$photo->name}-{$side}.webp");

    expect(array_column($photos, 'id'))->toBe([$first->id, $second->id, $portrait->id]);
    expect($photos[0])->toBe([
        'id' => $first->id,
        'width' => 1600,
        'height' => 1200,
        'alt_text' => 'Box braids lunghe',
        'variants' => [
            ['width' => 400, 'url' => $url($first, 400)],
            ['width' => 800, 'url' => $url($first, 800)],
            ['width' => 1600, 'url' => $url($first, 1600)],
        ],
    ]);
    // 900 px: only the sizes that exist, 400 and 800, never a 1600.
    expect($photos[1]['variants'])->toBe([
        ['width' => 400, 'url' => $url($second, 400)],
        ['width' => 800, 'url' => $url($second, 800)],
    ])->and($photos[1]['alt_text'])->toBeNull();
    // A portrait photo: the real width of each size, not its longest side.
    expect(array_column($photos[2]['variants'], 'width'))->toBe([300, 600, 1200]);

    $json = (string) json_encode($photos);
    expect($json)->not->toContain('"name"')->not->toContain('consent')->not->toContain('"position"')->not->toContain('"path');
    foreach ($photos as $photo) {
        expect(array_keys($photo))->toBe(['id', 'width', 'height', 'alt_text', 'variants']);
    }
});

test('without photos the list is empty', function () {
    expect(gmoPageProps(Provider::factory()->published()->create())['photos'])->toBe([]);
});

test('the photos are loaded with the provider: one query, however many there are', function () {
    $count = function (Provider $provider): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        gmoPageProps($provider);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $none = Provider::factory()->published()->create();
    $one = Provider::factory()->published()->create();
    ProviderPhoto::factory()->for($one)->create();
    $nine = Provider::factory()->published()->create();
    ProviderPhoto::factory()->count(9)->for($nine)->sequence(fn ($sequence) => ['position' => $sequence->index + 1])->create();

    expect($count($nine))->toBe($count($none))->toBe($count($one));
    expect(Provider::query()->withPublicProfile()->getEagerLoads())->toHaveKey('photos');
});

test('an unpublished provider with photos is still not found, and shows nothing of them', function () {
    $provider = Provider::factory()->create(['is_published' => false]);
    $photo = ProviderPhoto::factory()->for($provider)->create();

    $this->get(route('providers.show', $provider->slug))
        ->assertNotFound()
        ->assertDontSee($photo->name)
        ->assertDontSee('gallery/');
});

test('the list, the area and the edit page keep their own data', function () {
    $provider = Provider::factory()->published()->create();
    ProviderPhoto::factory()->for($provider)->create();

    $this->get(route('providers.index'))
        ->assertOk()
        ->assertDontSee('gallery/');

    $this->actingAs($provider->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('provider', 5));

    // The edit page: the card's own fields (PR C), not the public ones.
    $this->actingAs($provider->user)
        ->get(route('provider.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('provider.photos.0', fn (Assert $photo) => $photo->hasAll(['id', 'url', 'width', 'height', 'alt_text', 'position']))
            ->missing('provider.photos.0.variants'));
});

// ---------------------------------------------------------------------------------------------
// The small rules, run with Node
// ---------------------------------------------------------------------------------------------

test('how many photos the mosaic shows, from 768 px up and below', function () {
    expect(gmoRun('[0, 1, 2, 3, 4, 5, 9].map((count) => g.mosaicLayout(count))'))->toBe([
        ['wide' => 0, 'narrow' => 0],
        ['wide' => 1, 'narrow' => 1],
        ['wide' => 2, 'narrow' => 2],
        ['wide' => 3, 'narrow' => 3],
        ['wide' => 4, 'narrow' => 3],
        ['wide' => 5, 'narrow' => 3],
        ['wide' => 5, 'narrow' => 3],
    ]);
});

test('where each photo goes, what width it takes, and which only from 768 px up', function () {
    $tiles = gmoRun('{ none: g.mosaicTiles(0), one: g.mosaicTiles(1), two: g.mosaicTiles(2), three: g.mosaicTiles(3), four: g.mosaicTiles(4), nine: g.mosaicTiles(9) }');
    $large = ['span' => 'large', 'sizes' => '(min-width: 768px) 50vw, 100vw', 'wideOnly' => false];
    $quarter = fn (bool $wideOnly) => ['span' => 'quarter', 'sizes' => '(min-width: 768px) 25vw, 50vw', 'wideOnly' => $wideOnly];

    expect($tiles['none'])->toBe([])
        ->and($tiles['one'])->toBe([['span' => 'alone', 'sizes' => '100vw', 'wideOnly' => false]])
        ->and($tiles['two'])->toBe([$large, ['span' => 'half', 'sizes' => '50vw', 'wideOnly' => false]])
        ->and($tiles['three'])->toBe([$large, ['span' => 'row', 'sizes' => '50vw', 'wideOnly' => false], ['span' => 'row', 'sizes' => '50vw', 'wideOnly' => false]])
        ->and($tiles['four'])->toBe([$large, ['span' => 'row', 'sizes' => '50vw', 'wideOnly' => false], $quarter(false), $quarter(true)])
        // Nine photos: five tiles; on a phone the large one and two small ones.
        ->and($tiles['nine'])->toBe([$large, $quarter(false), $quarter(false), $quarter(true), $quarter(true)]);
});

test('the srcset and the src for a browser without srcset', function () {
    $result = gmoRun("{
        all: g.srcSet([{ width: 1600, url: '/m/a-1600.webp' }, { width: 400, url: '/m/a-400.webp' }, { width: 800, url: '/m/a-800.webp' }]),
        one: g.srcSet([{ width: 300, url: '/m/b-400.webp' }]),
        special: g.srcSet([{ width: 400, url: 'https://cdn.example/m/a b,c-400.webp?x=1&y=%41' }]),
        none: g.srcSet([]),
        src: g.fallbackSrc([{ width: 1600, url: '/m/a-1600.webp' }, { width: 400, url: '/m/a-400.webp' }, { width: 800, url: '/m/a-800.webp' }]),
        srcSmall: g.fallbackSrc([{ width: 400, url: '/m/c-400.webp' }]),
        srcOnlyWide: g.fallbackSrc([{ width: 1600, url: '/m/d-1600.webp' }, { width: 900, url: '/m/d-900.webp' }]),
        srcNone: g.fallbackSrc([]),
    }");

    expect($result)->toBe([
        'all' => '/m/a-400.webp 400w, /m/a-800.webp 800w, /m/a-1600.webp 1600w',
        'one' => '/m/b-400.webp 300w',
        'special' => 'https://cdn.example/m/a%20b%2Cc-400.webp?x=1&y=%41 400w',
        'none' => '',
        'src' => '/m/a-800.webp',
        'srcSmall' => '/m/c-400.webp',
        'srcOnlyWide' => '/m/d-900.webp',
        'srcNone' => '',
    ]);
});

test('the alternative text: the provider\'s own, or the public one with the provider\'s name; the card keeps its own', function () {
    $result = gmoRun("(() => {
        const t = (key, r = {}) => Object.entries(r).reduce((text, [k, v]) => text.split(':' + k).join(String(v)), key + '|:provider|:number/:total');
        return {
            own: g.photoAlt(t, '  Box braids lunghe ', 2, 6, 'providers.show.photo_alt', { provider: 'Trecce Sahel' }),
            none: g.photoAlt(t, null, 2, 6, 'providers.show.photo_alt', { provider: 'Trecce Sahel' }),
            blank: g.photoAlt(t, ' ', 1, 1, 'providers.show.photo_alt', { provider: 'Trecce Sahel' }),
            card: g.photoAlt(t, null, 2, 6),
        };
    })()");

    expect($result)->toBe([
        'own' => 'Box braids lunghe',
        'none' => 'providers.show.photo_alt|Trecce Sahel|2/6',
        'blank' => 'providers.show.photo_alt|Trecce Sahel|1/1',
        // The edit page's card, unchanged.
        'card' => 'provider.gallery.fallback_alt|:provider|2/6',
    ]);
});

// ---------------------------------------------------------------------------------------------
// The source
// ---------------------------------------------------------------------------------------------

test('the mosaic is mounted only with photos, after the way back and before the header', function () {
    expect(gmoSource('js/pages/providers/show.tsx'))
        ->toContain("import { ProviderMosaic } from '@/components/provider-mosaic';")
        ->toMatch('/<\/Link>\s*\{\/\*[^*]*\*\/\}\s*\{provider\.photos\.length > 0 && \(\s*<ProviderMosaic\s+photos=\{provider\.photos\}\s+providerName=\{provider\.name\}\s*\/>\s*\)\}\s*\{\/\*[\s\S]*?\*\/\}\s*<header/');
});

test('a group with a translated name, and no heading', function () {
    $source = gmoSource();

    expect($source)
        ->toContain('<div role="group" aria-labelledby={LABEL_ID}>')
        ->toMatch("/<p id=\\{LABEL_ID\\} className=\"sr-only\">\\s*\\{t\\('providers\\.show\\.photos'\\)\\}\\s*<\\/p>/")
        ->not->toMatch('/<h[1-6]\b/')
        ->not->toContain('aria-label=')
        ->not->toContain('aria-label ');
});

test('the large photo loads at once and first, the others lazily; srcset, sizes, width and height on each', function () {
    $source = gmoSource();
    preg_match('/<img[\s\S]*?\/>/', $source, $img);

    expect($img[0])
        ->toContain('src={fallbackSrc(photo.variants)}')
        ->toContain('srcSet={srcSet(photo.variants)}')
        ->toContain('sizes={tile.sizes}')
        ->toContain('width={photo.width}')
        ->toContain('height={photo.height}')
        ->toMatch("/\\{\\.\\.\\.\\(first\\s*\\?\\s*\\{\\s*loading: 'eager',\\s*fetchPriority: 'high',?\\s*\\}\\s*:\\s*\\{ loading: 'lazy', decoding: 'async' \\}\\)\\}/");
    expect($source)->toContain('const first = index === 0;');
    // fetchPriority only once, on the first; no loading or decoding written elsewhere.
    expect(substr_count($source, 'fetchPriority'))->toBe(1)
        ->and(substr_count($source, 'loading:'))->toBe(2)
        ->and(substr_count($source, '<img'))->toBe(1);
    // The space of each photo is kept: an aspect ratio on phones, a 2:1 box from 768 px up.
    expect($img[0])->toContain('className="size-full rounded-md bg-muted object-cover forced-colors:border"');
    expect($source)
        ->toContain('md:aspect-[2/1] md:grid-cols-4 md:grid-rows-2')
        ->toContain("alone: 'col-span-2 aspect-[4/3]")
        ->toContain("large: 'col-span-2 aspect-[4/3]")
        ->toContain("tile.wideOnly && 'max-md:hidden'");
});

test('each photo shown is a button that opens the zoom; nothing else to press, nothing that moves, nothing sticky', function () {
    $source = gmoSource();

    // A real button (PR E), one per tile, named by the photo's alternative text and then by
    // what it does, in sr-only text from t().
    expect(substr_count($source, '<button'))->toBe(1);
    expect($source)
        ->toMatch('/<button\s+key=\{photo\.id\}\s+type="button"\s+aria-haspopup="dialog"\s+onClick=\{\(event\) => open\(event, index\)\}/')
        ->toMatch("/alt=\{photoAlt\([\s\S]*?\/>\s*<span className=\"sr-only\">\s*\{' '\}\s*\{t\('providers\.show\.open_photo', \{\s*number: index \+ 1,\s*total: photos\.length,?\s*\}\)\}\s*<\/span>\s*<\/button>/")
        // A visible focus, an outline in forced colours, and no hidden tile in the Tab order.
        ->toMatch("/'overflow-hidden rounded-md outline-none [^']*focus-visible:ring-\[3px\][^']*focus-visible:outline-hidden forced-colors:border'/")
        ->toContain("tile.wideOnly && 'max-md:hidden'");

    // Still no link, no tab index of its own, no movement.
    expect($source)
        ->not->toMatch('/<(a|Link)\b/')
        ->not->toContain('href')
        ->not->toContain('tabIndex')
        ->not->toContain('sticky')
        ->not->toContain('fixed')
        ->not->toMatch('/\b(animate|transition|hover:|scale-|duration-)/')
        ->not->toMatch('/\bdark:/');
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts of the mosaic exist in Italian, French and English, with the same placeholders', function () {
    $json = fn (string $locale) => json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        sort($found[0]);

        return $found[0];
    };

    preg_match_all("/'(providers\\.show\\.photo[a-z_]*)'/", gmoSource(), $used);
    expect(array_values(array_unique($used[1])))->toBe(['providers.show.photos', 'providers.show.photo_alt']);

    foreach ($used[1] as $key) {
        foreach (['it', 'fr', 'en'] as $locale) {
            expect($json($locale))->toHaveKey($key)
                ->and($placeholders($json($locale)[$key]))->toBe($placeholders($json('it')[$key]), "{$locale} {$key}");
        }
        expect(preg_match('/ [:?!;](?![a-z])/', $json('fr')[$key]))->toBe(0, "fr {$key}");
    }

    expect(__('providers.show.photo_alt', ['provider' => 'Trecce Sahel', 'number' => 2, 'total' => 6], 'it'))->toBe('Lavoro di Trecce Sahel, foto 2 di 6')
        ->and(__('providers.show.photo_alt', ['provider' => 'Trecce Sahel', 'number' => 2, 'total' => 6], 'fr'))->toBe('Travail de Trecce Sahel, photo 2 sur 6')
        ->and(__('providers.show.photo_alt', ['provider' => 'Trecce Sahel', 'number' => 2, 'total' => 6], 'en'))->toBe('Work by Trecce Sahel, photo 2 of 6')
        ->and(__('providers.show.photos', [], 'it'))->toBe('Foto del lavoro');
});
