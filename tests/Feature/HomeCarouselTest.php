<?php

use Illuminate\Support\Facades\Process;

// The carousel in the band at the top of the home page (docs/home.md): its small rules run with
// Node, its source, its texts. The colours of the band are measured in AccessibilityContrastTest.

function hcSource(string $path = 'js/components/home/home-carousel.tsx'): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * Run a lib of resources/js/lib with Node and give back what the calls printed.
 *
 * @return array<mixed>
 */
function hcRun(string $lib, string $calls): array
{
    $script = sys_get_temp_dir().'/griot-carousel-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path("js/lib/{$lib}.ts");
    file_put_contents($script, "import * as h from '{$helper}';\nconsole.log(JSON.stringify({$calls}));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

// A t() as the hook's: the key itself when it is missing, the placeholders written in.
const HC_T = "const texts = { 'home.carousel.sentence.treccine': 'Trecce', 'home.carousel.sentence.default': 'Generica', 'home.carousel.position': 'Slide :number di :total' };
const t = (key, r = {}) => Object.entries(r).reduce((text, [k, v]) => text.split(':' + k).join(String(v)), texts[key] ?? key);
const cat = (id, slug) => ({ id, slug, name: 'N' + id });
const photo = { width: 3, height: 2, variants: [{ width: 640, url: '/p.webp' }] };
const photos = { treccine: photo, barbiere: photo, nuova: photo, c1: photo, c2: photo, c3: photo };";

// ---------------------------------------------------------------------------------------------
// The small rules, run with Node
// ---------------------------------------------------------------------------------------------

test('one slide per category with a photo, in order; a category without a photo has none', function () {
    $result = hcRun('home-carousel', '(() => { '.HC_T.' return {
        none: h.buildSlides(t, [], photos),
        one: h.buildSlides(t, [cat(1, "treccine")], photos),
        mixed: h.buildSlides(t, [cat(1, "c1"), cat(2, "senza-foto"), cat(3, "c3"), cat(4, "c2")], photos).map((s) => s.id),
        noPhotos: h.buildSlides(t, [cat(1, "treccine")]),
    }; })()');

    expect($result['none'])->toBe([])
        ->and($result['one'])->toBe([['id' => 1, 'slug' => 'treccine', 'name' => 'N1', 'sentence' => 'Trecce', 'image' => ['src' => '/p.webp', 'srcSet' => '/p.webp 640w', 'width' => 3, 'height' => 2]]])
        // The order of the categories, without the one that has no photo.
        ->and($result['mixed'])->toBe([1, 3, 4])
        ->and($result['noPhotos'])->toBe([]);
});

test('a category has its own sentence, or the general one: a new category breaks nothing', function () {
    $result = hcRun('home-carousel', '(() => { '.HC_T.' return h.buildSlides(t, [cat(1, "treccine"), cat(2, "nuova")], photos).map((s) => s.sentence); })()');

    expect($result)->toBe(['Trecce', 'Generica']);
});

