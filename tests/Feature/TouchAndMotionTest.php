<?php

// Touch targets and reduced motion (issue #55, phase 5). There is no JavaScript test runner: the
// sources are read. Outside components/ui the formatter sorts the classes, so the checks look for
// single classes, never for a sequence of them. A class in the source says nothing about the size
// on screen: that is checked by hand (docs in the PR).

function tamSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * The classes of the first string that follows $pattern, a regex that ends right before the
 * opening quote.
 *
 * @return list<string>
 */
function tamClasses(string $source, string $pattern): array
{
    expect(preg_match('/'.$pattern.'"([^"]*)"/', $source, $match))->toBe(1, "Not found: {$pattern}");

    return preg_split('/\s+/', trim($match[1])) ?: [];
}

/**
 * Every React and CSS source of the application, the generated folders left out.
 *
 * @return list<string>
 */
function tamSources(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = $file->getPathname();

        if (preg_match('/\.(tsx?|css)$/', $path) === 1 && preg_match('#/resources/js/(routes|actions|wayfinder)/#', $path) !== 1) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

// ---------------------------------------------------------------------------------------------
// Reduced motion
// ---------------------------------------------------------------------------------------------

test('with "reduce motion" every animation and transition ends at once, without delay', function () {
    $css = tamSource('css/app.css');

    expect(preg_match('/@media \(prefers-reduced-motion: reduce\) \{([\s\S]*?)\n\}/', $css, $match))->toBe(1);
    $block = $match[1];

    expect($block)->toMatch('/\*,\s*::before,\s*::after \{([^}]*)\}/');
    preg_match('/\*,\s*::before,\s*::after \{([^}]*)\}/', $block, $all);

    foreach ([
        'animation-duration: 0.01ms !important;',
        'animation-delay: 0s !important;',
        'animation-iteration-count: 1 !important;',
        'transition-duration: 0.01ms !important;',
        'transition-delay: 0s !important;',
        'scroll-behavior: auto !important;',
    ] as $declaration) {
        expect($all[1])->toContain($declaration);
    }
});

test('with "reduce motion" the loading spinner keeps turning, slower', function () {
    preg_match('/@media \(prefers-reduced-motion: reduce\) \{([\s\S]*?)\n\}/', tamSource('css/app.css'), $match);
    $block = $match[1] ?? '';

    // Same importance as the rule for every element, but a class is more specific than *: it wins.
    expect($block)->toMatch('/\*,\s*::before,\s*::after \{[^}]*\}[\s\S]*\.animate-spin \{\s*animation-duration: 1\.5s !important;\s*animation-iteration-count: infinite !important;\s*\}/');

    // The two spinners of the application turn with that class.
    expect(tamSource('js/components/ui/spinner.tsx'))->toContain('"size-4 animate-spin"');
    expect(tamSource('js/pages/auth/forgot-password.tsx'))->toContain('<LoaderCircle className="h-4 w-4 animate-spin" />');
});

test('no smooth scrolling anywhere: a scroll is always instant', function () {
    foreach (tamSources() as $path) {
        expect(preg_match("/scroll-smooth|scroll-behavior:\s*smooth|behavior:\s*['\"]smooth/", (string) file_get_contents($path)))
            ->toBe(0, "Smooth scrolling in {$path}");
    }
});

// ---------------------------------------------------------------------------------------------
// Touch targets: one breakpoint
// ---------------------------------------------------------------------------------------------

test('the touch targets grow below md only, the breakpoint of the mobile sidebar', function () {
    // md is 48rem, 768px: the width where the sidebar becomes a sheet.
    expect(tamSource('js/hooks/use-mobile.tsx'))->toContain('const MOBILE_BREAKPOINT = 768;');

    foreach (tamSources() as $path) {
        expect(preg_match('/max-sm:(min-[hw]-11|after:)/', (string) file_get_contents($path)))
            ->toBe(0, "A touch target on another breakpoint in {$path}");
    }
});

// ---------------------------------------------------------------------------------------------
// Touch targets: the kit's controls
// ---------------------------------------------------------------------------------------------

test('buttons, fields, select triggers and list items are at least 44px on phones', function (string $path, string $pattern, array $classes) {
    expect(tamClasses(tamSource("js/components/ui/{$path}"), $pattern))->toContain(...$classes);
})->with([
    // Every size and variant, the icon buttons (the sidebar's trigger) included.
    'button' => ['button.tsx', 'const buttonVariants = cva\(\s*', ['max-md:min-h-11', 'max-md:min-w-11']],
    'input' => ['input.tsx', 'data-slot="input"\s*className=\{cn\(\s*', ['h-9', 'max-md:min-h-11']],
    'select trigger' => ['select.tsx', 'data-slot="select-trigger"[\s\S]*?className=\{cn\(\s*', ['data-[size=default]:h-9', 'max-md:min-h-11']],
    'select item' => ['select.tsx', 'data-slot="select-item"\s*className=\{cn\(\s*', ['max-md:min-h-11']],
    'menu item' => ['dropdown-menu.tsx', 'data-slot="dropdown-menu-item"[\s\S]*?className=\{cn\(\s*', ['max-md:min-h-11']],
    'sidebar menu button' => ['sidebar.tsx', 'const sidebarMenuButtonVariants = cva\(\s*', ['max-md:min-h-11']],
]);

test('the sidebar\'s trigger is an icon button, whose minimum size it does not undo', function () {
    $source = tamSource('js/components/ui/sidebar.tsx');
    expect(preg_match('/function SidebarTrigger\([\s\S]*?\n\}\n/', $source, $match))->toBe(1);

    expect($match[0])
        ->toContain('size="icon"')
        ->toContain('className={cn("h-7 w-7", className)}')
        ->not->toContain('min-h-')
        ->not->toContain('min-w-');
});

test('the close buttons of sheets and dialogs have an invisible 44px area on phones', function (string $path, string $close) {
    $classes = tamClasses(tamSource("js/components/ui/{$path}"), "<{$close} className=");

    // A 16px icon and no padding: 16 + 2 × 14 = 44. "absolute" places the area on the button.
    expect($classes)->toContain('absolute', 'max-md:after:absolute', 'max-md:after:-inset-3.5')
        ->not->toContain('overflow-hidden');
})->with([
    'sheet' => ['sheet.tsx', 'SheetPrimitive.Close'],
    'dialog' => ['dialog.tsx', 'DialogPrimitive.Close'],
]);

test('the booking sheet does not cut the close button\'s area', function () {
    // It scrolls (overflow-y-auto), so it clips at its edges: the area stays 2px inside them.
    expect(tamClasses(tamSource('js/components/booking-sheet.tsx'), '<SheetContent className='))
        ->toContain('overflow-y-auto')
        ->not->toContain('overflow-hidden');
    expect(tamClasses(tamSource('js/components/ui/sheet.tsx'), '<SheetPrimitive.Close className='))
        ->toContain('top-4', 'right-4');
});

test('the checkbox has an invisible 44px area, and its rows are 44px so the areas do not overlap', function () {
    expect(tamClasses(tamSource('js/components/ui/checkbox.tsx'), 'data-slot="checkbox"\s*className=\{cn\(\s*'))
        ->toContain('relative', 'size-4', 'max-md:after:absolute', 'max-md:after:-inset-3.5');

    expect(tamClasses(tamSource('js/components/booking-fields.tsx'), 'key=\{option\.id\}\s*className='))
        ->toContain('max-md:min-h-11');
    expect(tamClasses(tamSource('js/pages/auth/login.tsx'), '<div className=(?="[^"]*space-x-3)'))
        ->toContain('max-md:min-h-11');

    // Inside the booking sheet the form keeps 16px from the edges, more than the 14px of the area.
    expect(tamSource('js/components/booking-sheet.tsx'))->toContain('className="flex flex-col gap-6 px-4 pb-6"');
});

test('the show-password button is 44px wide on phones, and the field leaves it room', function () {
    $source = tamSource('js/components/password-input.tsx');

    expect($source)->toContain("className={cn('pr-10 max-md:pr-11', className)}");
    // As high as the field: inset-y-0 on a field that is 44px high on phones.
    expect(tamClasses($source, '<button\s*type="button"\s*onClick=\{[^}]*\}\s*className='))
        ->toContain('absolute', 'inset-y-0', 'max-md:min-w-11');
});

// ---------------------------------------------------------------------------------------------
// Touch targets: links and small controls of the application
// ---------------------------------------------------------------------------------------------

test('the touch target of a lone link is one constant, 44px high and wide', function () {
    // The width too: a short word ("Esci", "Log in") is narrower than 44px, by how much depends
    // on the font.
    expect(tamSource('js/lib/touch-target.ts'))
        ->toContain("export const touchTarget =\n    'max-md:inline-flex max-md:min-h-11 max-md:min-w-11 max-md:items-center';");
});

test('every lone link uses it, and only those: the links inside a sentence are left alone', function (string $path, int $uses) {
    $source = tamSource("js/{$path}");

    expect($source)->toContain("import { touchTarget } from '@/lib/touch-target';")
        ->and(substr_count($source, 'touchTarget') - 1)->toBe($uses);
})->with([
    // The logo, "dashboard" or "log in", the privacy link in the footer, and the site's email and
    // WhatsApp in the footer (#144, step 4).
    'public layout' => ['layouts/public-layout.tsx', 6],
    // WhatsApp and Instagram (externalLink), the way back.
    'provider page' => ['pages/providers/show.tsx', 2],
    // The provider, in the booking's details; WhatsApp for a question (#140).
    'booking page' => ['pages/bookings/show.tsx', 2],
    // WhatsApp when no time suits (#140).
    'booking form' => ['components/booking-sheet.tsx', 1],
    // The email in the controller's details; not the one in the "contact" sentence.
    'privacy' => ['pages/privacy.tsx', 1],
    // "Forgot password"; not "sign up" in the sentence below.
    'login' => ['pages/auth/login.tsx', 1],
    'verify email' => ['pages/auth/verify-email.tsx', 1],
    // The logo above the login forms.
    'login layout' => ['layouts/auth/auth-simple-layout.tsx', 1],
    'pagination' => ['components/pagination.tsx', 1],
    // Each category of the row on the home page (#144); the main action is the kit's Button.
    'home page' => ['pages/home.tsx', 1],
    // Each card of "Professionisti in evidenza" (#144, step 3).
    'featured provider card' => ['components/home/featured-provider-card.tsx', 1],
]);

test('the lone links get it where they are', function () {
    expect(tamSource('js/layouts/auth/auth-simple-layout.tsx'))
        ->toMatch("/<Link\\s+href=\\{home\\(\\)\\}\\s+className=\\{cn\\(\\s*'flex flex-col items-center gap-2 font-medium',\\s*touchTarget,\\s*\\)\\}\\s*>\\s*<div[^>]*>\\s*<AppLogoIcon/");
    // "Esci" is centred on the page, and narrower than its box: centred in the box too.
    expect(tamSource('js/pages/auth/verify-email.tsx'))
        ->toMatch("/href=\\{logout\\(\\)\\}\\s+className=\\{cn\\(\\s*'[^']*\\bmax-md:justify-center\\b[^']*',\\s*touchTarget,\\s*\\)\\}/");
    expect(tamSource('js/layouts/public-layout.tsx'))
        ->toContain('<Link href={login()} className={touchTarget}>')
        ->toMatch('/<Link\s+href=\{dashboard\(\)\}\s+className=\{touchTarget\}\s*>/')
        ->toMatch("/<Link\s+href=\{home\(\)\}\s+className=\{cn\(\s*'flex shrink-0 items-center',\s*touchTarget,?\s*\)\}\s*>/");
    expect(tamSource('js/pages/providers/show.tsx'))
        ->toContain("className: cn('underline underline-offset-4', touchTarget),");
    expect(tamSource('js/pages/privacy.tsx'))
        ->toMatch('/<dd>\s*\{controller\.email !== null \? \(\s*<a\s+href=\{`mailto:\$\{controller\.email\}`\}\s+className=\{cn\(\s*\'underline underline-offset-4\',\s*touchTarget,\s*\)\}/');
    // The links inside a sentence: the registration page has none of them.
    expect(tamSource('js/pages/auth/register.tsx'))->not->toContain('touchTarget');
    expect(tamSource('js/components/privacy-link.tsx'))->not->toContain('touchTarget');
});

test('the page numbers are 44px squares on phones, the appearance tabs 44px high', function () {
    expect(tamSource('js/components/pagination.tsx'))
        ->toMatch("/const linkClass = cn\\(\\s*'[^']*\\bmax-md:min-w-11\\b[^']*',\\s*touchTarget,\\s*\\);/")
        ->toMatch("/const linkClass = cn\\(\\s*'[^']*\\bmax-md:justify-center\\b/");
    expect(tamSource('js/components/appearance-tabs.tsx'))
        ->toMatch("/'flex items-center rounded-md px-3\\.5 py-1\\.5 transition-colors max-md:min-h-11'/");
});

test('the breadcrumb links have an invisible area in height only: the header keeps its height', function () {
    $classes = tamClasses(tamSource('js/components/breadcrumbs.tsx'), '<Link\s*href=\{item\.href\}\s*className=');

    // 20px of text + 2 × 12px = 44.
    expect($classes)->toContain('relative', 'max-md:after:absolute', 'max-md:after:inset-x-0', 'max-md:after:-inset-y-3')
        ->not->toContain('max-md:after:-inset-3.5');
});
