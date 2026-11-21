<?php

use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The guide to the first steps (#53, App\Profile\SetupSteps, components/setup-steps.tsx,
// docs/accessibility.md « Guida ai primi passi »): for a provider not published yet, the four pages
// that already exist are the steps. Read from the data, never stored, never a redirect.

beforeEach(function () {
    app()->setLocale('it');
});

const SS_PAGES = [
    'business' => ['provider.edit', 'resources/js/pages/provider/edit.tsx'],
    'services' => ['provider.services.index', 'resources/js/pages/provider/services/index.tsx'],
    'availability' => ['provider.availability.index', 'resources/js/pages/provider/availability/index.tsx'],
    'publish' => ['dashboard', 'resources/js/pages/dashboard.tsx'],
];

const SS_COMPONENT = 'resources/js/components/setup-steps.tsx';

function ssSource(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/**
 * A provider in one of the states of the guide.
 */
function ssProvider(bool $published = false, bool $service = false, bool $availability = false, bool $inactiveService = false): Provider
{
    $provider = Provider::factory()->create(['is_published' => $published]);

    if ($service) {
        Service::factory()->for($provider)->create();
    }
    if ($inactiveService) {
        Service::factory()->for($provider)->inactive()->create();
    }
    if ($availability) {
        Availability::factory()->for($provider)->create(['weekday' => Weekday::Monday]);
    }

    return $provider;
}

/**
 * The steps as the server sends them, with the states given.
 *
 * @param  array{business: string, services: string, availability: string, publish: string}  $states
 * @return array{current: string, steps: list<array<string, mixed>>}
 */
function ssExpected(string $current, array $states): array
{
    $steps = [];
    foreach (SS_PAGES as $key => [$route]) {
        $steps[] = ['key' => $key, 'state' => $states[$key], 'optional' => $key === 'business', 'url' => route($route, absolute: false)];
    }

    return ['current' => $current, 'steps' => $steps];
}

// ---------------------------------------------------------------------------------------------
// The steps, from the data
// ---------------------------------------------------------------------------------------------

test('the steps come from the data: the next one is the first not done among services, hours and publish', function (array $state, string $current, array $states) {
    $provider = ssProvider(...$state);

    $this->actingAs($provider->user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('setup_steps', ssExpected($current, $states)));
})->with([
    'new: the business done, the rest to do' => [[], 'services', ['business' => 'done', 'services' => 'todo', 'availability' => 'todo', 'publish' => 'todo']],
    'a service' => [['service' => true], 'availability', ['business' => 'done', 'services' => 'done', 'availability' => 'todo', 'publish' => 'todo']],
    'a service and a window' => [['service' => true, 'availability' => true], 'publish', ['business' => 'done', 'services' => 'done', 'availability' => 'done', 'publish' => 'todo']],
    'a window only' => [['availability' => true], 'services', ['business' => 'done', 'services' => 'todo', 'availability' => 'done', 'publish' => 'todo']],
    'an inactive service only: still to do' => [['inactiveService' => true], 'services', ['business' => 'done', 'services' => 'todo', 'availability' => 'todo', 'publish' => 'todo']],
]);

test('the same steps on the four pages; none for a published provider, complete or not', function () {
    $new = ssProvider();
    $published = ssProvider(published: true, service: true, availability: true);
    $publishedIncomplete = ssProvider(published: true);

    foreach (SS_PAGES as [$route]) {
        $this->actingAs($new->user)->get(route($route))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('setup_steps.current', 'services')->has('setup_steps.steps', 4));

        foreach ([$published, $publishedIncomplete] as $provider) {
            $this->actingAs($provider->user)->get(route($route))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('setup_steps', null));
        }
    }
});

