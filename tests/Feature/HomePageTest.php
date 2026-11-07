<?php

use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The home page for the customers (issue #144, step 2, docs/home.md): the route, its data, the
// source of the page and its texts.

/**
 * A category with something to book: an active service of a published provider.
 */
function hpOffered(string $slug, string $name): Category
{
    $category = Category::factory()->create(['slug' => $slug, 'name' => $name]);
    Service::factory()->for(Provider::factory()->published())->for($category)->create(['is_active' => true]);

    return $category;
}

function hpSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

/**
 * The page's own props: the shared ones (HandleInertiaRequests) left out.
 *
 * @param  array<string, mixed>  $props
 * @return list<string>
 */
function hpOwnProps(array $props): array
{
    return array_values(array_diff(array_keys($props), ['errors', 'name', 'auth', 'sidebarOpen', 'locale', 'locales', 'translations', 'contact', 'flash']));
}

// ---------------------------------------------------------------------------------------------
// The route and its data
// ---------------------------------------------------------------------------------------------

test('"/" shows the home page to everyone, without a redirect', function (Closure $as) {
    hpOffered('treccine', 'Treccine');
    $as($this);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->has('categories', 1)
            ->where('categories.0', fn ($category) => array_keys($category->all()) === ['id', 'slug', 'name']));
    expect(route('home'))->toBe(url('/'));
})->with([
    'a guest' => [fn () => null],
    'a user without a provider' => [fn ($test) => $test->actingAs(User::factory()->create())],
    // "Il mio pannello" is in the header: the provider sees the customers' home page.
    'a provider' => [fn ($test) => $test->actingAs(Provider::factory()->published()->create()->user)],
]);

test('three props of its own: the categories, the featured providers and the cities of the search', function () {
    $this->get('/')->assertInertia(function (Assert $page) {
        expect(hpOwnProps($page->toArray()['props']))->toBe(['categories', 'featured', 'cities']);
    });
});

test('only the categories with something to book, with their translated name', function () {
    $offered = hpOffered('treccine', 'Treccine');
    // Only an inactive service.
    $inactive = Category::factory()->create(['slug' => 'ciglia', 'name' => 'Ciglia']);
    Service::factory()->for(Provider::factory()->published())->for($inactive)->create(['is_active' => false]);
    // An active service of a provider not published.
    $hidden = Category::factory()->create(['slug' => 'nails', 'name' => 'Nails']);
    Service::factory()->for(Provider::factory())->for($hidden)->create(['is_active' => true]);
    // No service at all.
    Category::factory()->create(['slug' => 'couture', 'name' => 'Couture']);

    $this->withCookie('locale', 'en')->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('categories', [['id' => $offered->id, 'slug' => 'treccine', 'name' => 'Braids']]));
});

test('sorted on the name shown, which depends on the language', function () {
    hpOffered('treccine', 'Treccine');
    hpOffered('barbiere', 'Barbiere');
    hpOffered('ristorazione', 'Ristorazione');

    $names = function (string $locale): array {
        $names = [];
        test()->withCookie('locale', $locale)->get('/')->assertInertia(function (Assert $page) use (&$names) {
            $names = array_column($page->toArray()['props']['categories'], 'name');
        });

        return $names;
    };

    expect($names('it'))->toBe(['Barbiere', 'Ristorazione', 'Treccine'])
        ->and($names('fr'))->toBe(['Barbier', 'Restauration', 'Tresses'])
        ->and($names('en'))->toBe(['Barber', 'Braids', 'Food']);
});

test('one query for the categories, however many there are', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('/')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    hpOffered('treccine', 'Treccine');
    $one = $count();

    foreach (['barbiere' => 'Barbiere', 'nails' => 'Nails', 'ciglia' => 'Ciglia', 'sopracciglia' => 'Sopracciglia', 'couture' => 'Couture', 'ristorazione' => 'Ristorazione'] as $slug => $name) {
        hpOffered($slug, $name);
    }

    $this->get('/')->assertInertia(fn (Assert $page) => $page->has('categories', 7));
    expect($count())->toBe($one);
});

test('with no category to book, the list is empty', function () {
    Category::factory()->create(['slug' => 'treccine', 'name' => 'Treccine']);

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('categories', []));
});

