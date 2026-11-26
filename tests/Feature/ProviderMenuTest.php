<?php

use Illuminate\Support\Facades\Process;

// The menu of the provider's area (#244, docs/accessibility.md, « Il tasto Menu in basso »): "Menu"
// written at the top, and on phones a second "Menu" at the bottom right that opens the same panel;
// the panel closes by itself when a page is chosen, with its X visible, and the focus goes to the
// content after such a closing. The sources are read; the panel itself is the kit's Sheet.

function pmSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

// ---------------------------------------------------------------------------------------------
// The two buttons
// ---------------------------------------------------------------------------------------------

test('at the top: "Menu" written, the kit\'s outline button with its 44px, the same toggle of the kit', function () {
    $header = pmSource('js/components/app-sidebar-header.tsx');

    expect($header)
        ->toMatch('/<Button\s+type="button"\s+variant="outline"\s+data-menu-top=""\s+onClick=\{toggleSidebar\}\s+className="-ml-1"\s*>\s*<MenuIcon aria-hidden="true" \/>\s*\{t\(\'nav\.menu\'\)\}\s*<\/Button>/')
        ->toContain('const { toggleSidebar } = useSidebar();')
        // Not the icon alone of before: no SidebarTrigger, no icon size, no 28px.
        ->not->toContain('SidebarTrigger')
        ->not->toContain('size="icon"')
        ->not->toContain('h-7')
        ->not->toContain('w-7')
        // Its name is the visible text (LocaleEverywhereTest): no aria-label, no hidden text.
        ->not->toMatch('/aria-label(?!ledby)|sr-only/');
    // The kit's Button gives 44px below 768 px (TouchAndMotionTest): nothing here takes it away.
    expect($header)->not->toMatch('/min-h-|min-w-|max-md:h-/');
});

test('at the bottom on phones: fixed only below 768 px, out of the safe areas, under the panel, "Menu" written, no movement', function () {
    $button = pmSource('js/components/provider-menu-button.tsx');

    expect($button)
        ->toContain('data-provider-menu=""')
        ->toContain('className="max-md:fixed max-md:right-[max(1rem,env(safe-area-inset-right))] max-md:bottom-[max(1rem,env(safe-area-inset-bottom))] max-md:z-40 md:hidden"')
        ->toMatch('/<Button\s+type="button"\s+onClick=\{toggleSidebar\}\s+className="h-12 gap-2 rounded-full px-5 shadow-md forced-colors:border"\s*>\s*<MenuIcon aria-hidden="true" className="size-5" \/>\s*\{t\(\'nav\.menu\'\)\}/')
        ->toContain('const { toggleSidebar } = useSidebar();')
        // Fixed only with max-md: (no "fixed" class without the prefix).
        ->not->toMatch('/["\'` ]fixed[ "\'`]/')
        // Under the panel (the kit's Sheet is z-50), never at its level.
        ->not->toMatch('/className="[^"]*z-50/')
        ->not->toMatch('/aria-label(?!ledby)|sr-only/')
        ->not->toMatch('/\banimate-|\btransition|motion-/');
    // Mounted by the layout of the area, after the content: the last stop of Tab.
    expect(pmSource('js/layouts/app/app-sidebar-layout.tsx'))
        ->toMatch('/<\/AppContent>\s*\{\/\*[^*]*\*\/\}\s*<ProviderMenuButton \/>/');
    expect(__('nav.menu', [], 'it'))->toBe('Menu')
        ->and(__('nav.menu', [], 'fr'))->toBe('Menu')
        ->and(__('nav.menu', [], 'en'))->toBe('Menu');
});

test('app.css: below 768 px the room at the end of the page, and the button hidden while a field is being written in', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));
    preg_match('/@media \(width < 48rem\) \{\s*html:has\(\[data-provider-menu\]\)[\s\S]*?\n\}\n/', $css, $block);

    expect($block)->toHaveCount(1);
    expect($block[0])
        ->toMatch('/html:has\(\[data-provider-menu\]\) \{\s*scroll-padding-bottom: 8rem;\s*\}/')
        ->toMatch('/body:has\(\[data-provider-menu\]\) \{\s*padding-bottom: calc\(8rem \+ env\(safe-area-inset-bottom\)\);\s*\}/')
        // Text fields only: not boxes, radios, buttons or files.
        ->toMatch("/body:has\\(\\s*input:not\\(\\s*\\[type='checkbox'\\],\\s*\\[type='radio'\\],\\s*\\[type='button'\\],\\s*\\[type='submit'\\],\\s*\\[type='reset'\\],\\s*\\[type='file'\\],\\s*\\[type='range'\\],\\s*\\[type='color'\\]\\s*\\):focus,\\s*textarea:focus,\\s*select:focus\\s*\\)\\s*\\[data-provider-menu\\] \\{\\s*display: none;\\s*\\}/");
});