test('never on the public pages nor on the other pages of the area', function () {
    $provider = ssProvider();
    $booking = Booking::factory()->for($provider)->create();

    foreach (['/', '/providers', '/privacy', '/bookings/'.$booking->public_token] as $path) {
        $this->actingAs($provider->user)->get($path)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('setup_steps'));
    }
    foreach (['provider.bookings.index', 'profile.edit'] as $route) {
        $this->actingAs($provider->user)->get(route($route))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('setup_steps', null));
    }
});

test('each provider gets the steps of its own data, from the session, with no id in the address', function () {
    $new = ssProvider();
    $ready = ssProvider(service: true, availability: true);

    $this->actingAs($new->user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('setup_steps.current', 'services'));
    $this->actingAs($ready->user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('setup_steps.current', 'publish'));

    expect(ssSource('app/Http/Middleware/ShareProviderNavigation.php'))
        ->toContain('$provider = $request->user()?->provider;')
        ->toContain('return app(SetupSteps::class)->for($request, $provider);');
    foreach (SS_PAGES as [$route]) {
        expect(app('router')->getRoutes()->getByName($route)?->parameterNames())->toBe([]);
    }
});

test('never a redirect: the four pages answer 200 to an unpublished provider in every state', function (array $state) {
    $provider = ssProvider(...$state);

    foreach (SS_PAGES as [$route]) {
        $this->actingAs($provider->user)->get(route($route))->assertOk();
    }
})->with([
    'new' => [[]],
    'a service' => [['service' => true]],
    'a window' => [['availability' => true]],
    'ready' => [['service' => true, 'availability' => true]],
]);

