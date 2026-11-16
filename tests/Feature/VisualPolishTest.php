<?php

// The visual polish (PR 2 of the visual renewal, docs/accessibility.md): one empty tile instead of
// the colours in turn, a neutral sign instead of the mark of the provisional logo, the orange bar
// under every title of a section of the home page, the light card of the professionals, the prices
// in bold with digits of the same width, one rule for the borders and shadows of the cards. Read
// from the sources: there is no JavaScript runner.

function vpSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

test('no colour in turn for the categories without a photo: the same empty tile for all', function () {
    $lib = vpSource('lib/home-categories.ts');
    $card = vpSource('components/home/category-card.tsx');

    expect($lib)->not->toContain('CATEGORY_TONES')->not->toContain('tone')
        ->and($card)->not->toContain('card.tone')
        ->and($card.$lib)->not->toMatch('/\bbg-(primary|highlight|info|card|brand|hero)\b/')
        ->and($card)->toMatch("/<EmptyPicture\\s+className=\\{cn\\(\\s*'h-24 w-full rounded-xl',\\s*CATEGORY_TILE_EDGE,\\s*CARD_REST,?\\s*\\)\\}\\s*\\/>/");
});

test('the empty picture: sand, a neutral sign hidden from screen readers, never the mark of the logo', function () {
    $empty = vpSource('components/empty-picture.tsx');

    expect($empty)
        ->toContain("import { Sparkles } from 'lucide-react';")
        ->toContain("'relative flex items-center justify-center bg-secondary text-muted-foreground'")
        ->toMatch('/<Sparkles\s+aria-hidden="true"/')
        ->not->toContain('aria-label');

    // Neither the empty picture nor the cards that use it take an image of the provisional logo.
    foreach (['components/empty-picture.tsx', 'components/home/category-card.tsx', 'components/provider-card.tsx', 'lib/home-categories.ts'] as $path) {
        expect(vpSource($path))
            ->not->toContain('@/lib/brand')
            ->not->toMatch('/\bpin[-_.]|PIN\b|brand\/|AppLogoIcon/');
    }

    expect(vpSource('components/provider-card.tsx'))
        ->toMatch('/<EmptyPicture className="aspect-\[4\/3\] w-full">\s*<ProviderAvatar/')
        ->not->toContain('bg-muted">');
});

test('every title of a section of the home page has the orange bar, from one component', function () {
    $title = vpSource('components/home/section-title.tsx');

    expect($title)
        ->toMatch("/<h2\\s+id=\\{id\\}\\s+tabIndex=\\{focusable \\? -1 : undefined\\}\\s+className=\\{cn\\(\\s*'text-2xl font-semibold tracking-tight',/")
        ->toMatch('/<\/h2>\s*<span\s+aria-hidden="true"\s+className="block h-1 w-12 rounded-full bg-brand forced-colors:hidden"\s*\/>/');

    expect(vpSource('components/home/scroll-row.tsx'))->toContain('<SectionTitle id={titleId}>{title}</SectionTitle>')
        ->not->toMatch('/<h2\b/');
    expect(vpSource('components/home/how-it-works.tsx'))->toContain('<SectionTitle id={HOW_IT_WORKS_TITLE_ID} focusable>')
        ->not->toMatch('/<h2\b/');
    expect(vpSource('pages/home.tsx'))->toContain('<div className="mx-auto flex w-full max-w-6xl flex-col gap-12 px-4 py-10">');
});

test('the prices in bold, with digits of the same width: a service, and "da X €" in the list', function () {
    expect(vpSource('pages/providers/show.tsx'))
        ->toMatch('/<h4 className="font-medium">\{service\.name\}<\/h4>/')
        ->toMatch('/<p className="text-sm text-muted-foreground">\s*<span className="text-base font-bold text-foreground tabular-nums">\s*\{formatPrice\(locale, service\.price\)\}\s*<\/span>/');
    expect(vpSource('components/provider-card.tsx'))
        ->toContain('<strong className="font-bold tabular-nums">{price}</strong>')
        ->toMatch('/<h2 className="truncate text-lg font-semibold">/');
    expect(vpSource('components/home/featured-provider-card.tsx'))->toContain('<h3 className="text-lg font-semibold break-words">');
});

test('the card of the professionals is light: sand, brown text, rounded, an orange edge on the left', function () {
    expect(vpSource('components/home/professionals-band.tsx'))
        ->toContain('className="rounded-xl border border-l-4 border-l-brand bg-secondary text-secondary-foreground"')
        ->not->toMatch('/\b(bg|text|ring)-hero/');
    // The band at the top of the page stays brown.
    expect(vpSource('lib/home-hero.ts'))->toContain("band: 'bg-hero text-hero-foreground");
});

test('one rule for the cards: a thin border, a small shadow at rest, a stronger one on hover, rounded-xl', function () {
    expect(vpSource('lib/motion.ts'))->toContain("export const CARD_REST = 'shadow-sm';")
        ->toContain("card: 'group hover:shadow-md focus-visible:shadow-md");

    foreach (['components/provider-card.tsx', 'components/home/featured-provider-card.tsx'] as $path) {
        expect(vpSource($path))->toMatch("/'flex w-[a-z0-9-]+ flex-col overflow-hidden rounded-xl border[^']*',\\s*touchTarget,\\s*CARD_REST,\\s*CARD_MOTION\\.card,/");
    }

    $category = vpSource('components/home/category-card.tsx');
    expect($category)->toContain("className={cn('block rounded-xl', CARD_REST, CARD_MOTION.frame)}")
        ->not->toContain('rounded-md');
    // The kit's Card keeps its own small shadow (shadow-sm): the same.
    expect(vpSource('components/ui/card.tsx'))->toContain('rounded-xl border py-6 shadow-sm');
});
