<?php

// Forced colours and text spacing (issue #55, phase 6). There is no JavaScript test runner: the
// sources are read. Outside components/ui the formatter sorts the classes, so the checks look for
// single classes in a class string, never for a sequence of them.
//
// In forced colours (Windows high contrast) the browser drops box-shadow: the focus rings of the
// kit, drawn with box-shadow, disappear. Tailwind 4's outline-hidden is "no outline", except in
// forced colours, where it is a 2px outline in the system colour: on :focus-visible only, it is the
// focus indicator there, and nothing changes with the normal colours.

function fcsSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * The classes of the first string that follows $pattern, a regex that ends right before the
 * opening quote (single or double).
 *
 * @return list<string>
 */
function fcsClasses(string $source, string $pattern): array
{
    expect(preg_match('/'.$pattern.'(["\'])((?:(?!\1).)*)\1/s', $source, $match))->toBe(1, "Not found: {$pattern}");

    return preg_split('/\s+/', trim($match[2])) ?: [];
}

/**
 * Every React source of the application, the generated folders left out.
 *
 * @return list<string>
 */
function fcsSources(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = $file->getPathname();

        if (str_ends_with($path, '.tsx') && preg_match('#/resources/js/(routes|actions|wayfinder)/#', $path) !== 1) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

// ---------------------------------------------------------------------------------------------
// Focus in forced colours
// ---------------------------------------------------------------------------------------------

test('the controls with a box-shadow ring have an outline on focus in forced colours', function (string $path, string $pattern) {
    expect(fcsClasses(fcsSource("js/{$path}"), $pattern))
        ->toContain('focus-visible:outline-hidden')
        ->not->toContain('focus-visible:outline-none');
})->with([
    'button' => ['components/ui/button.tsx', 'const buttonVariants = cva\(\s*'],
    'input' => ['components/ui/input.tsx', 'data-slot="input"\s*className=\{cn\(\s*'],
    'select trigger' => ['components/ui/select.tsx', 'data-slot="select-trigger"[\s\S]*?className=\{cn\(\s*'],
    'checkbox' => ['components/ui/checkbox.tsx', 'data-slot="checkbox"\s*className=\{cn\(\s*'],
    'textarea' => ['components/ui/textarea.tsx', 'data-slot="textarea"\s*className=\{cn\(\s*'],
    'booking notes' => ['components/booking-fields.tsx', 'const textareaClass =\s*'],
    'show password' => ['components/password-input.tsx', 'onClick=\{\(\) => setShowPassword\(\(prev\) => !prev\)\}\s*className='],
    'provider card' => ['components/provider-card.tsx', 'href=\{show\(provider\.slug\)\}\s*className=\{cn\(\s*'],
    'services: select' => ['pages/provider/services/index.tsx', 'const selectClass =\s*'],
    'service: select' => ['pages/provider/services/edit.tsx', 'const selectClass =\s*'],
    'availability: select' => ['pages/provider/availability/index.tsx', 'const selectClass =\s*'],
    'bookings: select' => ['pages/provider/bookings/index.tsx', 'const selectClass =\s*'],
    'new booking: select' => ['pages/provider/bookings/create.tsx', 'const selectClass =\s*'],
]);

test('every outline-none of the application keeps an outline on focus, but the programmatic targets', function () {
    // The main elements are reached with the skip link (tabIndex -1), never with Tab: no ring at
    // all. toggle and navigation-menu are kit components the application does not use.
    $left = ['layouts/public-layout.tsx', 'layouts/app/app-sidebar-layout.tsx', 'components/ui/toggle.tsx', 'components/ui/navigation-menu.tsx'];

    foreach (fcsSources() as $path) {
        $relative = str_replace(resource_path('js').'/', '', $path);

        if (in_array($relative, $left, true)) {
            continue;
        }

        preg_match_all('/(["\'])((?:(?!\1).)*\boutline-none\b(?:(?!\1).)*)\1/', (string) file_get_contents($path), $strings);

        foreach ($strings[2] as $classes) {
            $tokens = preg_split('/\s+/', $classes) ?: [];

            expect(in_array('outline-none', $tokens, true) && ! in_array('focus-visible:outline-hidden', $tokens, true) && ! in_array('focus:outline-hidden', $tokens, true))
                ->toBeFalse("outline-none without an outline on focus in {$relative}: {$classes}");
        }
    }

    foreach (['layouts/public-layout.tsx', 'layouts/app/app-sidebar-layout.tsx'] as $layout) {
        expect(fcsSource("js/{$layout}"))->toMatch('/tabIndex=\{-1\}[\s\S]*?outline-none|outline-none[\s\S]*?tabIndex=\{-1\}/');
    }
});

test('the side menu has an outline only with the focus, not always, in forced colours', function () {
    $source = fcsSource('js/components/ui/sidebar.tsx');

    // A bare outline-hidden would draw an outline around every item in forced colours, the focused
    // one as the others.
    expect(preg_match('/(?<![:\w-])outline-hidden/', $source))->toBe(0)
        ->and(substr_count($source, 'outline-none focus-visible:outline-hidden'))->toBe(5);
    expect(fcsClasses($source, 'const sidebarMenuButtonVariants = cva\(\s*'))
        ->toContain('outline-none', 'focus-visible:outline-hidden', 'focus-visible:ring-2');
});

// ---------------------------------------------------------------------------------------------
// Borders in forced colours
// ---------------------------------------------------------------------------------------------

test('every button has a border in forced colours, where its background is not drawn', function () {
    expect(fcsClasses(fcsSource('js/components/ui/button.tsx'), 'const buttonVariants = cva\(\s*'))
        ->toContain('forced-colors:border')
        // Only there: with the normal colours no button gets a border it did not have.
        ->not->toContain('border');
});

test('the appearance tabs say which one is chosen, and show it in forced colours', function () {
    $source = fcsSource('js/components/appearance-tabs.tsx');

    expect($source)->toContain('aria-pressed={appearance === value}');
    expect(fcsClasses($source, 'appearance === value\s*\?\s*'))
        ->toContain('bg-background', 'forced-colors:border');
    // Not on the others.
    expect(fcsClasses($source, 'appearance === value\s*\?\s*\'[^\']*\'\s*:\s*'))
        ->not->toContain('forced-colors:border');
});

// ---------------------------------------------------------------------------------------------
// Narrow screens and text spacing
// ---------------------------------------------------------------------------------------------

test('the name of the site in the public header is never squeezed', function () {
    expect(fcsClasses(fcsSource('js/layouts/public-layout.tsx'), '<Link\s+href=\{home\(\)\}\s+className=\{cn\(\s*'))
        ->toContain('flex', 'shrink-0', 'items-center');
});

test('the fields of the providers\' list never force a width on a phone', function () {
    // Since the list's PR B the category is a native select in the panel and the city a native
    // select in the search bar (SearchFields): full width under 768 px, a minimum width only from
    // 768 px, never a fixed one.
    $sources = fcsSource('js/components/search-fields.tsx').fcsSource('js/components/provider-filters.tsx')
        .fcsSource('js/components/provider-filters-panel.tsx').fcsSource('js/components/provider-sort.tsx');

    expect($sources)
        ->not->toMatch('/(?<![\w:-])(min-w|w)-([1-9]\d*|\[[^\]]+\])(?![\w-])/')
        ->toContain('md:min-w-56')
        ->toContain('md:w-44');
    expect(fcsClasses(fcsSource('js/components/provider-filters-panel.tsx'), 'const selectClass =\s*'))
        ->toContain('w-full', 'min-w-0');
    expect(fcsClasses(fcsSource('js/components/search-fields.tsx'), '<select\s+id=[\s\S]*?className=\{cn\(\s*'))
        ->toContain('w-full', 'min-w-0');
});