test('publishing is still checked by the server only', function () {
    $provider = ssProvider();

    $this->actingAs($provider->user)->post(route('provider.publish'))
        ->assertSessionHasErrors('publish');
    expect($provider->fresh()->is_published)->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Queries
// ---------------------------------------------------------------------------------------------

/**
 * The queries of one page.
 */
function ssQueries(Provider $provider, string $route): int
{
    test()->actingAs($provider->user);
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get(route($route))->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('queries: nothing more for the published; for the others the two exists, once per request, and none on the dashboard', function () {
    $published = ssProvider(published: true, service: true, availability: true);
    $unpublished = ssProvider(service: true, availability: true);

    // The dashboard asks the requirements already: the guide reads the same answer (memo).
    expect(ssQueries($unpublished, 'dashboard'))->toBe(ssQueries($published, 'dashboard'));
    foreach (['provider.edit', 'provider.services.index', 'provider.availability.index'] as $route) {
        expect(ssQueries($unpublished, $route))->toBe(ssQueries($published, $route) + 2, $route);
    }
    // The other pages of the area never compute the guide.
    expect(ssQueries($unpublished, 'provider.bookings.index'))->toBe(ssQueries($published, 'provider.bookings.index'));
    expect(ssSource('app/Profile/SetupSteps.php'))->toContain('$request->attributes->set(self::MEMO, $memo);');
});

// ---------------------------------------------------------------------------------------------
// The component
// ---------------------------------------------------------------------------------------------

test('the indicator: a nav named by a visible line, an ordered list, aria-current on the page\'s step, no aria-label', function () {
    $component = ssSource(SS_COMPONENT);

    expect($component)
        ->toMatch('/<nav\s+aria-labelledby="setup-steps-title"/')
        ->toMatch("/<p id=\"setup-steps-title\" className=\"font-semibold\">\\s*\\{t\\('setup\\.title'\\)\\}/")
        ->and(substr_count($component, "step.key === page ? 'step' : undefined"))->toBe(2)
        // The page's step underlined too, never shown by colour alone.
        ->and($component)->toContain("'aria-[current=step]:underline aria-[current=step]:underline-offset-4'")
        ->not->toMatch('/aria-label(?!ledby)/')
        // Not a heading: the h1 of the page stays the first one.
        ->not->toMatch('/<h[1-6]/');
});

test('from 768 px the four steps on one line: a grid of four columns, never wrapping', function () {
    $component = ssSource(SS_COMPONENT);

    expect($component)
        ->toContain('<ol className="grid grid-cols-4">')
        ->toContain('<div className="hidden md:block">')
        ->toMatch('/<div className="hidden md:block">\s*<StepsRow data=\{data\} page=\{page\} \/>/')
        ->not->toContain('flex-wrap md:')
        ->not->toMatch('/md:flex-row|md:flex-wrap/');
    // The line between two circles is decoration only.
    expect($component)->toMatch("/<span\\s+aria-hidden=\"true\"\\s+className=\\{cn\\(\\s*'absolute top-4 right-1\\/2 left-\\[-50%\\] h-0\\.5'/");
});

test('each step: a circle (a check when done, the number otherwise) hidden from screen readers, its name and its state always written', function () {
    $component = ssSource(SS_COMPONENT);

    expect($component)
        // The circle itself, its first and only element: hidden.
        ->toMatch('/function StepCircle\(\{ look, number \}: \{ look: Look; number: number \}\) \{\s*return \(\s*<span\s+aria-hidden="true"\s+className=\{cn\(\s*CIRCLE,/')
        ->toContain("{look === 'done' ? <Check className=\"size-4\" /> : number}")
        ->toMatch("/<span className=\"text-xs text-muted-foreground\">\\s*\\{t\\(`setup\\.state\\.\\\$\\{step\\.state\\}`\\)\\}\\s*\\{step\\.optional && `, \\\$\\{t\\('setup\\.optional'\\)\\}`\\}/")
        ->and(substr_count($component, '<StepText step={step} look={look} />'))->toBe(2)
        // The colours of the tokens: done filled, next in the brand colour, the others neutral.
        ->and($component)->toContain("const CIRCLE_DONE = 'border-primary bg-primary text-primary-foreground';")
        ->toContain("const CIRCLE_NEXT = 'border-primary bg-card text-primary';")
        ->toContain("const CIRCLE_LATER = 'border-border bg-card text-muted-foreground';")
        ->toMatch("/return step\\.key === data\\.current \\? 'next' : 'later';/");
});

test('44 px links for the steps, the kit\'s focus ring, nothing sticky, fixed or moving', function () {
    $component = ssSource(SS_COMPONENT);

    expect($component)
        ->toMatch("/const STEP_LINK = cn\\(\\s*'rounded-md outline-none focus-visible:ring-\\[3px\\] focus-visible:ring-ring\\/50 focus-visible:outline-hidden',\\s*touchTarget,\\s*\\);/")
        ->and(substr_count($component, 'STEP_LINK,'))->toBe(2)
        ->and($component)->not->toMatch('/\bsticky\b|["\'` ]fixed[ "\'`]|\banimate-|\btransition\b/')
        ->not->toMatch('/tabIndex=\{[1-9]/');
});

test('below 768 px one line in a native details, with a bar of four segments that is decoration only', function () {
    $component = ssSource(SS_COMPONENT);

    expect($component)
        ->toMatch('/<details className="md:hidden">\s*<summary/')
        ->toMatch("/t\\('setup\\.compact', \\{\\s*number,\\s*total: data\\.steps\\.length,\\s*step: t\\(`setup\\.step\\.\\\$\\{page\\}`\\),/")
        ->toMatch('/<StepsColumn data=\{data\} page=\{page\} \/>/')
        ->toMatch('/<div\s+aria-hidden="true"\s+className="grid grid-cols-4 gap-1 md:hidden"\s*>/');
});

test('buttons of the kit, never underlined links: "Go to" under the steps, "Back" and "Next" at the bottom', function () {
    $component = ssSource(SS_COMPONENT);

    expect($component)
        ->toContain("import { Button } from '@/components/ui/button';")
        // One "Go to", towards the step to do next, only when it is not this page; outline on the
        // dashboard, where "Pubblica il profilo" is the main button.
        ->toMatch("/\\{next &&\\s*next\\.key !== page && \\([\\s\\S]*?<Button\\s+asChild\\s+variant=\\{page === 'publish' \\? 'outline' : 'default'\\}[\\s\\S]*?<Link href=\\{next\\.url\\} onSuccess=\\{focusPageTitle\\}>\\s*\\{t\\('setup\\.go_to'/")
        ->toMatch("/<Button asChild variant=\"outline\" className=\\{wrappingLabel\\}>\\s*<Link href=\\{previous\\.url\\} onSuccess=\\{focusPageTitle\\}>\\s*\\{t\\('setup\\.back'/")
        ->toMatch("/<Button asChild className=\\{wrappingLabel\\}>\\s*<Link href=\\{following\\.url\\} onSuccess=\\{focusPageTitle\\}>\\s*\\{t\\('setup\\.forward'/")
        // No plain underlined link left in the guide but the steps themselves.
        ->not->toContain('underline underline-offset-4 ${touchTarget}');
});

test('the four pages show the guide at the top and the footer at the bottom, each on its own step', function (string $key) {
    $source = ssSource(SS_PAGES[$key][1]);
    $wrapper = (int) strpos($source, '<div className="flex flex-col gap-6 p-4">');
    $nav = (int) strpos($source, "<SetupStepsNav page=\"{$key}\" />");
    $title = (int) strpos($source, 'id={PAGE_TITLE_ID}');
    $footer = (int) strpos($source, "<SetupStepsFooter page=\"{$key}\" />");

    expect($wrapper)->toBeGreaterThan(0)
        ->and($nav)->toBeGreaterThan($wrapper)
        ->and($title)->toBeGreaterThan($nav)
        ->and($footer)->toBeGreaterThan($title);
})->with(array_keys(SS_PAGES));

test('after a link of the guide the focus goes to the h1 of the page, which has no ring', function () {
    $component = ssSource(SS_COMPONENT);

    expect($component)
        ->toContain("export const PAGE_TITLE_ID = 'page-title';")
        ->toContain("export const PAGE_TITLE_FOCUS = 'outline-none focus-visible:outline-hidden';")
        ->toMatch('/export function focusPageTitle\(\): void \{\s*window\.requestAnimationFrame\(\(\) =>\s*document\.getElementById\(PAGE_TITLE_ID\)\?\.focus\(\),/')
        // Every link of the guide: the steps in a row and in a column, "Go to", back and next.
        ->and(substr_count($component, 'onSuccess={focusPageTitle}'))->toBe(5);
    foreach (SS_PAGES as [, $file]) {
        expect(ssSource($file))->toMatch('/<h1\s+id=\{PAGE_TITLE_ID\}\s+tabIndex=\{-1\}\s+className=\{`[^`]*\$\{PAGE_TITLE_FOCUS\}`\}/');
    }
});

test('the dashboard of an unpublished provider: no list of what is missing, one sentence, the preview and a publish button off until ready', function () {
    $dashboard = ssSource(SS_PAGES['publish'][1]);
    preg_match('/function PublishSection\(.*?\n}\n/s', $dashboard, $match);
    $section = $match[0] ?? '';

    expect($section)
        ->toMatch("/\\{publishing\\.can_publish\\s*\\?\\s*t\\('provider\\.publish\\.ready'\\)\\s*:\\s*t\\('provider\\.publish\\.complete_steps'\\)\\}/")
        ->toMatch("/<Button\\s+asChild\\s+variant=\"outline\"\\s+className=\\{wrappingLabel\\}\\s*>\\s*<Link href=\\{preview\\(\\)\\}>\\s*\\{t\\('provider\\.area\\.preview'\\)\\}/")
        ->toContain('disabled={!publishing.can_publish || processing}')
        ->not->toContain('provider.publish.missing_')
        ->not->toContain('<ul')
        ->not->toContain('MISSING_PAGES');
    expect($dashboard)->not->toContain('MISSING_PAGES');

    // The data behind the button: off with something missing, on with everything.
    $new = ssProvider();
    $ready = ssProvider(service: true, availability: true);
    $this->actingAs($new->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('publishing.can_publish', false));
    $this->actingAs($ready->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('publishing.can_publish', true));
});

// ---------------------------------------------------------------------------------------------
// Texts
// ---------------------------------------------------------------------------------------------

test('the texts in the three languages, with the French spaces', function () {
    $nb = "\u{00A0}";
    $expected = [
        'it' => ['setup.title' => 'Prepara la tua pagina', 'setup.step.services' => 'I tuoi servizi', 'setup.state.done' => 'fatto', 'setup.state.todo' => 'da fare', 'setup.optional' => 'facoltativo', 'setup.compact' => 'Passo :number di :total: :step', 'setup.forward' => 'Avanti: :step', 'setup.back' => 'Indietro: :step'],
        'fr' => ['setup.title' => 'Préparez votre page', 'setup.step.services' => 'Vos prestations', 'setup.state.done' => 'fait', 'setup.state.todo' => 'à faire', 'setup.optional' => 'facultatif', 'setup.compact' => "Étape :number sur :total{$nb}: :step", 'setup.forward' => "Suivant{$nb}: :step", 'setup.back' => "Précédent{$nb}: :step"],
        'en' => ['setup.title' => 'Get your page ready', 'setup.step.services' => 'Your services', 'setup.state.done' => 'done', 'setup.state.todo' => 'to do', 'setup.optional' => 'optional', 'setup.compact' => 'Step :number of :total: :step', 'setup.forward' => 'Next: :step', 'setup.back' => 'Back: :step'],
    ];
    $keys = ['setup.title', 'setup.step.business', 'setup.step.services', 'setup.step.availability', 'setup.step.publish', 'setup.state.done', 'setup.state.todo', 'setup.optional', 'setup.compact', 'setup.go_to', 'setup.back', 'setup.forward', 'provider.publish.complete_steps'];

    foreach ($expected as $locale => $texts) {
        $json = json_decode(ssSource("lang/{$locale}.json"), true, flags: JSON_THROW_ON_ERROR);
        foreach ($texts as $key => $text) {
            expect($json[$key] ?? null)->toBe($text, "{$locale} {$key}");
        }
        foreach ($keys as $key) {
            expect($json)->toHaveKey($key);
            if ($locale === 'fr') {
                expect(preg_match('/ [:?!;](?![a-z_])/', $json[$key]))->toBe(0, "fr {$key}");
            }
        }
        // The keys left without a use are gone.
        expect($json)->not->toHaveKey('setup.next_todo');
        expect($json)->not->toHaveKey('provider.publish.missing_title');
    }
    expect(ssSource('lang/it.json'))->toContain('"setup.go_to": "Vai a: :step"')
        ->and(ssSource('lang/fr.json'))->toContain("\"setup.go_to\": \"Aller à\u{00A0}: :step\"");
});

test('docs: the paragraph of the guide, its checklist line, the guide for the providers in the README', function () {
    expect(ssSource('docs/accessibility.md'))
        ->toContain('- **Guida ai primi passi** (#53)')
        ->toContain('`aria-current="step"`')
        ->toContain('lo **stato scritto, sempre**')
        ->toContain('**mai solo il colore**')
        ->toContain('**pulsanti del kit, non link sottolineati**')
        ->toContain('**sotto 768 px** una riga compatta');
    // The exception to "no section buttons on the dashboard" (#204), written where the rule lives.
    expect(ssSource('docs/bookings.md'))->toContain('Unica eccezione, solo per chi non è pubblicato: in cima la guida ai primi passi');
    expect(ssSource('README.md'))->toContain('[provider-first-steps.md](docs/provider-first-steps.md)');
    expect(file_exists(base_path('docs/provider-first-steps.md')))->toBeTrue();
});
