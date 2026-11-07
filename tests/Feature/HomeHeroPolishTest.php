<?php

// The look of the band at the top of the home page (#144 step 9, docs/home.md): taller fields with
// a magnifying glass and an example, a bigger h1, previous and next with their chevron only next to
// the text of the slide. Only the look: the form, the slides and the queries are those of
// HomeHeroSearchTest and HomeCarouselTest; the colours are measured in AccessibilityContrastTest.

function hhpSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

function hhpBand(): string
{
    return hhpSource('components/home/home-hero.tsx').hhpSource('components/home/home-search.tsx')
        .hhpSource('components/home/home-carousel.tsx').hhpSource('lib/home-hero.ts');
}

/**
 * @return array<string, string>
 */
function hhpLang(string $locale): array
{
    return json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------------------------
// The search
// ---------------------------------------------------------------------------------------------

test('the example in the field comes from t(), and the label stays visible above it', function () {
    $search = hhpSource('components/home/home-search.tsx');

    expect($search)
        ->toContain("placeholder={t('home.search.placeholder')}")
        // The visible label, before the field and tied to it: never sr-only, never replaced.
        ->toMatch("/<Label htmlFor=\"home-search-q\">\\{t\\('home\\.search\\.q'\\)\\}<\\/Label>[\\s\\S]*?<Input\\s+id=\"home-search-q\"/")
        ->and(substr_count($search, 'placeholder='))->toBe(1)
        ->and($search)->not->toMatch('/<Label[^>]*className="[^"]*sr-only/');
    expect(__('home.search.placeholder', [], 'it'))->not->toBe(__('home.search.q', [], 'it'));
});

test('the magnifying glass is decorative, inside the field, and never in the way of a click', function () {
    expect(hhpSource('components/home/home-search.tsx'))
        ->toContain("import { Search } from 'lucide-react';")
        ->toMatch('/<div className="relative">\s*<Search\s+aria-hidden="true"\s+className="pointer-events-none absolute inset-y-0 left-3 my-auto size-5 text-muted-foreground"\s*\/>\s*<Input\b/')
        // The field leaves room for it.
        ->toContain("className={cn('pl-10', SIZE, HERO.field)}");
});

test('the fields and the button: 44px on phones, 3rem from 768 px, heights in rem only; one row from 768 px', function () {
    $search = hhpSource('components/home/home-search.tsx');

    expect($search)
        ->toContain("const SIZE = 'h-11 text-base md:h-12 md:text-base';")
        ->and(substr_count($search, 'SIZE,'))->toBe(3)
        // The select keeps its 44px on phones; the button is full width under 768 px.
        ->and($search)->toContain('max-md:min-h-11')
        ->toContain("className={cn('px-6 max-md:w-full', SIZE, HERO.button)}")
        ->toContain('className="flex flex-col gap-4 md:flex-row md:flex-wrap md:items-end"')
        ->toContain('className="grid min-w-0 gap-2 md:min-w-56 md:flex-1"')
        ->toContain('className="grid min-w-0 gap-2 md:w-44"');
    // Never a height in pixels: the zoom makes it grow.
    expect(hhpBand())->not->toMatch('/\b(min-h|h|size)-\[\d+px\]/');
});

// ---------------------------------------------------------------------------------------------
// The title and the band
// ---------------------------------------------------------------------------------------------

test('the h1 is bigger from 1024 px, with balanced lines and a measure of its own', function () {
    expect(hhpSource('components/home/home-hero.tsx'))
        ->toMatch("/<h1 className=\"max-w-2xl text-3xl leading-tight font-semibold text-balance break-words md:text-4xl lg:text-5xl\">\\s*\\{t\\('home\\.intro\\.title'\\)\\}\\s*<\\/h1>/")
        // Its colour is the band's: no colour of its own.
        ->not->toMatch('/<h1 className="[^"]*\btext-(hero|foreground|primary|white)/');
});

test('the band has more room, in rem; the photo stays 3:2 with square corners', function () {
    expect(hhpSource('components/home/home-hero.tsx'))->toContain("'mx-auto grid w-full max-w-6xl gap-10 px-4 py-12 md:min-h-[32rem] md:items-center md:py-16 lg:py-20',");
    $carousel = hhpSource('components/home/home-carousel.tsx');
    expect($carousel)->toContain('aspect-[3/2]')
        ->and(preg_replace('/rounded-full/', '', $carousel))->not->toContain('rounded');
});

// ---------------------------------------------------------------------------------------------
// The carousel
// ---------------------------------------------------------------------------------------------

test('previous and next show their chevron only; their name is an sr-only text from t()', function () {
    $carousel = hhpSource('components/home/home-carousel.tsx');

    foreach (['Left' => 'previous', 'Right' => 'next'] as $side => $key) {
        expect($carousel)->toMatch("/<Button\\s+type=\"button\"\\s+size=\"icon\"\\s+className=\\{cn\\(CONTROL_SIZE, HERO\\.button\\)\\}\\s+onClick=\\{[^}]*\\}\\s*>\\s*<Chevron{$side} aria-hidden=\"true\" \\/>\\s*<span className=\"sr-only\">\\s*\\{t\\('home\\.carousel\\.{$key}'\\)\\}\\s*<\\/span>\\s*<\\/Button>/");
    }
    expect(substr_count($carousel, 'size="icon"'))->toBe(2);
});

test('44px targets under 768 px for previous, next and the dots; at least 36px above', function () {
    $carousel = hhpSource('components/home/home-carousel.tsx');

    expect($carousel)
        ->toContain("const CONTROL_SIZE = 'size-11 md:size-9 [&_svg]:size-5';")
        ->toContain("'flex size-11 items-center justify-center rounded-full outline-none focus-visible:ring-[3px] focus-visible:outline-hidden md:size-8',");
});

test('the text of the slide on the left, the controls on its right, on one row when there is room', function () {
    expect(hhpSource('components/home/home-carousel.tsx'))
        ->toMatch('/<div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-4">\s*(\{\/\*[\s\S]*?\*\/\}\s*)?<div className="grid min-w-0 flex-1 basis-64">[\s\S]*?\{total > 1 && \(\s*<div className="flex items-center gap-1">/');
});

// ---------------------------------------------------------------------------------------------
// Nothing written by hand
// ---------------------------------------------------------------------------------------------

test('no aria-label written by hand, no timer, nothing that moves or scrolls smoothly', function () {
    expect(hhpBand())
        // aria-labelledby is fine: it points to a text on the page.
        ->not->toMatch('/aria-label(?!ledby)/')
        ->not->toMatch('/\b(setInterval|setTimeout|requestAnimationFrame)\b/')
        ->not->toMatch('/\b(transition|animate-|duration-|translate-)|scroll-smooth/');
});

test('the example exists in Italian, French and English, without placeholders, in the right form', function () {
    foreach (['it', 'fr', 'en'] as $locale) {
        $text = hhpLang($locale)['home.search.placeholder'] ?? null;

        expect($text)->toBeString()->not->toBe('')
            ->and($text)->not->toMatch('/:[a-z_]+/')
            ->and(preg_match('/ [:?!;](?![a-z])/', (string) $text))->toBe(0, $locale);
    }
    expect(array_keys(hhpLang('fr')))->toBe(array_keys(hhpLang('it')))
        ->and(array_keys(hhpLang('en')))->toBe(array_keys(hhpLang('it')));
    expect(__('home.search.placeholder', [], 'it'))->toBe('Treccine, barbiere, unghie…')
        ->and(__('home.search.placeholder', [], 'fr'))->toBe('Tresses, barbier, ongles…')
        ->and(__('home.search.placeholder', [], 'en'))->toBe('Braids, barber, nails…');
});

test('no real contact: no email address, no phone number in the band or its texts', function () {
    $sources = hhpBand();
    foreach (['it', 'fr', 'en'] as $locale) {
        $sources .= implode("\n", array_filter(hhpLang($locale), fn (string $key) => str_starts_with($key, 'home.'), ARRAY_FILTER_USE_KEY));
    }

    expect($sources)
        ->not->toMatch('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/')
        ->not->toMatch('/(\+|00)\d[\d ]{7,}/')
        ->not->toContain('mailto:')
        ->not->toContain('wa.me/');
});
