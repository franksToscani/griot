<?php

use App\Models\Category;
use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Models\Service;
use App\Support\MediaDisk;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

// "Professionisti in evidenza" on the home page (issue #144, step 3, docs/home.md): who is
// featured, in which order, the data of the card, the queries, and the source of the card.

/**
 * A provider that can be featured: published at the given moment, with an active service and
 * the given number of photos.
 */
function hfEligible(string $publishedAt = '2026-11-01 10:00:00', int $photos = 1, string $name = 'Trecce Sahel'): Provider
{
    $provider = Provider::factory()->published()->create(['name' => $name, 'city' => 'Bologna', 'published_at' => $publishedAt]);
    Service::factory()->for($provider)->create(['is_active' => true]);

    for ($position = 1; $position <= $photos; $position++) {
        ProviderPhoto::factory()->for($provider)->create(['position' => $position]);
    }

    return $provider;
}

/**
 * The prop "featured" of the home page.
 *
 * @return list<array<string, mixed>>
 */
function hfFeatured(?string $locale = null): array
{
    $featured = [];
    $test = $locale === null ? test() : test()->withCookie('locale', $locale);

    $test->get('/')->assertOk()->assertInertia(function (Assert $page) use (&$featured) {
        $featured = $page->toArray()['props']['featured'];
    });

    return $featured;
}

function hfSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

// ---------------------------------------------------------------------------------------------
// Who is featured
// ---------------------------------------------------------------------------------------------

test('a published provider with a photo and an active service is featured, with only what its card shows', function () {
    $category = Category::factory()->create(['slug' => 'treccine', 'name' => 'Treccine']);
    $provider = hfEligible();
    $provider->services()->first()->update(['category_id' => $category->id]);
    $photo = $provider->photos()->first();

    $featured = hfFeatured('en');

    expect($featured)->toBe([[
        'id' => $provider->id,
        'slug' => $provider->slug,
        'name' => 'Trecce Sahel',
        'city' => 'Bologna',
        // Translated, as in the list.
        'categories' => [['id' => $category->id, 'slug' => 'treccine', 'name' => 'Braids']],
        'cover' => [
            'width' => 1600,
            'height' => 1200,
            'variants' => [
                ['width' => 400, 'url' => MediaDisk::get()->url("gallery/{$photo->name}-400.webp")],
                ['width' => 800, 'url' => MediaDisk::get()->url("gallery/{$photo->name}-800.webp")],
                ['width' => 1600, 'url' => MediaDisk::get()->url("gallery/{$photo->name}-1600.webp")],
            ],
        ],
    ]]);

    // Nothing else: no date, no consent, no contact, no bio, no avatar, no text of the photo.
    $json = (string) json_encode($featured);
    foreach (['published_at', 'consent', 'whatsapp', 'instagram', 'bio', 'avatar', 'alt_text', 'position', 'user_id', 'path', 'cover_photo_id', 'is_published'] as $field) {
        expect($json)->not->toContain($field);
    }
    expect($json)->not->toContain('"name":"'.$photo->name.'"');
});

test('not featured: unpublished, without photos, without active services', function (Closure $make) {
    $make();

    expect(hfFeatured())->toBe([]);
})->with([
    'not published' => [function () {
        $provider = Provider::factory()->create();
        Service::factory()->for($provider)->create(['is_active' => true]);
        ProviderPhoto::factory()->for($provider)->create();
    }],
    'no photo' => [function () {
        Service::factory()->for(Provider::factory()->published())->create(['is_active' => true]);
    }],
    'no service' => [function () {
        ProviderPhoto::factory()->for(Provider::factory()->published())->create();
    }],
    'only inactive services' => [function () {
        $provider = Provider::factory()->published()->create();
        Service::factory()->for($provider)->count(2)->create(['is_active' => false]);
        ProviderPhoto::factory()->for($provider)->create();
    }],
]);