test('fixed only in the two groups of buttons that stay in view: the home\'s and this one', function () {
    $allowed = ['components/home/floating-actions.tsx', 'components/provider-menu-button.tsx', 'components/skip-link.tsx'];
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js')));
    foreach ($files as $file) {
        $path = str_replace(resource_path('js').'/', '', $file->getPathname());
        if (! str_ends_with($path, '.tsx') || str_starts_with($path, 'components/ui/') || in_array($path, $allowed, true)) {
            continue;
        }
        // A "fixed" class, with or without a prefix (the word may stay in a comment).
        if (preg_match('/["\'` ](?:[a-z-]+:)*fixed[ "\'`]/', (string) file_get_contents($file->getPathname())) === 1) {
            $found[] = $path;
        }
    }

    expect($found)->toBe([]);
    // The link to the content only while it has the focus.
    expect(pmSource('js/components/skip-link.tsx'))->toContain('focus:fixed')->not->toMatch('/["\'` ]fixed[ "\'`]/');
});

// ---------------------------------------------------------------------------------------------
// The panel closes by itself
// ---------------------------------------------------------------------------------------------

test('a page chosen in the panel closes it at once, also the page already shown, and the logo too', function () {
    expect(pmSource('js/components/nav-main.tsx'))
        ->toContain('const { isMobile, setOpenMobile } = useSidebar();')
        ->toMatch('/const closeMenu = \(\) => \{\s*if \(isMobile\) \{\s*markClosedByNavigation\(\);\s*setOpenMobile\(false\);\s*\}\s*\};/')
        ->toMatch('/<Link\s+href=\{item\.href\}\s+prefetch\s+onClick=\{closeMenu\}/');
    expect(pmSource('js/components/app-sidebar.tsx'))
        ->toMatch('/<Link\s+href=\{dashboard\(\)\}\s+prefetch\s+onClick=\{\(\) => \{[\s\S]*?if \(isMobile\) \{\s*markClosedByNavigation\(\);\s*setOpenMobile\(false\);\s*\}/');
});

test('every navigation of Inertia closes it (Back, Forward, the links inside), and stops listening with the layout', function () {
    expect(pmSource('js/layouts/app/app-sidebar-layout.tsx'))
        ->toMatch('/useEffect\(\(\) => \{\s*return router\.on\(\'navigate\', \(\) => \{\s*if \(open\.current\) \{\s*markClosedByNavigation\(\);\s*setOpenMobile\(false\);\s*\}\s*\}\);\s*\}, \[setOpenMobile\]\);/')
        ->toMatch('/useEffect\(\(\) => \{\s*open\.current = openMobile;\s*\}, \[openMobile\]\);/')
        ->toContain('<CloseMenuOnNavigate />');
});

test('the X of the panel is visible again, with room in the header; after a closing for a page the focus goes to the content', function () {
    $sidebar = pmSource('js/components/ui/sidebar.tsx');

    expect($sidebar)
        ->not->toContain('[&>button]:hidden')
        ->toContain('[&_[data-sidebar=header]]:pr-12')
        ->toMatch('/if \(consumeClosedByNavigation\(\)\) \{\s*event\.preventDefault\(\)\s*document\.getElementById\(MAIN_CONTENT_ID\)\?\.focus\(\{ preventScroll: true \}\)\s*return\s*\}/');
    // The X of the kit's Sheet, translated, with its 44px area (TouchAndMotionTest).
    expect(pmSource('js/components/ui/sheet.tsx'))->toContain('{t("common.close")}');
});

test('audit #175, E: the panel opens on the first link of its navigation, after keeping who opened it', function () {
    $sidebar = pmSource('js/components/ui/sidebar.tsx');

    expect($sidebar)
        ->toContain('const opener = React.useRef<HTMLElement | null>(null)')
        ->toContain('const nav = React.useRef<HTMLElement>(null)')
        ->toContain('<nav ref={nav} aria-label={t("nav.sidebar")}')
        // Who had the focus is read first, then the focus moves: the other order would keep the link.
        ->toMatch('/onOpenAutoFocus=\{\(event\) => \{\s*opener\.current = document\.activeElement instanceof HTMLElement \? document\.activeElement : null\s*const first = nav\.current\?\.querySelector<HTMLElement>\("a\[href\]"\)\s*if \(first\) \{\s*event\.preventDefault\(\)\s*first\.focus\(\)\s*\}\s*\}\}/');
});

