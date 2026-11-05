<?php

// The provider's page on a phone (issue #139): who the provider is, what they offer and how to go
// on, in a few seconds. Order, hierarchy and style only: no new data. There is no JavaScript test
// runner: the sources are read, with the fragility of the other source tests.

function ppmSource(string $path = 'js/pages/providers/show.tsx'): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * The source without its comments: a comment that says "no sticky bar" is not a sticky bar.
 */
function ppmCode(): string
{
    return (string) preg_replace(['#\{/\*.*?\*/\}#s', '#/\*.*?\*/#s', '#^\s*//.*$#m'], '', ppmSource());
}

/**
 * The position of a piece of the source, which must be there.
 */
function ppmAt(string $source, string $needle): int
{
    $position = strpos($source, $needle);
    expect($position)->toBeInt("Not found: {$needle}");

    return (int) $position;
}

test('the page goes: header, actions, services, then hours and contacts', function () {
    $source = ppmSource();

    $order = [
        'gallery place' => '{/* The gallery of the provider\'s work (#143) will go here, above the header. */}',
        'header' => '<header className="flex flex-col gap-4">',
        'name' => '<h1 className="text-2xl font-semibold">',
        'city' => '{provider.city}',
        'categories' => '{categories.map((category) => (',
        'bio' => '{provider.bio}',
        'choose a service' => "{t('providers.show.choose_service')}",
        'WhatsApp in the actions' => "{t('providers.show.whatsapp')}\n",
        'end of the header' => '</header>',
        'services' => '<section',
        'a service' => '<ServiceItem',
        'hours' => "<h2>{t('providers.show.hours')}</h2>",
        'contacts' => "<h2>{t('providers.show.contacts')}</h2>",
    ];

    $positions = array_map(fn (string $needle) => ppmAt($source, $needle), $order);

    expect(array_keys($positions))->toBe(array_keys(collect($positions)->sort()->all()));
});

test('"Scegli un servizio" is a link to the services, which moves the focus to their title', function () {
    $source = ppmSource();

    expect($source)
        ->toContain("const SERVICES_ID = 'servizi';")
        ->toContain("const SERVICES_TITLE_ID = 'servizi-titolo';")
        // A link (an anchor on the page), filled, full width on phones; not a button.
        ->toMatch('/\{canBook && \(\s*<Button asChild className="max-md:w-full">\s*<a\s+href=\{`#\$\{SERVICES_ID\}`\}\s+onClick=\{goToServices\}\s*>\s*\{t\(\'providers\.show\.choose_service\'\)\}\s*<\/a>/')
        // The section has the id, its title can take the focus.
        ->toMatch('/<section\s+id=\{SERVICES_ID\}/')
        ->toMatch('/<h2\s+id=\{SERVICES_TITLE_ID\}\s+tabIndex=\{-1\}/')
        ->toMatch('/function goToServices\(event: MouseEvent<HTMLAnchorElement>\) \{\s*const title = document\.getElementById\(SERVICES_TITLE_ID\);\s*if \(title !== null\) \{\s*event\.preventDefault\(\);\s*title\.focus\(\);[\s\S]*?title\.scrollIntoView\(\);/')
        // Instant: no smooth scrolling.
        ->and(ppmCode())->not->toContain("behavior: 'smooth'")->not->toContain('scroll-smooth');
});

test('"Scegli un servizio" only when a booking is possible: not in the preview, not without services', function () {
    expect(ppmSource())->toContain('const canBook = !preview && provider.service_groups.length > 0;');
    expect(substr_count(ppmSource(), "{t('providers.show.choose_service')}"))->toBe(1);
});

test('WhatsApp is in the actions as an outline button, in a new tab, said to screen readers', function () {
    expect(ppmSource())
        ->toMatch('/\{whatsapp\?\.url && \(\s*<Button\s+asChild\s+variant="outline"\s+className="max-md:w-full"\s*>\s*<a\s+href=\{whatsapp\.url\}\s+target="_blank"\s+rel="noopener noreferrer"\s*>\s*\{t\(\'providers\.show\.whatsapp\'\)\}\s*<NewTabHint \/>\s*<\/a>/')
        // Instagram: a text link, as in the contacts.
        ->toMatch('/\{instagram\?\.url && \(\s*<a href=\{instagram\.url\} \{\.\.\.externalLink\}>\s*@\{instagram\.username\}\s*<NewTabHint \/>\s*<\/a>/')
        // Without a number, no button and no empty place: the whole row needs one action at least.
        ->toContain('{(canBook || whatsapp?.url || instagram?.url) && (');
});

test('"Prenota" is a normal button, full width on phones, named after its service', function () {
    expect(ppmSource('js/components/booking-sheet.tsx'))
        ->toMatch('/<Button className="max-md:w-full">\s*\{t\(\'booking\.form\.open\'\)\}\s*<span className="sr-only"> \{service\.name\}<\/span>/')
        ->not->toContain('<Button size="sm">');
});

test('on phones each service is a card of its own; from md up the cards of the categories stay', function () {
    expect(ppmSource())
        ->toContain('className="max-md:gap-3 max-md:border-0 max-md:bg-transparent max-md:py-0 max-md:shadow-none"')
        ->toMatch('/<li\s+key=\{service\.id\}\s+className="flex flex-col gap-4 max-md:rounded-xl max-md:border max-md:bg-card max-md:p-4/')
        ->toContain('<Separator className="max-md:hidden" />')
        // The two columns from lg up, as before.
        ->toContain('<div className="grid gap-6 lg:grid-cols-3">');
});

test('the header has a larger photo and the categories of the services', function () {
    expect(ppmSource())
        ->toMatch('/<ProviderAvatar\s+name=\{provider\.name\}\s+avatarUrl=\{provider\.avatar_url\}\s+className="size-24 text-2xl"\s*\/>/')
        ->toContain('const categories = provider.service_groups.map((group) => group.category);')
        ->toMatch('/\{categories\.length > 0 && \(\s*<ul className="flex flex-wrap gap-2">/');
});

test('nothing sticks to the screen: no sticky or fixed bar on the page', function () {
    expect(preg_match('/\b(sticky|fixed)\b/', ppmCode()))->toBe(0);
});

test('the new text exists in Italian, French and English', function () {
    expect(__('providers.show.choose_service', [], 'it'))->toBe('Scegli un servizio')
        ->and(__('providers.show.choose_service', [], 'fr'))->toBe('Choisir une prestation')
        ->and(__('providers.show.choose_service', [], 'en'))->toBe('Choose a service');

    foreach (['it', 'fr', 'en'] as $locale) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        expect($json['providers.show.choose_service'])->not->toContain(':');
    }
});
