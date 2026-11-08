<?php

// Accessible names and structure (issue #55, phase 4). There is no JavaScript test runner: the
// sources are read, with the fragility of the other source tests.

function acnSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * Every React source of the application, the generated folders left out.
 *
 * @return list<string>
 */
function acnSources(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = $file->getPathname();

        if (preg_match('/\.tsx?$/', $path) === 1 && preg_match('#/resources/js/(routes|actions|wayfinder)/#', $path) !== 1) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

// ---------------------------------------------------------------------------------------------
// Links in a new tab
// ---------------------------------------------------------------------------------------------

test('every link that opens a new tab says so to screen readers, and has no opener', function () {
    $found = [];

    foreach (acnSources() as $path) {
        $source = (string) file_get_contents($path);
        expect($source)->not->toContain('window.open(');

        preg_match_all('/<a\b[\s\S]*?<\/a>/', $source, $links);

        foreach ($links[0] as $link) {
            $newTab = str_contains($link, 'target="_blank"') || str_contains($link, '{...externalLink}');

            if (! $newTab) {
                continue;
            }

            $found[] = basename($path);
            // The props of externalLink are checked below.
            if (str_contains($link, 'target="_blank"')) {
                expect($link)->toContain('rel="noopener noreferrer"');
            }
            expect($link)->toMatch('/<NewTabHint \/>\s*<\/a>$/', "No new-tab notice in {$path}: {$link}");
        }
    }

    expect(acnSource('js/pages/providers/show.tsx'))
        ->toMatch("/const externalLink = \\{\\s*target: '_blank',\\s*rel: 'noopener noreferrer',/");
    // The links of today: privacy; on the provider's page WhatsApp and Instagram twice (in the
    // actions under the header, and in the contacts); WhatsApp in the booking form and on the
    // booking's page (#140); the WhatsApp answer in the bookings list; the kit's footer links
    // (none configured yet); the site's WhatsApp in the footer of the public pages (#144), and in
    // the band for the professionals on the home page.
    sort($found);
    expect($found)->toBe(['booking-sheet.tsx', 'index.tsx', 'nav-footer.tsx', 'privacy-link.tsx', 'professionals-band.tsx', 'public-layout.tsx', 'show.tsx', 'show.tsx', 'show.tsx', 'show.tsx', 'show.tsx']);
});

test('the new-tab notice is one translated text, hidden on screen', function () {
    expect(acnSource('js/components/new-tab-hint.tsx'))
        ->toContain("return <span className=\"sr-only\"> {t('privacy.new_tab')}</span>;");
    expect(__('privacy.new_tab', [], 'it'))->toBe('(si apre in una nuova scheda)')
        ->and(__('privacy.new_tab', [], 'fr'))->toBe("(s'ouvre dans un nouvel onglet)")
        ->and(__('privacy.new_tab', [], 'en'))->toBe('(opens in a new tab)');
});

// ---------------------------------------------------------------------------------------------
// Buttons and images
// ---------------------------------------------------------------------------------------------

test('each "Book" button carries the name of its service, after the visible verb', function () {
    expect(acnSource('js/components/booking-sheet.tsx'))
        ->toMatch("/<Button className=\"max-md:w-full\">\\s*\\{t\\('booking\\.form\\.open'\\)\\}\\s*<span className=\"sr-only\"> \\{service\\.name\\}<\\/span>\\s*<\\/Button>/");
});

test('the provider\'s avatar is decorative next to the name, its initials are never read', function () {
    expect(acnSource('js/components/provider-avatar.tsx'))
        ->toContain('decorative = true,')
        ->toContain("alt={decorative ? '' : t('provider.avatar.alt', { name })}")
        ->toMatch('/<AvatarFallback aria-hidden="true">\s*\{getInitials\(name\)\}/');

    // Next to the name: the card of the list, the provider's page, the panel.
    foreach (['js/components/provider-card.tsx', 'js/pages/providers/show.tsx', 'js/pages/dashboard.tsx'] as $path) {
        expect(acnSource($path))
            ->toMatch('/<ProviderAvatar\s+name=\{[a-z.]+\}\s+avatarUrl=\{[a-z_.]+\}(\s+className="[^"]*")?\s*\/>/')
            ->not->toContain('decorative=');
    }

    // Alone above the upload field: its own alternative text.
    expect(acnSource('js/pages/provider/edit.tsx'))->toMatch('/<ProviderAvatar[^>]*decorative=\{false\}\s*\/>/');
    expect(__('provider.avatar.alt', ['name' => 'Salone Awa'], 'it'))->toBe('Foto di Salone Awa')
        ->and(__('provider.avatar.alt', ['name' => 'Salone Awa'], 'fr'))->toBe('Photo de Salone Awa')
        ->and(__('provider.avatar.alt', ['name' => 'Salone Awa'], 'en'))->toBe('Photo of Salone Awa');
});

test('the user\'s avatar in the menu is decorative too: the name is written next to it', function () {
    expect(acnSource('js/components/user-info.tsx'))
        ->toContain('<AvatarImage src={user.avatar} alt="" />')
        ->toMatch('/<AvatarFallback\s+aria-hidden="true"/');
});

// ---------------------------------------------------------------------------------------------
// Structure and messages
// ---------------------------------------------------------------------------------------------

test('the headings of the provider\'s area go h1 first, one h1, and never skip a level', function (string $page) {
    preg_match_all('/<h([1-6])\b/', acnSource("js/pages/{$page}.tsx"), $matches);
    $levels = array_map('intval', $matches[1]);

    expect($levels)->not->toBeEmpty()
        ->and($levels[0])->toBe(1)
        ->and(array_count_values($levels)[1])->toBe(1);

    foreach (array_slice($levels, 1) as $position => $level) {
        expect($level)->toBeLessThanOrEqual($levels[$position] + 1, "A skipped heading level in {$page}");
    }
})->with([
    'dashboard', 'provider/setup', 'provider/edit', 'provider/bookings/index', 'provider/bookings/create',
    'provider/services/index', 'provider/services/edit', 'provider/services/options', 'provider/availability/index',
    'providers/index', 'providers/show', 'bookings/show',
]);

test('the privacy page has one h1, an h2 per section and h3 inside the sections', function () {
    // Read apart: its Section component, written above the page, holds the h2.
    $source = acnSource('js/pages/privacy.tsx');

    expect(substr_count($source, '<h1'))->toBe(1)
        ->and($source)->toMatch('/function Section\(\{ title, children \}[\s\S]*?<section[^>]*>\s*<h2 className="text-lg font-semibold">\{title\}<\/h2>/')
        ->and(substr_count($source, '<h2'))->toBe(1)
        ->and(preg_match_all('/<Section title=\{t\(\'privacy\.[a-z]+\.heading\'\)\}>/', $source))->toBe(8)
        // The three h3 are inside the "data" section.
        ->and($source)->toMatch("/<Section title=\\{t\\('privacy\\.data\\.heading'\\)\\}>\\s*<h3/");
});

test('the status messages of the login pages are in a live region that is always on the page', function (string $page, string $message) {
    expect(acnSource("js/pages/{$page}.tsx"))
        ->toMatch('/<div role="status">\s*\{'.preg_quote($message, '/').' && \(\s*<p className="[^"]*text-green-700/');
})->with([
    'login' => ['auth/login', 'status'],
    'forgot password' => ['auth/forgot-password', 'status'],
    'verify email' => ['auth/verify-email', "status === 'verification-link-sent'"],
    'profile' => ['settings/profile', "status ===\n                                                'verification-link-sent'"],
]);

// ---------------------------------------------------------------------------------------------
// The language of the document and the toasts
// ---------------------------------------------------------------------------------------------

test('<html lang> and the toasts\' label follow every page, the first one included', function () {
    expect(acnSource('js/lib/document-language.ts'))
        ->toMatch("/router\\.on\\('navigate', \\(event\\) => \\{\\s*const \\{ locale, translations \\} = event\\.detail\\.page\\.props;\\s*document\\.documentElement\\.lang = locale\\.replace\\('_', '-'\\);\\s*notificationsLabel = translations\\['common\\.notifications'\\];/");
    // Before createInertiaApp: the "navigate" of the first page is not missed.
    $app = acnSource('js/app.tsx');
    // strpos() gives false for a missing call, and false is "less" than any position: check both.
    $call = strpos($app, "\nsyncDocumentLanguage();\n");
    $start = strpos($app, 'createInertiaApp({');
    expect($call)->toBeInt()
        ->and($start)->toBeInt()
        ->and($call)->toBeLessThan($start);
    expect(acnSource('js/components/ui/sonner.tsx'))
        ->toContain('const notificationsLabel = useNotificationsLabel();')
        ->toContain('containerAriaLabel={notificationsLabel}');
    expect(__('common.notifications', [], 'it'))->toBe('Notifiche')
        ->and(__('common.notifications', [], 'fr'))->toBe('Notifications')
        ->and(__('common.notifications', [], 'en'))->toBe('Notifications');
});

// ---------------------------------------------------------------------------------------------
// The final check (axe and the accessibility tree)
// ---------------------------------------------------------------------------------------------

test('the side menu is a navigation landmark, on the desktop and in the phone\'s panel', function () {
    $source = acnSource('js/components/ui/sidebar.tsx');

    // Desktop: the inner container.
    expect($source)->toMatch('/<nav\s+aria-label=\{t\("nav\.sidebar"\)\}\s+data-sidebar="sidebar"/')
        ->not->toContain('<div
          data-sidebar="sidebar"');
    // Phone: inside the panel, around the content.
    expect($source)->toMatch('/<nav aria-label=\{t\("nav\.sidebar"\)\} className="flex h-full w-full flex-col">\s*\{children\}\s*<\/nav>\s*<\/SheetContent>/');

    // A name of its own: not the one of the breadcrumb, of the settings or of the pages.
    $names = array_map(fn (string $key) => __($key, [], 'it'), ['nav.sidebar', 'common.breadcrumb', 'nav.settings', 'common.pagination.label']);
    expect(array_unique($names))->toHaveCount(4);
    expect(__('nav.sidebar', [], 'it'))->toBe('Menu laterale')
        ->and(__('nav.sidebar', [], 'fr'))->toBe('Menu latéral')
        ->and(__('nav.sidebar', [], 'en'))->toBe('Side menu');
});

test('the hidden title of the phone\'s menu is inside its panel: nothing is left on the page when it is closed', function () {
    $source = acnSource('js/components/ui/sidebar.tsx');

    expect($source)->toMatch('/<SheetContent\s+data-sidebar="sidebar"[\s\S]*?>\s*(\{\/\*[\s\S]*?\*\/\}\s*)?<SheetHeader className="sr-only">\s*<SheetTitle>\{t\("nav\.sidebar"\)\}<\/SheetTitle>\s*<SheetDescription>\{t\("nav\.sidebar_description"\)\}<\/SheetDescription>\s*<\/SheetHeader>/')
        ->not->toMatch('/<Sheet open=\{openMobile\}[^>]*>\s*<SheetHeader/');
});

test('the logo of the login pages is named after the app, not after the page', function () {
    $source = acnSource('js/layouts/auth/auth-simple-layout.tsx');

    expect($source)
        ->toContain('const { name } = usePage().props;')
        ->toContain('<span className="sr-only">{name}</span>')
        ->not->toContain('<span className="sr-only">{title}</span>')
        // The title stays the h1.
        ->toContain('<h1 className="text-xl font-medium">{title}</h1>');
    // As the logo of the public header.
    expect(acnSource('js/components/app-logo.tsx'))->toContain('const { name } = usePage().props;');
});

test('on the provider\'s page "Orari" and "Contatti" are h2, after the services: h1, h2, h3, then h2', function () {
    $source = acnSource('js/pages/providers/show.tsx');

    foreach (['hours', 'contacts'] as $key) {
        expect($source)->toMatch("/<CardTitle>\\s*<h2>\\{t\\('providers\\.show\\.{$key}'\\)\\}<\\/h2>\\s*<\\/CardTitle>/");
    }

    // The order on the page: the h3 of the services come from ServiceItem, inside the section of
    // the services, before the aside.
    $section = strpos($source, "{t('providers.show.services')}");
    $items = strpos($source, '<ServiceItem');
    $aside = strpos($source, '<aside');
    $hours = strpos($source, "<h2>{t('providers.show.hours')}</h2>");
    $contacts = strpos($source, "<h2>{t('providers.show.contacts')}</h2>");
    expect([$section, $items, $aside, $hours, $contacts])->each->toBeInt();
    expect($section)->toBeLessThan($items)
        ->and($items)->toBeLessThan($aside)
        ->and($aside)->toBeLessThan($hours)
        ->and($hours)->toBeLessThan($contacts);
    expect($source)->toMatch('/function ServiceItem\([\s\S]*<h3 className="font-medium">\{service\.name\}<\/h3>/');
    expect(substr_count($source, '<h1'))->toBe(1)
        ->and(substr_count($source, '<h2'))->toBe(3);
});