test('"Slide N di M", and previous and next go round with 1, 2 and 7 slides', function () {
    expect(hcRun('home-carousel', '(() => { '.HC_T.' return [h.slidePosition(t, 0, 1), h.slidePosition(t, 1, 7), h.slidePosition(t, 6, 7)]; })()'))
        ->toBe(['Slide 1 di 1', 'Slide 2 di 7', 'Slide 7 di 7']);

    // The same functions as the zoom of the photos (gallery.ts).
    expect(hcRun('gallery', '{
        one: [h.nextIndex(0, 1), h.previousIndex(0, 1)],
        two: [h.nextIndex(0, 2), h.nextIndex(1, 2), h.previousIndex(0, 2)],
        seven: [h.nextIndex(6, 7), h.previousIndex(0, 7), h.nextIndex(3, 7)],
    }'))->toBe(['one' => [0, 0], 'two' => [1, 0, 1], 'seven' => [0, 6, 4]]);
    expect(hcSource())->toContain("import { nextIndex, previousIndex } from '@/lib/gallery';");
});

// ---------------------------------------------------------------------------------------------
// The source
// ---------------------------------------------------------------------------------------------

test('in the band at the top, before the categories; the slides are built once by the page', function () {
    expect(hcSource('js/pages/home.tsx'))
        ->toContain('const slides = buildSlides(t, categories, HOME_PHOTOS);')
        ->toMatch('/<HomeHero cities=\{cities\} slides=\{slides\} \/>[\s\S]*<CategoriesSection/');
    expect(hcSource('js/components/home/home-hero.tsx'))->toContain('<HomeCarousel slides={slides} />');
    expect(hcSource())
        ->toContain('export function HomeCarousel({ slides }: { slides: Slide[] }) {')
        ->toMatch('/if \(total === 0\) \{\s*return null;\s*\}/');
});

test('a section with a translated role and name; each slide a group named by its category, without a heading', function () {
    $source = hcSource();

    expect($source)
        ->toMatch("/<section\\s+aria-roledescription=\\{t\\('home\\.carousel\\.role'\\)\\}\\s+aria-labelledby=\\{LABEL_ID\\}/")
        ->toMatch("/<p id=\\{LABEL_ID\\} className=\"sr-only\">\\s*\\{t\\('home\\.carousel\\.label'\\)\\}\\s*<\\/p>/")
        ->toMatch("/<div\\s+key=\\{slide\\.id\\}\\s+role=\"group\"\\s+aria-roledescription=\\{t\\('home\\.carousel\\.slide'\\)\\}\\s+aria-labelledby=\\{slideNameId\\(slide\\.id\\)\\}/")
        // The name of the category is a paragraph, not a title: the page keeps its headings.
        ->toMatch('/<p\s+id=\{slideNameId\(slide\.id\)\}\s+className="text-xl font-semibold break-words"\s*>\s*\{slide\.name\}\s*<\/p>/')
        ->toMatch('/<p className="sr-only">\\s*\\{slidePosition\\(t, position, total\\)\\}\\s*<\\/p>/')
        ->not->toMatch('/<h[1-6]\b/')
        ->not->toMatch('/aria-label(?!ledby)/');
});

test('every slide in the same cell, the others invisible: the height never changes', function () {
    $source = hcSource();

    expect($source)
        ->toMatch('/<div className="grid">\s*\{slides\.map\(/')
        // The photo, then the text under it, in one column.
        ->toMatch("/'col-start-1 row-start-1 flex min-w-0 flex-col gap-3',\\s*position !== current && 'invisible',/")
        // Hidden by visibility only: no display none, no height of its own per slide.
        ->not->toMatch('/[\s\'"]hidden[\s\'"]|\bhidden=\{/')
        ->not->toMatch('/\b(min-h|max-h|h)-\[/')
        ->not->toContain('display');
});

test('previous, next and the dots only with more than one slide; the change is announced', function () {
    $source = hcSource();

    expect($source)
        ->toMatch("/\\{total > 1 && \\(\\s*<div[^>]*>\\s*<Button\\s+type=\"button\"\\s+className=\\{HERO\\.button\\}\\s+onClick=\\{\\(\\) => go\\(previousIndex\\(current, total\\)\\)\\}\\s*>\\s*<ChevronLeft aria-hidden=\"true\" \\/>\\s*\\{t\\('home\\.carousel\\.previous'\\)\\}/")
        ->toMatch("/onClick=\\{\\(\\) => go\\(nextIndex\\(current, total\\)\\)\\}\\s*>\\s*\\{t\\('home\\.carousel\\.next'\\)\\}/")
        // A dot per slide: a button, the current one marked, a name of its own.
        ->toMatch("/<button\\s+type=\"button\"\\s+aria-current=\\{\\s*position === current\\s*\\?\\s*'true'\\s*:\\s*undefined\\s*\\}\\s+onClick=\\{\\(\\) => go\\(position\\)\\}/")
        ->toMatch("/<span className=\"sr-only\">\\s*\\{t\\('home\\.carousel\\.go_to', \\{\\s*number: position \\+ 1,?\\s*\\}\\)\\}\\s*<\\/span>/")
        // Going round: never disabled under the focus.
        ->not->toMatch('/\bdisabled[=}]/')
        // The status line: empty when the page opens, then "Slide N di M" after each change.
        ->toContain("const [announcement, setAnnouncement] = useState('');")
        // Since step 6 the slide is also marked as seen (its photo, HomePhotosTest).
        ->toMatch('/const go = \(target: number\) => \{\s*setChosen\(target\);\s*setSeen\(\(shown\) =>\s*shown\.includes\(target\) \? shown : \[\.\.\.shown, target\],?\s*\);\s*setAnnouncement\(slidePosition\(t, target, total\)\);\s*\};/')
        ->toMatch('/<p role="status" className="sr-only">\s*\{announcement\}\s*<\/p>/');
});

test('the dots are 44px on phones, with a visible focus in the band\'s white', function () {
    expect(hcSource())->toMatch("/className=\\{cn\\(\\s*'flex size-11 items-center justify-center rounded-full outline-none focus-visible:ring-\\[3px\\] focus-visible:outline-hidden md:size-8',\\s*HERO\\.ring,?\\s*\\)\\}/")
        // Bigger when current: it shows without colour too.
        ->toMatch("/position === current\\s*\\? cn\\(\\s*'size-3\\.5',\\s*HERO\\.dotCurrent,?\\s*\\)\\s*: 'size-2\\.5',/")
        ->toMatch("/'rounded-full border',\\s*HERO\\.dot,/");
});

test('the button of a slide goes to the filtered list and says the category to screen readers', function () {
    expect(hcSource())
        ->toMatch("/<Button\\s+asChild\\s+className=\\{cn\\('max-md:w-full', HERO\\.button\\)\\}\\s*>\\s*<Link\\s+href=\\{providersIndex\\(\\{\\s*query: \\{ category: slide\\.slug \\},?\\s*\\}\\)\\}\\s*>\\s*\\{t\\('home\\.carousel\\.cta'\\)\\}\\s*<span className=\"sr-only\">\\s*\\{' '\\}\\s*\\{t\\('home\\.carousel\\.cta_which', \\{\\s*category: slide\\.name,?\\s*\\}\\)\\}\\s*<\\/span>\\s*<\\/Link>\\s*<\\/Button>/");
});

test('every slide has a photo, decorative, in a box of its own mounted from the slide\'s first visit', function () {
    expect(hcSource())->toMatch('/<div className="relative aspect-\[3\/2\] bg-muted">\s*\{seen\.includes\(position\) && \(\s*<img[\s\S]*?alt=""[\s\S]*?\/>\s*\)\}\s*<\/div>/');
    expect(hcSource('js/lib/home-carousel.ts'))
        ->toContain('image: SlideImage;')
        ->toContain('const image = slideImage(category.slug, photos);')
        ->toMatch('/if \(image === null\) \{\s*return \[\];\s*\}/')
        ->toContain('photos: Record<string, HomePhoto> = {},')
        ->not->toContain('tone');
});

test('nothing moves by itself, nothing sticks, no colour written by hand, no name of a category', function () {
    $sources = hcSource().hcSource('js/lib/home-carousel.ts');

    expect($sources)
        ->not->toMatch('/\b(setInterval|setTimeout|requestAnimationFrame|autoPlay|autoplay)\b/')
        ->not->toMatch('/\b(transition|animate-|duration-|translate-|scroll-smooth)/')
        ->not->toMatch('/sticky|fixed/')
        // Only the tokens: no palette colour, no hex, no rgb or oklch, no arbitrary colour.
        ->not->toMatch('/\b(bg|text|border|ring)-(red|green|blue|yellow|sky|amber|emerald|teal|gray|slate|zinc|neutral|white|black)\b/')
        ->not->toMatch('/rgb\(|oklch\(|-\[#/');
    // A hexadecimal colour, in the code only: the comments name issues ("#144").
    $code = (string) preg_replace(['#\{/\*.*?\*/\}#s', '#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $sources);
    expect($code)->not->toMatch('/#[0-9a-fA-F]{3,6}\b/');

    foreach (['treccine', 'barbiere', 'nails', 'ciglia', 'sopracciglia', 'couture', 'ristorazione'] as $slug) {
        expect($sources)->not->toContain("'{$slug}'");
    }
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts of the carousel exist in Italian, French and English, with the same placeholders', function () {
    $json = fn (string $locale) => json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        sort($found[0]);

        return $found[0];
    };

    $keys = array_values(array_filter(array_keys($json('it')), fn (string $key) => str_starts_with($key, 'home.carousel.')));
    expect($keys)->toHaveCount(17);

    foreach ($keys as $key) {
        foreach (['fr', 'en'] as $locale) {
            expect($json($locale))->toHaveKey($key)
                ->and($placeholders($json($locale)[$key]))->toBe($placeholders($json('it')[$key]), "{$locale} {$key}");
        }
        expect(preg_match('/ [:?!;](?![a-z])/', $json('fr')[$key]))->toBe(0, "fr {$key}");
    }

    // A sentence of its own for every category of today.
    preg_match_all("/'([a-z]+)' => '[^']+',/", (string) file_get_contents(database_path('seeders/CategorySeeder.php')), $slugs);
    expect($slugs[1])->toHaveCount(7);
    foreach ($slugs[1] as $slug) {
        expect($json('it'))->toHaveKey("home.carousel.sentence.{$slug}");
    }

    expect(__('home.carousel.role', [], 'it'))->toBe('carosello')
        ->and(__('home.carousel.role', [], 'fr'))->toBe('carrousel')
        ->and(__('home.carousel.slide', [], 'en'))->toBe('slide')
        ->and(__('home.carousel.position', ['number' => 2, 'total' => 7], 'it'))->toBe('Slide 2 di 7')
        ->and(__('home.carousel.go_to', ['number' => 3], 'it'))->toBe('Vai alla slide 3')
        ->and(__('home.carousel.cta', [], 'it').' '.__('home.carousel.cta_which', ['category' => 'Treccine'], 'it'))->toBe('Vedi i professionisti (Treccine)')
        ->and(__('home.carousel.sentence.ristorazione', [], 'fr'))->toBe("Cuisine africaine\u{00A0}: découvrez qui la prépare et réservez.");
});

test('the title of the home page says what the site is for', function () {
    expect(__('home.title', [], 'it'))->toBe('Prenota un professionista vicino a te')
        ->and(__('home.title', [], 'fr'))->toBe('Réservez un professionnel près de chez vous')
        ->and(__('home.title', [], 'en'))->toBe('Book a professional near you');
    expect(hcSource('js/pages/home.tsx'))->toContain("<Head title={t('home.title')} />");
    // Composed as on the other pages: "<title> - Griot".
    expect(hcSource('js/app.tsx'))->toContain('title: (title) => (title ? `${title} - ${appName}` : appName),');
});