test('the categories of a card come from the active services only', function () {
    $provider = hfEligible();
    $active = $provider->services()->first()->category;
    Service::factory()->for($provider)->create(['is_active' => false]);

    expect(array_column(hfFeatured()[0]['categories'], 'id'))->toBe([$active->id]);
});

test('the most recently published first; on the same moment, the newest id first', function () {
    $old = hfEligible('2026-09-01 10:00:00', name: 'Vecchio');
    $new = hfEligible('2026-11-01 10:00:00', name: 'Nuovo');
    $middle = hfEligible('2026-10-01 10:00:00', name: 'Medio');
    $sameA = hfEligible('2026-08-01 10:00:00', name: 'Pari A');
    $sameB = hfEligible('2026-08-01 10:00:00', name: 'Pari B');

    expect(array_column(hfFeatured(), 'id'))->toBe([$new->id, $middle->id, $old->id, $sameB->id, $sameA->id]);
});

test('at most 8: with 9 who could be, the oldest stays out', function () {
    $start = Carbon::parse('2026-10-01 10:00:00');
    $providers = collect(range(0, 8))->map(fn (int $day) => hfEligible($start->addDays($day)->toDateTimeString(), name: "Provider {$day}"));

    expect(array_column(hfFeatured(), 'id'))->toBe($providers->reverse()->take(8)->pluck('id')->values()->all());
});

test('the cover is the first photo by position, with only the sizes that exist', function () {
    $provider = Provider::factory()->published()->create(['published_at' => '2026-11-01 10:00:00']);
    Service::factory()->for($provider)->create(['is_active' => true]);
    ProviderPhoto::factory()->for($provider)->create(['position' => 2]);
    // Created after, but first in the order; 900 px wide: 400 and 800 only.
    $first = ProviderPhoto::factory()->for($provider)->create(['position' => 1, 'width' => 900, 'height' => 600]);

    expect(hfFeatured()[0]['cover'])->toBe([
        'width' => 900,
        'height' => 600,
        'variants' => [
            ['width' => 400, 'url' => MediaDisk::get()->url("gallery/{$first->name}-400.webp")],
            ['width' => 800, 'url' => MediaDisk::get()->url("gallery/{$first->name}-800.webp")],
        ],
    ]);
});