// ---------------------------------------------------------------------------------------------
// The source of the page
// ---------------------------------------------------------------------------------------------

test('the home page has the public layout, not the one with the side menu', function () {
    expect(hpSource('app.tsx'))->toMatch("/case name === 'privacy':\\s*case name === 'home':\\s*return PublicLayout;/");
});

test('one h1, in the band at the top; an h2 per section and an h3 per step, without a skipped level', function () {
    $intro = hpSource('components/home/home-hero.tsx');
    $page = hpSource('pages/home.tsx');
    // Since #144 step 3 the h2 of the rows that scroll is in the shared ScrollRow, and the h3 of a
    // featured provider in its card, which ScrollRow puts under that h2.
    $row = hpSource('components/home/scroll-row.tsx');
    $card = hpSource('components/home/featured-provider-card.tsx');
    $levels = function (string $source): array {
        preg_match_all('/<h([1-6])\b/', $source, $found);

        return $found[1];
    };

    expect($levels($intro))->toBe(['1'])
        ->and(array_count_values($levels($page)))->toBe(['2' => 1, '3' => 1])
        ->and($levels($row))->toBe(['2'])
        ->and($levels($card))->toBe(['3'])
        // The carousel (#144 step 5) has no heading: the name of a category is a paragraph.
        ->and($levels(hpSource('components/home/home-carousel.tsx')))->toBe([])
        // Nor the search: its fields have labels.
        ->and($levels(hpSource('components/home/home-search.tsx')))->toBe([])
        // The band comes first, then the sections; the h3 are inside the steps' list.
        ->and($page)->toMatch('/<HomeHero[\s\S]*<CategoriesSection[\s\S]*<ScrollRow[\s\S]*<h2[\s\S]*<ol[^>]*>[\s\S]*<h3[\s\S]*<\/ol>/')
        // The row's h2 comes before its items: the cards' h3 are under it.
        ->and($row)->toMatch('/<h2 id=\{titleId\}[\s\S]*<ul[\s\S]*\{renderItem\(item\)\}/');
    expect($intro)->toMatch("/<h1 className=\"text-3xl font-semibold break-words md:text-4xl\">\\s*\\{t\\('home\\.intro\\.title'\\)\\}\\s*<\\/h1>/");
});

test('the steps are an ordered list, the categories a list in a region that scrolls sideways', function () {
    $page = hpSource('pages/home.tsx');
    // The row that scrolls is the shared ScrollRow since #144 step 3: the same rules, in its file.
    $row = hpSource('components/home/scroll-row.tsx');

    expect($page)
        ->toContain("const STEPS = ['choose', 'book', 'confirm'] as const;")
        ->toMatch('/<ol className="grid gap-4 md:grid-cols-3">\s*\{STEPS\.map\(\(step\) => \(\s*<li/')
        ->toMatch('/<h3 className="font-medium">\\s*\\{t\\(`home\\.steps\\.\\$\\{step\\}\\.title`\\)\\}/')
        // The categories go in the shared row, named by their own title.
        ->toMatch("/<ScrollRow\\s+titleId=\\{CATEGORIES_TITLE_ID\\}\\s+title=\\{t\\('home\\.categories\\.title'\\)\\}\\s+items=\\{categories\\}/");

    expect($row)
        // Named by the title of its section, focusable for the arrow keys; only the row scrolls.
        ->toMatch('/<h2 id=\{titleId\}/')
        ->toMatch('/<div\s+role="region"\s+aria-labelledby=\{titleId\}\s+tabIndex=\{0\}\s+className="overflow-x-auto [^"]*outline-none[^"]*focus-visible:ring-\[3px\][^"]*focus-visible:outline-hidden"/')
        ->toMatch('/<ul className="flex snap-x gap-3 p-1 pb-3">\s*\{items\.map\(\(item\) => \(\s*<li key=\{item\.id\} className="shrink-0 snap-start">/')
        ->toMatch('/<section className="flex min-w-0 flex-col gap-4">/');
});

