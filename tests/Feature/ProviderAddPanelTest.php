<?php

use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use Inertia\Testing\AssertableInertia as Assert;

// #53: in the provider's services and hours, the "add" form stays in view next to the list from xl
// (1280 px) and opens in a dialog below; after a save the new or changed item is brought into view
// with the focus on its title (docs/accessibility.md, « La scheda « Aggiungi » che resta in vista »).
// The pages are read in their source: there is no JavaScript test runner.

beforeEach(function () {
    app()->setLocale('it');
});

function apSource(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

const AP_PAGES = [
    'services' => 'resources/js/pages/provider/services/index.tsx',
    'availability' => 'resources/js/pages/provider/availability/index.tsx',
];

const AP_PANEL = 'resources/js/components/add-panel.tsx';

// ---------------------------------------------------------------------------------------------
// The order of the services
// ---------------------------------------------------------------------------------------------

test('the provider sees the most recent service first; the public page keeps its order by name', function () {
    $provider = Provider::factory()->published()->create();
    $category = Category::factory()->create();
    foreach (['Zeta', 'Alfa', 'Media'] as $name) {
        Service::factory()->for($provider)->for($category)->create(['name' => $name]);
    }

    $this->actingAs($provider->user)->get(route('provider.services.index'))
        ->assertInertia(fn (Assert $page) => $page->where('services', fn ($services) => collect($services)->pluck('name')->all() === ['Media', 'Alfa', 'Zeta']));

    // The customer's page: grouped by category, by name inside, as before.
    $this->get(route('providers.show', $provider->slug))
        ->assertInertia(fn (Assert $page) => $page->where('provider.service_groups.0.services', fn ($services) => collect($services)->pluck('name')->all() === ['Alfa', 'Media', 'Zeta']));

    // Two separate orders: the provider's list in its controller, the public one in the model.
    expect(apSource('app/Http/Controllers/ProviderServiceIndexController.php'))->toContain("->orderByDesc('id')->get();")
        ->and(apSource('app/Models/Provider.php'))->toContain("'services' => fn (\$services) => \$services->active()->orderBy('name'),");
});

// ---------------------------------------------------------------------------------------------
// After a save: the server names the element to show
// ---------------------------------------------------------------------------------------------

test('adding a service names its card; changing one goes back to the list on its card', function () {
    $provider = Provider::factory()->create();
    $category = Category::factory()->create();
    $form = ['name' => 'Box braids', 'category_id' => $category->id, 'price' => '60', 'duration_max' => 180];

    $this->actingAs($provider->user)->post(route('provider.services.store'), $form)
        ->assertRedirect(route('provider.services.index'))
        ->assertInertiaFlash('focus', 'service-'.Service::query()->sole()->id);

    $service = Service::query()->sole();
    $this->actingAs($provider->user)->put(route('provider.services.update', $service->id), [...$form, 'name' => 'Treccine'])
        ->assertRedirect(route('provider.services.index'))
        ->assertInertiaFlash('focus', 'service-'.$service->id);
    // No #anchor: the flash does the work, and a reload of the list focuses nothing.
    $location = (string) $this->actingAs($provider->user)->put(route('provider.services.update', $service->id), $form)->headers->get('Location');
    expect($location)->toBe(route('provider.services.index'))->not->toContain('#');
});

test('adding a slot names its day; a refused one names nothing', function () {
    $provider = Provider::factory()->create();

    $this->actingAs($provider->user)->post(route('provider.availability.store'), ['weekday' => Weekday::Wednesday->value, 'start_time' => '09:00', 'end_time' => '13:00'])
        ->assertRedirect(route('provider.availability.index'))
        ->assertInertiaFlash('focus', 'availability-day-3');

    // Overlapping, for another provider (a fresh session: the flash of a request lives until the
    // next one): an error, no element to show.
    $other = Provider::factory()->create();
    Availability::factory()->for($other)->create(['weekday' => Weekday::Friday, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);
    $this->flushSession();
    $this->actingAs($other->user)->post(route('provider.availability.store'), ['weekday' => Weekday::Friday->value, 'start_time' => '10:00', 'end_time' => '11:00'])
        ->assertSessionHasErrors('start_time')
        ->assertInertiaFlashMissing('focus');
});

// ---------------------------------------------------------------------------------------------
// The panel
// ---------------------------------------------------------------------------------------------

test('sticky only from xl, always with a maximum height and its own scroll; nothing fixed, nothing that moves', function () {
    $panel = apSource(AP_PANEL);

    expect($panel)
        ->toContain("export const STICKY_PANEL =\n    'xl:sticky xl:top-4 xl:max-h-[calc(100svh-2rem)] xl:overflow-y-auto';")
        ->toContain('<aside aria-labelledby={`${id}-title`} className={STICKY_PANEL}>')
        ->and(substr_count($panel, 'sticky'))->toBe(substr_count($panel, 'xl:sticky'))
        ->and(substr_count($panel, 'xl:sticky'))->toBe(1);

    foreach ([AP_PANEL, ...AP_PAGES] as $file) {
        $source = apSource($file);
        // Every "sticky" carries the xl: prefix, and only in the panel.
        expect(preg_match_all('/(?<![\w:-])sticky\b/', str_replace('STICKY_PANEL', '', $source)))->toBe(0, $file)
            // No "fixed" class (the word may stay in a comment).
            ->and($source)->not->toMatch('/["\'` ]fixed[ "\'`]/')
            ->not->toMatch('/\banimate-|\btransition\b|motion-/');
    }
    foreach (AP_PAGES as $file) {
        expect(apSource($file))->not->toContain('sticky');
    }
});

test('two columns from xl, the list first in the DOM and the form after it', function (string $page) {
    $source = apSource(AP_PAGES[$page]);

    expect(apSource(AP_PANEL))->toContain("export const TWO_COLUMNS =\n    'flex flex-col gap-6 xl:grid xl:grid-cols-[minmax(0,42rem)_24rem] xl:items-start xl:justify-start';");

    $columns = (int) strpos($source, '<div className={TWO_COLUMNS}>');
    $list = (int) strpos($source, '<ul className="flex flex-col gap-4">', $columns);
    $card = (int) strpos($source, 'mode="card"', $columns);
    expect($columns)->toBeGreaterThan(0)
        ->and($list)->toBeGreaterThan($columns)
        ->and($card)->toBeGreaterThan($list)
        // The dialog's button is at the top, before the columns.
        ->and((int) strpos($source, 'mode="dialog"'))->toBeLessThan($columns);
})->with(['services', 'availability']);

/**
 * The value of a string constant of add-panel.tsx.
 */
function apConstant(string $name): string
{
    expect(preg_match("/export const {$name} =\\s*'([^']*)';/", apSource(AP_PANEL), $match))->toBe(1, $name);

    return $match[1];
}

test('the list track is at most 42rem and the group stays on the left, under the title', function () {
    $columns = apConstant('TWO_COLUMNS');

    expect(preg_match('/\bxl:grid-cols-\[minmax\(0,(\d+(?:\.\d+)?)rem\)_24rem\]/', $columns, $track))->toBe(1, $columns)
        ->and($track[1])->toBe('42')
        ->and(explode(' ', $columns))->toContain('xl:justify-start')
        // The gap between the columns stays 1.5rem, the same at every width.
        ->and($columns)->not->toContain('1fr')
        ->not->toMatch('/\bxl:gap-/')
        ->not->toContain('mx-auto')
        ->not->toContain('justify-center');
});

test('the 42rem of the grid is the max-w-2xl of the list and of the title', function () {
    // Tailwind's max-w-2xl is 42rem (--container-2xl).
    $tailwind = ['max-w-xl' => '36', 'max-w-2xl' => '42', 'max-w-3xl' => '48', 'max-w-4xl' => '56'];
    preg_match('/minmax\(0,(\d+(?:\.\d+)?)rem\)/', apConstant('TWO_COLUMNS'), $track);

    foreach (['LIST_COLUMN', 'PAGE_HEADER_WIDTH'] as $name) {
        preg_match('/\b(max-w-[a-z0-9]+)\b/', apConstant($name), $width);
        $class = $width[1] ?? '';

        expect($tailwind)->toHaveKey($class)
            ->and($tailwind[$class] ?? null)->toBe($track[1] ?? null, "{$name}: {$class}");
    }
    expect(apConstant('LIST_COLUMN'))->toBe('flex max-w-2xl min-w-0 flex-col gap-4')
        ->and(apConstant('PAGE_HEADER_WIDTH'))->toBe('max-w-2xl');
});

test('the two pages write no width of their own: only the constants of add-panel.tsx', function (string $page) {
    $source = apSource(AP_PAGES[$page]);

    // One needle per call: toContain() takes several needles, not a message.
    expect($source)->not->toContain('max-w-2xl');
    expect($source)->not->toMatch('/\bmax-w-(?:xs|sm|md|lg|xl|[2-7]xl|\[)/');
    // The grid of the columns too (the form's own @xs:grid-cols-2 is not a page width).
    expect($source)->not->toContain('xl:grid');
    expect($source)
        ->toContain('<header className={')
        ->toContain('PAGE_HEADER_WIDTH')
        ->toContain('<div className={LIST_COLUMN}>')
        ->toContain('<div className={TWO_COLUMNS}>');
})->with(['services', 'availability']);

test('one form at a time: the card from xl, the dialog below, chosen by the same 80rem as xl', function () {
    expect(apSource(AP_PANEL))
        ->toMatch('/const wide = useWideLayout\(\);\s*\/\/ Never the two forms together\.\s*if \(mode === \'card\' \? !wide : wide\) \{\s*return null;\s*\}/')
        ->toMatch('/export function AddPanelLink[\s\S]*?const wide = useWideLayout\(\);\s*if \(!wide\) \{\s*return null;\s*\}/');
    expect(apSource('resources/js/hooks/use-wide-layout.ts'))
        ->toContain("export const WIDE_LAYOUT_QUERY = '(width >= 80rem)';")
        ->toContain('useSyncExternalStore(')
        ->toContain('() => mql?.matches ?? false,');

    // Each form is written once, and only inside the two panels: the ids of its fields are unique.
    $services = apSource(AP_PAGES['services']);
    expect(substr_count($services, 'id="service-name"'))->toBe(1)
        ->and(substr_count($services, '<AddServiceForm'))->toBe(2)
        ->and(preg_match_all('/<AddPanel\s/', $services))->toBe(2);
    $hours = apSource(AP_PAGES['availability']);
    expect(substr_count($hours, 'idPrefix="new-availability"'))->toBe(1)
        ->and(substr_count($hours, '<AddAvailabilityForm'))->toBe(2)
        ->and(preg_match_all('/<AddPanel\s/', $hours))->toBe(2);
});

test('the dialog: its title, its own height and scroll, the kit\'s Esc and focus, no aria-label', function () {
    $panel = apSource(AP_PANEL);

    expect($panel)
        ->toMatch('/<DialogContent\s+aria-describedby=\{undefined\}\s+className="max-h-\[calc\(100svh-2rem\)\] overflow-y-auto"/')
        // Audit #175, A: no id of our own on the dialog's title, Radix names the dialog with its own.
        ->toContain('<DialogTitle>{title}</DialogTitle>')
        ->not->toMatch('/<DialogTitle\s+id=/')
        ->toMatch('/<DialogTrigger asChild>\s*<Button className=\{`self-start \$\{wrappingLabel\}`\}>\s*<Plus aria-hidden="true" \/>\s*\{openLabel\}/')
        ->not->toMatch('/aria-label(?!ledby)/');
    foreach (AP_PAGES as $file) {
        expect(apSource($file))->not->toMatch('/aria-label(?!ledby)/');
    }
});

test('from xl a link at the top of the list takes the focus to the form\'s title', function (string $page, string $key) {
    expect(apSource(AP_PAGES[$page]))->toMatch("/<AddPanelLink\\s+id=\\{ADD_[A-Z]+\\}\\s+label=\\{t\\('{$key}'\\)\\}\\s*\\/>/");
    expect(apSource(AP_PANEL))
        ->toMatch('/href=\{`#\$\{id\}-title`\}\s*onClick=\{\(event\) => \{\s*event\.preventDefault\(\);\s*document\.getElementById\(`\$\{id\}-title`\)\?\.focus\(\);/')
        ->toMatch('/<h2\s+id=\{`\$\{id\}-title`\}\s+tabIndex=\{-1\}/');
})->with([
    'services' => ['services', 'provider.services.add_open'],
    'availability' => ['availability', 'provider.availability.add_open'],
]);

test('after a save the item is in view with the focus, at once, the dialog included; the submit button keeps the focus', function () {
    expect(apSource('resources/js/hooks/use-flash-focus.ts'))
        ->toContain("element.scrollIntoView({ block: 'start', behavior: 'instant' });")
        ->toContain('element.focus({ preventScroll: true });')
        ->toMatch('/useEffect\(\(\) => \{\s*const id = flashFocusId\(flash\);\s*if \(id\) \{\s*revealAndFocus\(id\);/');

    $panel = apSource(AP_PANEL);
    expect($panel)
        ->toMatch('/onCloseAutoFocus=\{\(event\) => \{[\s\S]*?if \(id && document\.getElementById\(id\)\) \{\s*event\.preventDefault\(\);\s*window\.requestAnimationFrame\(\(\) => revealAndFocus\(id\)\);/');
    // The button gets the focus back only if nobody took it: useFormFocus (#244), re-exported here.
    expect(apSource('resources/js/hooks/use-form-focus.ts'))
        ->toMatch('/if \(active === null \|\| active === document\.body\) \{\s*ref\.current\?\.focus\(\);/');
    expect($panel)->toMatch("/export \\{\\s*useFormFocus,\\s*useFormFocus as useSubmitFocus,?\\s*\\} from '@\\/hooks\\/use-form-focus';/");

    foreach (AP_PAGES as $file) {
        expect(apSource($file))
            ->toContain('useFlashFocus();')
            // On the "add" form itself, not anywhere in the page (the other forms have them too, #244).
            ->toMatch('/onSuccess=\{\(page\) => onSaved\(page\.flash\)\}\s*onError=\{submit\.onError\}\s*onFinish=\{submit\.onFinish\}/')
            ->toContain('ref={submit.ref}')
            ->toContain('options={{ preserveScroll: true }}');
    }
    // The targets: reachable by script only, with an id.
    expect(apSource(AP_PAGES['services']))->toMatch('/<CardTitle\s+id=\{`service-\$\{service\.id\}`\}\s+tabIndex=\{-1\}/');
    expect(apSource(AP_PAGES['availability']))->toContain('const titleId = `availability-day-${weekday.weekday}`;')
        ->toMatch('/<CardTitle\s+id=\{titleId\}\s+tabIndex=\{-1\}\s+className=\{CARD_TITLE_FOCUS\}\s*>/');
});

test('the edit of a service keeps no scroll position: the list shows the changed card', function () {
    expect(apSource('resources/js/pages/provider/services/edit.tsx'))
        // The focus after a refused save (#244): onError and onFinish, still no preserveScroll.
        ->toMatch('/<Form\s+\{\.\.\.update\.form\(service\.id\)\}\s+onError=\{submit\.onError\}\s+onFinish=\{submit\.onFinish\}\s+className="flex flex-col gap-6"\s*>/')
        ->not->toMatch('/update\.form\(service\.id\)\}\s*options=/');
});

test('the form of a service is shorter, the texts of the durations unchanged', function () {
    $services = apSource(AP_PAGES['services']);

    expect($services)
        ->toContain('className="@container flex flex-col gap-4"')
        ->toContain('<div className="grid gap-4 @xs:grid-cols-2">')
        ->toMatch('/<Textarea\s+id="service-description"\s+name="description"\s+rows=\{3\}/')
        ->toContain('<ServiceDurationFields errors={errors} />');
});

test('the toasts are at the top centre, on every page', function () {
    expect(apSource('resources/js/components/ui/sonner.tsx'))
        ->toContain('position="top-center"')
        ->not->toContain('bottom-right');
});

test('the hours: a closed day on one line, the help folded in a native details', function () {
    $hours = apSource(AP_PAGES['availability']);

    expect($hours)
        ->toMatch("/if \\(availabilities\\.length === 0\\) \\{\\s*return \\(\\s*<p\\s+id=\\{titleId\\}\\s+tabIndex=\\{-1\\}[\\s\\S]*?\\{t\\('provider\\.availability\\.closed_day', \\{ day \\}\\)\\}/")
        ->toMatch("/<details className=\"text-sm text-muted-foreground\">\\s*<summary\\s+className=\\{`[^`]*focus-visible:ring-\\[3px\\][^`]*\\\$\\{touchTarget\\}`\\}\\s*>\\s*\\{t\\('provider\\.availability\\.help_title'\\)\\}\\s*<\\/summary>/")
        ->toContain("{t('provider.availability.help_touch')}")
        ->toContain("{t('provider.availability.help_midnight')}")
        ->toContain("{t('provider.availability.help_timezone')}");
});

// ---------------------------------------------------------------------------------------------
// Documentation and texts
// ---------------------------------------------------------------------------------------------

test('docs/accessibility.md writes the second exception with its rules', function () {
    $doc = apSource('docs/accessibility.md');

    expect($doc)
        ->toContain('- **La scheda « Aggiungi » che resta in vista** (la seconda eccezione alla regola « niente `sticky` », #53)')
        ->toContain('`xl:sticky xl:top-4 xl:max-h-[calc(100svh-2rem)] xl:overflow-y-auto`')
        ->toContain('**accanto alla lista, mai sopra**')
        ->toContain('**un solo modulo per volta**')
        ->toContain('**niente `fixed`, nessuna animazione**')
        ->toContain('`ProviderAddPanelTest`')
        // The checklist names both exceptions.
        ->toContain('le uniche eccezioni sono i pulsanti in basso a destra della home');
});

test('the new texts in the three languages, with the French spaces', function () {
    $nb = "\u{00A0}";
    $expected = [
        'it' => ['Aggiungi un servizio', 'Aggiungi una fascia', 'Come funziona', ':day: chiuso'],
        'fr' => ['Ajouter une prestation', 'Ajouter une plage horaire', 'Comment ça marche', ":day{$nb}: fermé"],
        'en' => ['Add a service', 'Add working hours', 'How it works', ':day: closed'],
    ];
    $keys = ['provider.services.add_open', 'provider.availability.add_open', 'provider.availability.help_title', 'provider.availability.closed_day'];

    foreach ($expected as $locale => $texts) {
        $json = json_decode(apSource("lang/{$locale}.json"), true, flags: JSON_THROW_ON_ERROR);
        foreach ($keys as $i => $key) {
            expect($json[$key] ?? null)->toBe($texts[$i], "{$locale} {$key}");
            if ($locale === 'fr') {
                expect(preg_match('/ [:?!;](?![a-z_])/', $json[$key]))->toBe(0, "fr {$key}");
            }
        }
    }
});

// ---------------------------------------------------------------------------------------------
// The highlight after a save
// ---------------------------------------------------------------------------------------------

test('after a save the card, not its title, is highlighted, and the highlight goes as soon as the person goes on', function () {
    $hook = apSource('resources/js/hooks/use-flash-focus.ts');

    expect($hook)
        ->toContain("export const JUST_SAVED_ATTRIBUTE = 'data-just-saved';")
        // The card of the title, or the closed day itself.
        ->toContain("const card = target.closest<HTMLElement>('[data-slot=\"card\"]') ?? target;")
        ->toContain("card.setAttribute(JUST_SAVED_ATTRIBUTE, '');")
        ->toContain('card.removeAttribute(JUST_SAVED_ATTRIBUTE);')
        // Only when the focus is really on the title: a plain click never gets here.
        ->toMatch('/element\.focus\(\{ preventScroll: true \}\);\s*if \(document\.activeElement === element\) \{\s*highlight\(element\);\s*\}/')
        // Removed when the focus leaves the card, or at a pointer or a key outside it: one signal for all.
        ->toMatch("/card\.addEventListener\(\s*'focusout',\s*\(event\) => \{\s*if \(outside\(event\.relatedTarget\)\) \{\s*clear\(\);/")
        ->toMatch("/for \(const type of \['pointerdown', 'keydown'\] as const\) \{\s*document\.addEventListener\(\s*type,\s*\(event\) => \{\s*if \(outside\(event\.target\)\) \{\s*clear\(\);/")
        ->toMatch('/const clear = \(\) => \{\s*listeners\.abort\(\);\s*card\.removeAttribute\(JUST_SAVED_ATTRIBUTE\);/')
        ->toContain('{ signal: listeners.signal }')
        ->toContain('{ capture: true, signal: listeners.signal }')
        // The page changes: nothing left behind. No timer.
        ->toContain('useEffect(() => clearJustSaved, []);')
        ->not->toContain('setTimeout')
        ->not->toContain('setInterval');
    // Never set on the title: the attribute is on the card.
    expect($hook)->not->toMatch('/element\.setAttribute\(/')
        ->not->toMatch('/target\.setAttribute\(/');
    // With the dialog, the same function runs after the closing (requestAnimationFrame).
    expect(apSource(AP_PANEL))->toContain('window.requestAnimationFrame(() => revealAndFocus(id));');
});

test('JUST_SAVED is an outline in the full ring colour, on the attribute only, with nothing that moves', function () {
    $justSaved = apConstant('JUST_SAVED');

    expect($justSaved)->toBe('data-[just-saved]:outline-[3px] data-[just-saved]:outline-offset-2 data-[just-saved]:outline-ring');
    foreach (explode(' ', $justSaved) as $class) {
        expect($class)->toStartWith('data-[just-saved]:outline-');
    }
    expect($justSaved)
        ->not->toContain('ring-')
        ->not->toContain('/')
        ->not->toContain('focus')
        ->not->toContain('transition')
        ->not->toContain('animate-');
});

test('the card of a service, the card of a day and a closed day carry JUST_SAVED', function () {
    expect(apSource(AP_PAGES['services']))->toContain('<Card className={JUST_SAVED}>');
    $hours = apSource(AP_PAGES['availability']);
    expect($hours)->toContain('<Card className={JUST_SAVED}>')
        ->toContain('className={`px-1 text-muted-foreground ${CARD_TITLE_FOCUS} ${JUST_SAVED}`}');
});

test('the titles of the cards have no ring, only the hidden outline; the title of the column keeps the ring', function () {
    expect(apConstant('CARD_TITLE_FOCUS'))->toBe('scroll-mt-4 rounded-sm outline-none focus-visible:outline-hidden')
        ->and(apConstant('PANEL_TITLE_FOCUS'))->toContain('focus-visible:outline-hidden')
        ->toContain('focus-visible:ring-[3px]');
    expect(apSource(AP_PANEL))->toContain('className={`text-xl leading-none font-semibold ${PANEL_TITLE_FOCUS}`}');
    expect(apSource(AP_PAGES['services']))->toContain('className={`flex flex-wrap items-center gap-2 ${CARD_TITLE_FOCUS}`}');
    foreach ([AP_PANEL, ...AP_PAGES] as $file) {
        expect(apSource($file))->not->toContain('FOCUS_TARGET');
    }
});

test('docs/accessibility.md explains the highlight after a save', function () {
    expect(apSource('docs/accessibility.md'))
        ->toContain('- **Evidenziazione dopo il salvataggio** (servizi e orari, #53)')
        ->toContain("**l'evidenziazione è un segnale in più, non un indicatore di focus**")
        ->toContain('**un `outline`, non un `ring`**')
        ->toContain('**sparisce al primo segno di avanzamento**')
        ->toContain('**senza anello**, perché non si raggiunge con Tab');
});