test('with nobody to feature, the prop is empty', function () {
    hfEligible()->forceFill(['is_published' => false, 'published_at' => null])->save();

    expect(hfFeatured())->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// The queries
// ---------------------------------------------------------------------------------------------

test('the same queries with 1 or 8 providers, and only the covers are loaded, never every photo', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('/')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    hfEligible('2026-11-01 10:00:00', photos: 3);
    $one = $count();

    foreach (range(2, 8) as $day) {
        hfEligible("2026-10-0{$day} 10:00:00", photos: 9, name: "Provider {$day}");
    }

    // Count the photos the page reads: one per featured provider, not 3 + 7 × 9.
    $photos = 0;
    Event::listen('eloquent.retrieved: '.ProviderPhoto::class, function () use (&$photos) {
        $photos++;
    });

    expect($count())->toBe($one)
        ->and($photos)->toBe(8)
        ->and(hfFeatured())->toHaveCount(8);
});

// ---------------------------------------------------------------------------------------------
// The source
// ---------------------------------------------------------------------------------------------

test('the section sits between the categories and the steps, in the shared row, only with providers', function () {
    $page = hfSource('pages/home.tsx');

    expect($page)
        ->toContain("const FEATURED_TITLE_ID = 'home-featured-title';")
        ->toMatch("/\\{featured\\.length > 0 && \\(\\s*<ScrollRow\\s+titleId=\\{FEATURED_TITLE_ID\\}\\s+title=\\{t\\('home\\.featured\\.title'\\)\\}\\s+items=\\{featured\\}\\s+renderItem=\\{\\(provider\\) => \\(\\s*<FeaturedProviderCard provider=\\{provider\\} \\/>\\s*\\)\\}\\s*\\/>\\s*\\)\\}/")
        ->toMatch('/<CategoriesSection categories=\{categories\} \/>[\s\S]*\{featured\.length > 0[\s\S]*\{t\(\'home\.steps\.title\'\)\}/');
});

test('the card: a link to the page with its touch target, the name as h3 with the city, the categories', function () {
    $card = hfSource('components/home/featured-provider-card.tsx');

    expect($card)
        ->toMatch("/<Link\\s+href=\\{show\\(provider\\.slug\\)\\}\\s+className=\\{cn\\(\\s*'flex w-64 flex-col [^']*border[^']*outline-none[^']*focus-visible:ring-\\[3px\\][^']*focus-visible:outline-hidden',\\s*touchTarget,\\s*CARD_MOTION\\.card,?\\s*\\)\\}\\s*>/")
        // Since #144 step 10 it rises under the mouse or the focus, with the classes shared with the
        // cards of the categories (HomeMotionTest).
        ->toContain("import { CARD_MOTION } from '@/lib/motion';")
        ->toContain("import { show } from '@/routes/providers';")
        // The heading inside the link says the name and the city; the city shown is not read twice.
        ->toMatch("/<h3 className=\"font-medium break-words\">\\s*\\{provider\\.name\\}\\s*<span className=\"sr-only\">\\s*\\{t\\('home\\.featured\\.in_city', \\{ city: provider\\.city \\}\\)\\}\\s*<\\/span>\\s*<\\/h3>/")
        ->toMatch('/<p aria-hidden="true" className="text-sm text-muted-foreground">\s*\{provider\.city\}\s*<\/p>/')
        ->toMatch('/<Badge variant="secondary">\s*\{category\.name\}\s*<\/Badge>/')
        // Always a photo: no avatar.
        ->not->toMatch('/ProviderAvatar|avatar_url/');
});

test('the cover is decorative and loads lazily, with srcset, sizes and its real size', function () {
    preg_match_all('/<img[\s\S]*?\/>/', hfSource('components/home/featured-provider-card.tsx'), $images);

    expect($images[0])->toHaveCount(1);
    expect($images[0][0])
        ->toContain('alt=""')
        ->toContain('loading="lazy"')
        ->toContain('decoding="async"')
        ->toContain('src={fallbackSrc(provider.cover.variants)}')
        ->toContain('srcSet={srcSet(provider.cover.variants)}')
        ->toContain('sizes="16rem"')
        ->toContain('width={provider.cover.width}')
        ->toContain('height={provider.cover.height}')
        ->toContain('aspect-[4/3]')
        ->toContain('object-cover')
        ->not->toContain('fetchPriority');
});

test('nothing written by hand, nothing that moves or sticks, in the card', function () {
    $card = hfSource('components/home/featured-provider-card.tsx');

    expect($card)
        ->not->toMatch('/aria-label(?!ledby)/')
        ->not->toMatch('/scroll-smooth|sticky|fixed|animate-|transition|duration-|scale-|translate-/')
        ->not->toMatch('/>\s*[A-Za-zÀ-ÿ][^<{]*</');
});

test('the texts of the section', function () {
    expect(__('home.featured.title', [], 'it'))->toBe('Professionisti in evidenza')
        ->and(__('home.featured.title', [], 'fr'))->toBe('Professionnels à la une')
        ->and(__('home.featured.title', [], 'en'))->toBe('Featured professionals')
        ->and('Trecce Sahel'.__('home.featured.in_city', ['city' => 'Bologna'], 'it'))->toBe('Trecce Sahel, a Bologna')
        ->and('Trecce Sahel'.__('home.featured.in_city', ['city' => 'Bologna'], 'fr'))->toBe('Trecce Sahel, à Bologna')
        ->and('Trecce Sahel'.__('home.featured.in_city', ['city' => 'Bologna'], 'en'))->toBe('Trecce Sahel, in Bologna');
});
