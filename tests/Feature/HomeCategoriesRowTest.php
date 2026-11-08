<?php

use App\Models\Category;
use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

// The row "Categorie" of the home page (docs/home.md): a card per category, with its photo or a
// coloured tile, the name under it, one link to the filtered list. The rules of lib/home-categories.ts
// run with Node; the border of the tiles is measured in AccessibilityContrastTest.

function hcrSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

/**
 * Run lib/home-categories.ts with Node and give back what the calls printed.
 *
 * @return array<mixed>
 */
function hcrRun(string $calls): array
{
    $script = sys_get_temp_dir().'/griot-categories-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path('js/lib/home-categories.ts');
    file_put_contents($script, "import * as h from '{$helper}';
const cat = (id, slug) => ({ id, slug, name: 'N' + id });
const photo = { width: 1920, height: 1280, variants: [{ width: 1920, url: '/p-1920.webp' }, { width: 640, url: '/p-640.webp' }, { width: 1280, url: '/p-1280.webp' }] };
const photos = { treccine: photo, barbiere: photo, vuota: { width: 1, height: 1, variants: [] } };
console.log(JSON.stringify({$calls}));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The card of a category, between <Link and </Link>.
 */
function hcrCard(): string
{
    expect(preg_match('/<Link[\s\S]*<\/Link>/', hcrSource('components/home/category-card.tsx'), $match))->toBe(1);

    return $match[0];
}

/**
 * A category with something to book: an active service of a published provider.
 */
function hcrOffered(string $slug): Category
{
    $category = Category::factory()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
    Service::factory()->for(Provider::factory()->published())->for($category)->create(['is_active' => true]);

    return $category;
}

// ---------------------------------------------------------------------------------------------
// The cards
// ---------------------------------------------------------------------------------------------

test('one card per category, in the order of the prop: a photo, or a tile in turn brown, amber, light green, white', function () {
    $cards = hcrRun("h.categoryCards([cat(5, 'couture'), cat(2, 'treccine'), cat(9, 'cucina'), cat(1, 'vuota'), cat(7, 'barbiere'), cat(3, 'altro'), cat(4, 'sartoria'), cat(8, 'constructor')], photos)");

    expect(array_column($cards, 'id'))->toBe([5, 2, 9, 1, 7, 3, 4, 8])
        ->and(array_column($cards, 'slug'))->toBe(['couture', 'treccine', 'cucina', 'vuota', 'barbiere', 'altro', 'sartoria', 'constructor'])
        ->and(array_column($cards, 'name'))->toBe(['N5', 'N2', 'N9', 'N1', 'N7', 'N3', 'N4', 'N8'])
        // The tones go round over the tiles only; a photo has none. A photo without sizes, or a
        // name of the prototype of an object, is no photo.
        ->and(array_column($cards, 'tone'))->toBe(['bg-primary', null, 'bg-highlight', 'bg-info', null, 'bg-card', 'bg-primary', 'bg-highlight'])
        ->and(array_map(fn (array $card) => $card['image'] === null, $cards))->toBe([true, false, true, true, false, true, true, true]);
    expect(hcrRun('h.categoryCards([], photos)'))->toBe([])
        ->and(array_column(hcrRun("h.categoryCards([cat(1, 'treccine')])"), 'tone'))->toBe(['bg-primary']);
});

test('the photo of a card: the 640 size as src, every size in srcset, the real size of the photo', function () {
    expect(hcrRun("h.categoryCards([cat(2, 'treccine')], photos)[0].image"))->toBe([
        'src' => '/p-640.webp',
        'srcSet' => '/p-640.webp 640w, /p-1280.webp 1280w, /p-1920.webp 1920w',
        'width' => 1920,
        'height' => 1280,
    ]);
    expect(hcrSource('lib/home-categories.ts'))->toContain("export const CATEGORY_PHOTO_SIZES = '9rem';");
    // The page gives the cards the photos of the carousel, in the row of the categories.
    expect(hcrSource('pages/home.tsx'))
        ->toMatch("/<ScrollRow\\s+titleId=\\{CATEGORIES_TITLE_ID\\}\\s+title=\\{t\\('home\\.categories\\.title'\\)\\}\\s+items=\\{categoryCards\\(categories, HOME_PHOTOS\\)\\}\\s+renderItem=\\{\\(card\\) => <CategoryCard card=\\{card\\} \\/>\\}/");
});

test('the whole card is one link to the list filtered on its category, made by Wayfinder', function () {
    $card = hcrCard();

    expect($card)
        ->toMatch('/^<Link\s+href=\{index\(\{ query: \{ category: card\.slug \} \}\)\}/')
        ->and(substr_count($card, '<Link'))->toBe(1)
        ->and($card)->not->toMatch('/<a\b|href="/');
    expect(hcrSource('components/home/category-card.tsx'))->toContain("import { index } from '@/routes/providers';");
    expect(route('providers.index', ['category' => 'treccine'], false))->toBe('/providers?category=treccine');
});

test('the name under the photo or the tile, never on it: the image first, then the name, nothing absolute', function () {
    expect(hcrCard())
        ->toMatch('/\{card\.image !== null \? \([\s\S]*<img[^>]*\/>\s*<\/span>\s*\) : \(\s*<span[^>]*\/>\s*\)\}\s*<span className="[^"]*">\s*\{card\.name\}\s*<\/span>\s*<\/Link>$/')
        ->and(substr_count(hcrCard(), '{card.name}'))->toBe(1)
        ->and(hcrCard())->not->toMatch('/\b(absolute|inset-|bg-gradient|from-black|bg-black)/');
});

test('the photo is decorative, lazy, with its width and height: nothing jumps', function () {
    expect(preg_match_all('/<img[\s\S]*?\/>/', hcrCard(), $images))->toBe(1);

    expect($images[0][0])
        ->toContain('src={card.image.src}')
        ->toContain('srcSet={card.image.srcSet}')
        ->toContain('sizes={CATEGORY_PHOTO_SIZES}')
        ->toContain('width={card.image.width}')
        ->toContain('height={card.image.height}')
        ->toContain('alt=""')
        ->toContain('loading="lazy"')
        ->toContain('decoding="async"')
        // Since #144 step 10 it grows a little under the mouse or the focus (HomeMotionTest).
        ->toMatch("/className=\\{cn\\(\\s*'h-24 w-full rounded-md [^']*object-cover',\\s*CARD_MOTION\\.photo,?\\s*\\)\\}/")
        ->not->toContain('eager')
        ->not->toContain('fetchPriority');
});

test('a category without a photo: a tile filled with its token, with the measured border, decorative', function () {
    expect(hcrCard())->toMatch("/<span\\s+aria-hidden=\"true\"\\s+className=\\{cn\\(\\s*'block h-24 w-full rounded-md',\\s*CATEGORY_TILE_EDGE,\\s*card\\.tone,?\\s*\\)\\}\\s*\\/>/");
    expect(hcrSource('lib/home-categories.ts'))
        ->toMatch("/export const CATEGORY_TONES = \\[\\s*'bg-primary',\\s*'bg-highlight',\\s*'bg-info',\\s*'bg-card',?\\s*\\] as const;/")
        ->toContain("export const CATEGORY_TILE_EDGE = 'border border-input';");
});

test('no colour written by hand, never red: only tokens of the theme', function () {
    $sources = hcrSource('components/home/category-card.tsx').hcrSource('lib/home-categories.ts');

    preg_match_all('/(?<![\w-])(?:hover:|focus-visible:|group-hover:)?(?:bg|text|border|ring)-([a-z]+(?:-[a-z]+)*)(?:\/\d+)?(?![\w-])/', $sources, $colours);
    expect(array_values(array_diff(array_unique($colours[1]), ['primary', 'highlight', 'info', 'card', 'input', 'muted', 'ring'])))->toBe([])
        ->and($sources)->not->toMatch('/#[0-9a-f]{3,8}\b|oklch\(|rgba?\(|hsla?\(|\[(?:#|rgb|oklch)/i')
        ->and($sources)->not->toMatch('/\b(bg|text|border|ring)-(decor|destructive|red|rose|orange|[a-z]+-\d{2,3})\b/');
});

test('a phone can use it: a card 9rem wide, more than 44px high, a visible focus not cut by the row', function () {
    expect(hcrCard())->toMatch("/^<Link\\s+href=[^>]*className=\\{cn\\(\\s*'group flex w-36 flex-col gap-2 rounded-md outline-none focus-visible:ring-\\[3px\\] focus-visible:ring-ring\\/50 focus-visible:outline-hidden',\\s*touchTarget,\\s*CARD_MOTION\\.card,?\\s*\\)\\}/");
    // The row keeps 8px around the cards: the 2px a card rises under the focus plus its 3px ring
    // (since #144 step 10). It scrolls, the page not.
    expect(hcrSource('components/home/scroll-row.tsx'))
        ->toContain('className="flex snap-x gap-3 p-2 pb-4"')
        ->toContain('className="reveal-item shrink-0 snap-start"');
});

// ---------------------------------------------------------------------------------------------
// The row
// ---------------------------------------------------------------------------------------------

test('a region named by the visible title, focusable, snapping; only the categories\' own section', function () {
    expect(hcrSource('components/home/scroll-row.tsx'))
        ->toMatch('/<h2 id=\{titleId\}/')
        ->toMatch('/<div\s+role="region"\s+aria-labelledby=\{titleId\}\s+tabIndex=\{0\}\s+className="overflow-x-auto [^"]*outline-none[^"]*focus-visible:outline-hidden"/')
        ->toContain('snap-x');
    expect(hcrSource('pages/home.tsx'))->toContain("const CATEGORIES_TITLE_ID = 'home-categories-title';");
});

test('no heading in the cards, no aria-label, nothing that scrolls smoothly, moves or sticks', function () {
    $sources = hcrSource('components/home/category-card.tsx').hcrSource('lib/home-categories.ts').hcrSource('components/home/scroll-row.tsx');

    expect(hcrSource('components/home/category-card.tsx'))->not->toMatch('/<h[1-6]\b|role="heading"/');
    expect($sources)
        ->not->toMatch('/aria-label(?!ledby)/')
        ->not->toMatch('/scroll-smooth|scroll-behavior|behavior:\s*[\'"]smooth|scrollIntoView|scrollTo\(/')
        ->not->toMatch('/\b(sticky|fixed)\b/');
    // Since #144 step 10 the cards move, only under motion-safe: (HomeMotionTest).
    expect(withoutMotionSafe($sources))->not->toMatch('/\b(animate-|transition|duration-|translate-)/');
});

test('the home page makes no query more for the cards: 4 without featured providers, 6 with them', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('categories'));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // Categories with a photo and without one.
    foreach (['treccine', 'barbiere', 'couture', 'cucina', 'sartoria'] as $slug) {
        hcrOffered($slug);
    }
    expect($count())->toBe(4);

    ProviderPhoto::factory()->for(Provider::query()->firstOrFail())->create();
    expect($count())->toBe(6);
});

test('no real contact in the row: no email address, no phone number', function () {
    $sources = hcrSource('components/home/category-card.tsx').hcrSource('lib/home-categories.ts').hcrSource('pages/home.tsx');

    expect($sources)
        ->not->toMatch('/[\w.+-]+@[\w-]+\.[\w.]+/')
        ->not->toMatch('/(\+|00)\d[\d\s]{7,}/')
        ->not->toContain('mailto:')
        ->not->toContain('wa.me');
});