test('audit #175, D: closed with the X, outside or Esc, the focus goes back to the "Menu" that opened the panel', function () {
    $sidebar = pmSource('js/components/ui/sidebar.tsx');

    expect($sidebar)
        // Read and forgotten at every closing, also a closing for a page.
        ->toMatch('/onCloseAutoFocus=\{\(event\) => \{\s*const target = opener\.current\s*opener\.current = null\s*if \(consumeClosedByNavigation\(\)\)/')
        // Not for a page: Radix's own return is stopped, the button kept gets the focus, if it is
        // still on the page and shown; otherwise "Menu" at the top.
        ->toMatch('/return\s*\}\s*event\.preventDefault\(\)\s*if \(target\?\.isConnected && target\.getClientRects\(\)\.length > 0\) \{\s*target\.focus\(\)\s*\} else \{\s*document\.querySelector<HTMLElement>\("\[data-menu-top\]"\)\?\.focus\(\)\s*\}\s*\}\}/')
        // The wrong comment of #250 is gone: Radix gives the focus back only to a DialogTrigger.
        ->not->toContain('Radix gives it back to the "Menu" button that opened the panel')
        ->toContain('Radix gives the focus back only to a');
    expect(pmSource('js/lib/menu-navigation.ts'))
        ->not->toContain('nothing is marked, and Radix gives the focus back to the button.')
        ->toContain('Radix alone would give it only to a DialogTrigger');
    // The fallback exists: the button at the top carries the mark (one only).
    expect(substr_count(pmSource('js/components/app-sidebar-header.tsx'), 'data-menu-top=""'))->toBe(1);
});

test('the mark of a closing for a page is read once', function () {
    $script = sys_get_temp_dir().'/griot-pm-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path('js/lib/menu-navigation.ts');
    file_put_contents($script, "import * as m from '{$helper}';
const before = m.consumeClosedByNavigation();
m.markClosedByNavigation();
console.log(JSON.stringify([before, m.consumeClosedByNavigation(), m.consumeClosedByNavigation()]));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());
    // Nothing marked: Radix's own return to the button. Marked: once, then nothing again.
    expect(json_decode($result->output(), true))->toBe([false, true, false]);
});

// ---------------------------------------------------------------------------------------------
// The document
// ---------------------------------------------------------------------------------------------

test('the third exception is written, with its rules, and the checklist says three', function () {
    $doc = (string) file_get_contents(base_path('docs/accessibility.md'));

    expect($doc)
        ->toContain('- **Il tasto Menu in basso** (la terza eccezione alla regola « niente `sticky` », #244)')
        ->toContain('**solo sotto 768 px** (`max-md:fixed`, `md:hidden`)')
        ->toContain('**nascosto mentre si scrive**')
        ->toContain('**il pannello si chiude da solo**')
        ->toContain('la prima delle tre eccezioni alla regola « niente `sticky` »')
        ->toContain('e il tasto « Menu » in basso nell\'area del professionista sotto 768 px (« Il tasto Menu in basso »): sono tre');
});

test('the reduced audit #175 of 26/11/2026 is written: results, the three errors, false positives, the debts', function () {
    $doc = (string) file_get_contents(base_path('docs/accessibility.md'));
    preg_match('/\n## 6\. Limiti e debiti noti\n([\s\S]*?)\n## 7\./', $doc, $limits);
    $section6 = $limits[1] ?? '';

    // Section 5: the run, its tools and its results.
    expect($doc)
        ->toContain('axe-core, audit ridotto (#175, 26/11/2026)')
        ->toContain('Playwright 1.65.1 con Chromium, axe-core 4.14.1')
        ->toContain('112 combinazioni')
        ->toContain('**0 violazioni nelle pagine pubbliche.**')
        ->toContain('`aria-dialog-name` sul dialog « Aggiungi »')
        ->toContain('`<html lang>` fermo sulla lingua vecchia dopo il cambio dal selettore')
        ->toContain('alla chiusura finiva sul `body`')
        ->toContain('Tasti « Menu » e pannello (26/11/2026)')
        // The panel's focus, as it is now.
        ->toContain('All\'apertura il focus va sul primo link del menu (audit #175);')
        ->toContain('**Dopo**: l\'audit #175 è fatto il 26/11/2026')
        ->not->toContain('l\'audit #175 resta in coda');
    // Section 6: the false positives explained, the debts kept.
    expect($section6)
        ->toContain('axe non emula i colori forzati')
        ->toContain('quando il testo sta sopra una foto o un livello')
        ->toContain('Il 26/11/2026 lo stesso, 3 nodi per pagina')
        ->toContain('Da confermare con il vero alto contrasto di Windows.')
        ->toContain('è **rimandato** (decisione del 26/11/2026, dopo l\'audit #175): circa 40 campi in 12-13 file')
        ->not->toContain('si valuta nell\'audit #175');
    // Section 8: the two rules learnt.
    expect($doc)->toContain('il titolo senza un `id` scritto a mano (Radix lo collega da solo)');
});
