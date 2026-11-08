<?php

use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// "Come funziona" with its icons and the band for the professionals (docs/home.md): icons and
// circles, the number of each step in words, the orange bar, the band only with a contact of the
// site (the shared prop "contact"), its links, texts, motion, colours and queries.

function hiwSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

/**
 * @return array<string, string>
 */
function hiwLang(string $locale): array
{
    return json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The shared prop "contact" of the home page.
 *
 * @return array<string, mixed>
 */
function hiwContact(): array
{
    $contact = [];
    test()->get('/')->assertOk()->assertInertia(function (Assert $page) use (&$contact) {
        $contact = $page->toArray()['props']['contact'];
    });

    return $contact;
}

// ---------------------------------------------------------------------------------------------
// "Come funziona"
// ---------------------------------------------------------------------------------------------

test('three steps, each with its decorative icon in an amber circle', function () {
    $page = hiwSource('pages/home.tsx');

    expect($page)
        ->toContain("import { CalendarCheck, MailCheck, Search } from 'lucide-react';")
        ->toMatch('/const STEP_ICONS = \{\s*choose: Search,\s*book: CalendarCheck,\s*confirm: MailCheck,?\s*\} as const;/')
        ->toContain("const STEPS = ['choose', 'book', 'confirm'] as const;")
        // The circle: the amber of the palette, its brown icon, decorative, a border in forced colours.
        ->toMatch('/<span\s+aria-hidden="true"\s+className="flex size-12 items-center justify-center rounded-full bg-highlight text-highlight-foreground forced-colors:border"\s*>\s*<Icon className="size-6" \/>\s*<\/span>/');
});

test('the number of the step is in the text of its heading, not only in the picture', function () {
    expect(hiwSource('pages/home.tsx'))
        ->toMatch("/<h3 className=\"font-medium\">\\s*<span className=\"block text-sm font-semibold text-muted-foreground\">\\s*\\{t\\('home\\.steps\\.number', \\{\\s*number: index \\+ 1,?\\s*\\}\\)\\}\\s*<\\/span>\\s*\\{t\\(`home\\.steps\\.\\\$\\{step\\}\\.title`\\)\\}\\s*<\\/h3>/");
    expect(__('home.steps.number', ['number' => 1], 'it'))->toBe('Passo 1')
        ->and(__('home.steps.number', ['number' => 2], 'fr'))->toBe('Étape 2')
        ->and(__('home.steps.number', ['number' => 3], 'en'))->toBe('Step 3');
});

test('the orange bar under the title: decorative, without text, gone in forced colours', function () {
    expect(hiwSource('pages/home.tsx'))
        ->toMatch("/\\{t\\('home\\.steps\\.title'\\)\\}\\s*<\\/h2>\\s*(\\{\\/\\*[\\s\\S]*?\\*\\/\\}\\s*)?<span\\s+aria-hidden=\"true\"\\s+className=\"block h-1 w-12 rounded-full bg-brand forced-colors:hidden\"\\s*\\/>/")
        // The orange of the brand nowhere else on the page: never under a text.
        ->and(substr_count(hiwSource('pages/home.tsx'), 'bg-brand'))->toBe(1);
});

test('the steps are no links: they appear on scroll, nothing moves under the mouse', function () {
    $page = hiwSource('pages/home.tsx');
    preg_match('/<ol ref=\{steps\}[\s\S]*?<\/ol>/', $page, $list);

    expect($list[0] ?? '')->not->toBe('')
        ->and($list[0])->toContain('className="reveal-item ')
        ->toContain('style={revealDelay(index)}')
        ->not->toMatch('/CARD_MOTION|hover:|<a\b|<Link\b|group-hover/');
    expect($page)->toContain('const steps = useReveal<HTMLOListElement>();');
});

// ---------------------------------------------------------------------------------------------
// The band for the professionals
// ---------------------------------------------------------------------------------------------

test('the band exists only with a contact of the site: the shared prop, nothing written in the code', function () {
    $band = hiwSource('components/home/professionals-band.tsx');

    expect($band)
        ->toContain('const { contact } = usePage().props;')
        ->toMatch('/if \(contact\.email === null && contact\.whatsapp_url === null\) \{\s*return null;\s*\}/')
        ->toMatch('/\{contact\.email !== null && \(\s*<a href=\{`mailto:\$\{contact\.email\}`\}/')
        ->toMatch('/\{contact\.whatsapp_url !== null && \(\s*<a\s+href=\{whatsappLink\(\s*contact\.whatsapp_url,\s*t\(\'home\.pros\.whatsapp_message\'\),?\s*\)\}/');
    // Under "Come funziona", the last section of the page.
    expect(hiwSource('pages/home.tsx'))->toMatch('/<\/ol>\s*<\/section>\s*(\{\/\*[\s\S]*?\*\/\}\s*)?<ProfessionalsBand \/>\s*<\/div>/');

    // The prop the band reads: with the contacts, both; without, none (and so no band).
    config(['contact.email' => 'info@example.test', 'contact.whatsapp' => '+390000000000']);
    $with = hiwContact();
    expect($with['email'])->toBe('info@example.test')->and($with['whatsapp_url'])->toBe('https://wa.me/390000000000');

    config(['contact.email' => null, 'contact.whatsapp' => null]);
    expect(hiwContact())->toBe(['email' => null, 'whatsapp_url' => null]);
});

test('a title, a sentence, the links: underlined, 44px on phones, the cream focus ring, WhatsApp says it opens a new tab', function () {
    $band = hiwSource('components/home/professionals-band.tsx');

    expect($band)
        ->toMatch('/<section\\s+ref=\\{reveal\\}\\s+aria-labelledby=\\{TITLE_ID\\}\\s+className="bg-hero text-hero-foreground dark:border dark:border-input"/')
        ->toMatch("/<h2 id=\\{TITLE_ID\\} className=\"text-xl font-semibold\">\\s*\\{t\\('home\\.pros\\.title'\\)\\}\\s*<\\/h2>/")
        ->toContain("{t('home.pros.text')}")
        ->toMatch("/const LINK = cn\\(\\s*'rounded-sm underline underline-offset-4 outline-none focus-visible:ring-\\[3px\\] focus-visible:ring-hero-foreground focus-visible:outline-hidden',\\s*touchTarget,?\\s*\\);/")
        ->and(substr_count($band, 'className={LINK}'))->toBe(2)
        ->and($band)->toMatch('/target="_blank"\s+rel="noopener noreferrer"\s+className=\{LINK\}\s*>\s*\{t\(\'home\.pros\.whatsapp\'\)\}\s*<NewTabHint \/>/')
        ->not->toMatch('/aria-label(?!ledby)/');
    expect(__('home.pros.title', [], 'it'))->toBe('Sei un professionista?')
        ->and(__('home.pros.text', [], 'it'))->toBe('Sviluppa la tua attività: fatti trovare e ricevi prenotazioni.');
});

test('no address and no number written in the code: only the shared prop', function () {
    $sources = hiwSource('components/home/professionals-band.tsx').hiwSource('pages/home.tsx');

    expect($sources)
        ->not->toMatch('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/')
        ->not->toMatch('/(\+|00)\d[\d ]{7,}/')
        ->not->toContain('wa.me/')
        ->not->toMatch('/mailto:(?!\$\{contact\.email\})/');
});

test('never "sign up": the texts of the band invite to write, not to register', function () {
    foreach (['it', 'fr', 'en'] as $locale) {
        foreach (hiwLang($locale) as $key => $text) {
            if (str_starts_with($key, 'home.pros.')) {
                expect($text)->not->toMatch('/registr|iscriv|inscri|sign ?up|register/i', "{$locale} {$key}");
            }
        }
    }
    expect(hiwSource('components/home/professionals-band.tsx'))->not->toMatch('/register|registr/i');
});

// ---------------------------------------------------------------------------------------------
// Texts, motion, colours, queries
// ---------------------------------------------------------------------------------------------

test('the texts exist in Italian, French and English, with the same placeholders and the right forms', function () {
    $keys = ['home.steps.number', 'home.pros.title', 'home.pros.text', 'home.pros.email', 'home.pros.whatsapp', 'home.pros.whatsapp_message'];

    foreach ($keys as $key) {
        foreach (['it', 'fr', 'en'] as $locale) {
            expect(hiwLang($locale))->toHaveKey($key);
            preg_match_all('/:[a-z_]+/', hiwLang($locale)[$key], $found);
            preg_match_all('/:[a-z_]+/', hiwLang('it')[$key], $expected);
            expect($found[0])->toBe($expected[0], "{$locale} {$key}");
        }
        // French: a non-breaking space before : ? ! ;, never a plain one.
        expect(preg_match('/ [:?!;](?![a-z])/', hiwLang('fr')[$key]))->toBe(0, "fr {$key}");
    }
    expect(array_keys(hiwLang('fr')))->toBe(array_keys(hiwLang('it')))
        ->and(array_keys(hiwLang('en')))->toBe(array_keys(hiwLang('it')));
    // "tu" in Italian, "vous" in French.
    expect(hiwLang('it')['home.pros.text'])->toContain('tua')
        ->and(hiwLang('fr')['home.pros.title'])->toContain('Vous')
        ->and(hiwLang('fr')['home.pros.text'])->toContain('votre');
});

test('the band appears on scroll, only without "reduce motion"; no timer, no transition of its own', function () {
    $band = hiwSource('components/home/professionals-band.tsx');

    expect($band)
        ->toContain('const reveal = useReveal<HTMLElement>();')
        ->toContain('className="reveal-item ')
        ->not->toMatch('/\b(setInterval|setTimeout|requestAnimationFrame)\b/');
    expect(withoutMotionSafe($band.hiwSource('pages/home.tsx')))
        ->not->toMatch('/\b(transition|duration-|animate-|translate-)/');
});

test('only tokens of the palette, the orange never near an error', function () {
    $sources = hiwSource('components/home/professionals-band.tsx').hiwSource('pages/home.tsx');
    preg_match_all('/(?<![\w-])(?:[\w-]+:)*(?:bg|text|border|ring)-([a-z0-9]+(?:-[a-z0-9]+)*)(?:\/\d+)?(?![\w-])/', $sources, $colours);
    $tokens = array_unique($colours[1]);
    $allowed = ['hero', 'hero-foreground', 'input', 'highlight', 'highlight-foreground', 'brand', 'card', 'card-foreground', 'muted-foreground', 'xl', 'sm', 'lg'];

    expect(array_values(array_diff($tokens, $allowed)))->toBe([])
        ->and($sources)->not->toMatch('/#[0-9a-fA-F]{6}\b|rgba?\(|oklch\(/')
        ->and($sources)->not->toMatch('/\b(?:bg|text|border|ring)-(?:red|green|blue|yellow|amber|orange|neutral|gray|white|black)\b/')
        ->and($sources)->not->toMatch('/destructive|InputError|aria-invalid|role="alert"/');
});

test('the home page keeps its queries: 4 without featured providers, 6 with them, with or without contacts', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    config(['contact.email' => null, 'contact.whatsapp' => null]);
    expect($count())->toBe(4);
    config(['contact.email' => 'info@example.test', 'contact.whatsapp' => '+390000000000']);
    expect($count())->toBe(4);

    $provider = Provider::factory()->published()->create();
    Service::factory()->for($provider)->create();
    ProviderPhoto::factory()->for($provider)->create();
    expect($count())->toBe(6);
});
