<?php

// Keyboard navigation (issue #55, phase 3). There is no JavaScript test runner: the sources are
// read, with the fragility of the other source tests (a reformatted line can break a pattern).

function kbnSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * Every React source of the application, the generated folders left out.
 *
 * @return list<string>
 */
function kbnSources(): array
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
// Tab order
// ---------------------------------------------------------------------------------------------

test('no element has a positive tabIndex: the order of the page is the order of the document', function () {
    foreach (kbnSources() as $path) {
        expect(preg_match('/tabIndex=\{\s*[1-9]|tabindex="[1-9]/i', (string) file_get_contents($path)))->toBe(0, "A positive tabIndex in {$path}");
    }
});

test('the login page goes field, password, "forgot password", remember, button, then the sign-up link', function () {
    $source = kbnSource('js/pages/auth/login.tsx');
    $order = ['id="email"', 'id="password"', "t('account.login.forgot')", 'id="remember"', 'type="submit"', "t('nav.register')"];
    $positions = array_map(fn (string $needle) => strpos($source, $needle), $order);

    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());
});

test('the show-password button is reached with Tab, with a translated name and its state', function () {
    expect(kbnSource('js/components/password-input.tsx'))
        ->not->toContain('tabIndex={-1}')
        ->toContain("aria-label={t('common.show_password')}")
        ->toContain('aria-pressed={showPassword}')
        ->toContain('type="button"');
});

// ---------------------------------------------------------------------------------------------
// Skip link
// ---------------------------------------------------------------------------------------------

test('the skip link is hidden until focused, translated, and moves the focus to the main content', function () {
    expect(kbnSource('js/components/skip-link.tsx'))
        ->toContain("export const MAIN_CONTENT_ID = 'main-content';")
        ->toContain('href={`#${MAIN_CONTENT_ID}`}')
        ->toMatch('/const main = document\.getElementById\(MAIN_CONTENT_ID\);\s*if \(main !== null\) \{\s*event\.preventDefault\(\);\s*main\.focus\(\);/')
        ->toContain('sr-only focus:not-sr-only')
        ->toContain("{t('common.skip_to_content')}");
    expect(__('common.skip_to_content', [], 'it'))->toBe('Salta al contenuto')
        ->and(__('common.skip_to_content', [], 'fr'))->toBe('Aller au contenu')
        ->and(__('common.skip_to_content', [], 'en'))->toBe('Skip to content');
});

test('the public layout starts with the skip link, and its main is the target', function () {
    expect(kbnSource('js/layouts/public-layout.tsx'))
        ->toMatch('/<div className="flex min-h-svh flex-col bg-background">\s*<SkipLink \/>\s*<header/')
        ->toMatch('/<main\s+id=\{MAIN_CONTENT_ID\}\s+tabIndex=\{-1\}/');
});

test('the provider\'s area starts with the skip link, before the sidebar, and its main is the target', function () {
    expect(kbnSource('js/layouts/app/app-sidebar-layout.tsx'))
        ->toMatch('/<AppShell variant="sidebar">\s*(\{\/\*[\s\S]*?\*\/\}\s*)?<SkipLink \/>\s*<AppSidebar \/>/')
        ->toMatch('/<AppContent\s+id=\{MAIN_CONTENT_ID\}\s+tabIndex=\{-1\}/');
    // AppContent gives its props to the <main> (SidebarInset is a <main> too).
    expect(kbnSource('js/components/app-content.tsx'))
        ->toContain('return <SidebarInset {...props}>{children}</SidebarInset>;');
    expect(kbnSource('js/components/ui/sidebar.tsx'))
        ->toMatch('/function SidebarInset\(\{ className, \.\.\.props \}: React\.ComponentProps<"main">\) \{\s*return \(\s*<main/');
});

test('the login layout has its main landmark', function () {
    expect(kbnSource('js/layouts/auth/auth-simple-layout.tsx'))
        ->toContain('<main className="w-full max-w-sm">')
        ->toContain('</main>');
});

// ---------------------------------------------------------------------------------------------
// Language selector
// ---------------------------------------------------------------------------------------------

test('the language selector is a Radix Select: no native select that changes page with the arrows', function () {
    $source = kbnSource('js/components/locale-switcher.tsx');

    expect($source)
        ->not->toContain('<select')
        ->not->toContain('onChange=')
        ->toContain("} from '@/components/ui/select';")
        ->toContain('onValueChange={(value) =>')
        // Its label points to its own trigger; each language in its own name and language.
        ->toContain('const id = useId();')
        ->toMatch("/<Label htmlFor=\\{id\\} className=\"sr-only\">\\s*\\{t\\('common\\.locale\\.label'\\)\\}/")
        ->toContain('<SelectTrigger id={id}')
        ->toMatch('/<SelectItem\s+key=\{option\.code\}\s+value=\{option\.code\}\s+lang=\{option\.code\}\s*>\s*\{option\.name\}/');
});

// ---------------------------------------------------------------------------------------------
// The provider's bookings list
// ---------------------------------------------------------------------------------------------

test('after an answer the focus goes to the card\'s title, or to the page title when the card is gone', function () {
    $source = kbnSource('js/pages/provider/bookings/index.tsx');

    expect($source)
        ->toMatch('/document\.getElementById\(cardTitleId\(bookingId\)\) \?\?\s*document\.getElementById\(PAGE_TITLE_ID\)\s*\)\?\.focus\(\);/')
        ->toMatch('/<h1\s+id=\{PAGE_TITLE_ID\}\s+tabIndex=\{-1\}/')
        ->toMatch('/<h2\s+id=\{cardTitleId\(booking\.id\)\}\s+tabIndex=\{-1\}/')
        // The confirmation, without a window.
        ->toContain('onSuccess={() => focusAfterAnswer(bookingId)}')
        // The rejection and the cancellation: the window gives the focus to the card, not to its
        // trigger, only after a success.
        ->toMatch('/onSuccess=\{\(\) => \{\s*answered\.current = true;\s*setOpen\(false\);\s*\}\}/')
        ->toMatch('/onCloseAutoFocus=\{\(event\) => \{\s*if \(answered\.current\) \{\s*answered\.current = false;\s*event\.preventDefault\(\);\s*focusAfterAnswer\(bookingId\);/');
});

test('the result of an answer is a toast, in a live region', function () {
    // Each answer flashes its toast…
    foreach (['Confirm', 'Reject', 'Cancel'] as $action) {
        expect((string) file_get_contents(app_path("Http/Controllers/ProviderBooking{$action}Controller.php")))
            ->toContain("Inertia::flash('toast', ['type' => 'success'");
    }

    // …shown by the Toaster of every page, whose region is polite.
    expect(kbnSource('js/app.tsx'))->toMatch('/\{app\}\s*<Toaster \/>/');
    expect((string) file_get_contents(base_path('node_modules/sonner/dist/index.mjs')))->toContain('"aria-live": "polite"');
});