test('each category links to the list filtered on it, as a lone link with its touch target', function () {
    expect(hpSource('pages/home.tsx'))
        ->toMatch('/<Link\s+href=\{index\(\{\s*query: \{ category: category\.slug \},?\s*\}\)\}\s+className=\{cn\(\s*\'flex min-h-20 [^\']*border[^\']*outline-none[^\']*focus-visible:outline-hidden\',\s*touchTarget,\s*\)\}\s*>\s*\{category\.name\}\s*<\/Link>/')
        ->toContain("import { index } from '@/routes/providers';");
});

test('the section of the categories is there only with categories', function () {
    expect(hpSource('pages/home.tsx'))
        ->toMatch('/\{categories\.length > 0 && \(\s*<CategoriesSection categories=\{categories\} \/>\s*\)\}/')
        ->toContain("<Head title={t('home.title')} />");
});

test('nothing written by hand: no aria-label, no category name; nothing that moves or sticks', function () {
    $sources = hpSource('pages/home.tsx').hpSource('components/home/home-hero.tsx').hpSource('components/home/home-search.tsx').hpSource('components/home/scroll-row.tsx')
        .hpSource('components/home/featured-provider-card.tsx').hpSource('components/home/home-carousel.tsx').hpSource('lib/home-carousel.ts').hpSource('lib/home-hero.ts');

    expect($sources)
        // aria-labelledby is fine: it points to a visible title.
        ->not->toMatch('/aria-label(?!ledby)/')
        ->not->toMatch('/scroll-smooth|scroll-behavior|behavior:\s*[\'"]smooth/')
        ->not->toContain('sticky')
        ->not->toContain('fixed')
        ->not->toMatch('/\b(animate-|transition|duration-)/');

    // Neither a slug nor a translated name of a category: they come from the server.
    foreach (['it', 'fr', 'en'] as $locale) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        foreach ($json as $key => $name) {
            if (str_starts_with($key, 'categories.')) {
                expect($sources)->not->toContain("'".substr($key, 11)."'")->not->toContain(">{$name}<")->not->toContain("'{$name}'");
            }
        }
    }
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts of the home page exist in Italian, French and English, with the same placeholders', function () {
    $json = fn (string $locale) => json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        sort($found[0]);

        return $found[0];
    };

    $keys = array_values(array_filter(array_keys($json('it')), fn (string $key) => str_starts_with($key, 'home.')));
    // 11 of the page, 2 of "Professionisti in evidenza" (#144 step 3), 17 of the carousel (step 5),
    // 4 of the search in the band. "home.intro.cta" is gone with its button: the search does the same.
    expect($keys)->toHaveCount(34)
        ->and($keys)->not->toContain('home.intro.cta');

    // Every key the page uses, the steps and the sentences of the categories included.
    $used = hpSource('pages/home.tsx').hpSource('components/home/home-hero.tsx').hpSource('components/home/home-search.tsx').hpSource('components/home/featured-provider-card.tsx')
        .hpSource('components/home/home-carousel.tsx').hpSource('lib/home-carousel.ts');
    foreach ($keys as $key) {
        $step = preg_match('/^home\.steps\.(choose|book|confirm)\.(title|text)$/', $key, $match) === 1;
        // The sentence of a category is read by its slug (lib/home-carousel.ts).
        $sentence = preg_match('/^home\.carousel\.sentence\.(?!default$)[a-z]+$/', $key) === 1;
        expect(match (true) {
            $step => str_contains($used, "'{$match[1]}'") && str_contains($used, "`home.steps.\${step}.{$match[2]}`"),
            $sentence => str_contains($used, '`home.carousel.sentence.${category.slug}`'),
            default => str_contains($used, "'{$key}'"),
        })->toBeTrue("Not used: {$key}");

        foreach (['fr', 'en'] as $locale) {
            expect($json($locale))->toHaveKey($key)
                ->and($placeholders($json($locale)[$key]))->toBe($placeholders($json('it')[$key]), "{$locale} {$key}");
        }
        expect(preg_match('/ [:?!;](?![a-z])/', $json('fr')[$key]))->toBe(0, "fr {$key}");
    }

    expect(__('home.search.submit', [], 'it'))->toBe('Cerca')
        ->and(__('home.steps.title', [], 'it'))->toBe('Come funziona')
        ->and(__('home.intro.text', [], 'fr'))->toContain("encore\u{00A0}: trouvez");
});
