<?php

use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// The legal drafts (issue #131, docs/legal.md): the terms of use page, its link in the footer next
// to the privacy notice, and the notice under the notes of the booking form. The pages are rendered
// in the browser: the tests read the props, the translations and the React sources.

/**
 * The props of the terms page, read with assertInertia().
 *
 * @return array<string, mixed>
 */
function lgdProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $page->component('terms');
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * The texts of a language whose keys start with the prefix.
 *
 * @return array<string, string>
 */
function lgdTexts(string $locale, string $prefix): array
{
    /** @var array<string, string> $translations */
    $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

    return array_filter($translations, fn (string $key) => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
}

/**
 * The config file of the terms read again with some environment variables.
 *
 * @param  array<string, string>  $variables
 * @return array<string, mixed>
 */
function lgdConfigWith(array $variables): array
{
    foreach ($variables as $name => $value) {
        $_SERVER[$name] = $_ENV[$name] = $value;
    }

    try {
        return require config_path('terms.php');
    } finally {
        foreach (array_keys($variables) as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
    }
}

function lgdSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

// ---------------------------------------------------------------------------------------------
// The terms page
// ---------------------------------------------------------------------------------------------

test('the terms page answers in the three languages, with its title and the draft notice', function (string $locale, string $title, string $draft) {
    config()->set('terms.reviewed', false);
    $test = $locale === 'it' ? $this : $this->withCookie('locale', $locale);

    $response = $test->get(route('terms'));
    $props = lgdProps($response);

    expect(route('terms'))->toBe(url('/terms'));
    $response->assertSee("<html lang=\"{$locale}\"", false);
    expect($props['locale'])->toBe($locale)
        ->and($props['reviewed'])->toBeFalse()
        ->and($props['translations']['terms.title'])->toBe($title)
        ->and($props['translations']['terms.draft.title'])->toBe($draft);
})->with([
    'Italian' => ['it', "Termini d'uso", 'Bozza in revisione'],
    'French' => ['fr', "Conditions d'utilisation", 'Brouillon en cours de révision'],
    'English' => ['en', 'Terms of use', 'Draft under review'],
]);

test('the page uses the public layout and shows the draft notice only when the text is not reviewed', function () {
    expect(lgdSource('js/app.tsx'))->toMatch("/case name === 'terms':\\s*case name === 'home':\\s*return PublicLayout;/");
    expect(lgdSource('js/pages/terms.tsx'))
        ->toContain("<Head title={t('terms.title')} />")
        ->toMatch("/\\{!reviewed && \\(\\s*<DraftNotice\\s+title=\\{t\\('terms\\.draft\\.title'\\)\\}\\s+body=\\{t\\('terms\\.draft\\.body'\\)\\}\\s*\\/>\\s*\\)\\}/");

    config()->set('terms.reviewed', true);
    expect(lgdProps($this->get(route('terms')))['reviewed'])->toBeTrue();
});

test('TERMS_REVIEWED is false unless the environment says true, and the publisher comes from the environment only', function () {
    expect(lgdConfigWith([])['reviewed'])->toBeFalse()
        ->and(lgdConfigWith(['TERMS_REVIEWED' => 'true'])['reviewed'])->toBeTrue()
        ->and(lgdConfigWith(['TERMS_PUBLISHER_NAME' => 'X', 'TERMS_PUBLISHER_ADDRESS' => 'Y', 'TERMS_PUBLISHER_VAT_NUMBER' => 'Z']))
        ->toMatchArray(['publisher_name' => 'X', 'publisher_address' => 'Y', 'publisher_vat_number' => 'Z']);

    expect((string) file_get_contents(config_path('terms.php')))
        ->toContain("'publisher_name' => env('TERMS_PUBLISHER_NAME'),")
        ->toContain("'publisher_address' => env('TERMS_PUBLISHER_ADDRESS'),")
        ->toContain("'publisher_vat_number' => env('TERMS_PUBLISHER_VAT_NUMBER'),")
        ->toContain("'reviewed' => (bool) env('TERMS_REVIEWED', false),");
    expect((string) file_get_contents(base_path('.env.example')))
        ->toContain("# TERMS_PUBLISHER_NAME=\"Example Name\"\n")
        ->toContain("# TERMS_REVIEWED=false\n")
        ->not->toMatch('/^TERMS_/m');
});

test('the publisher comes from the config and the contact from the site contact; a missing value is null', function () {
    config()->set('terms.publisher_name', ' Editore di prova ');
    config()->set('terms.publisher_address', 'Via di prova 1');
    config()->set('terms.publisher_vat_number', 'IT-PROVA');
    config()->set('contact.email', 'info@example.test');

    expect(lgdProps($this->get(route('terms')))['publisher'])->toBe([
        'name' => 'Editore di prova',
        'address' => 'Via di prova 1',
        'vatNumber' => 'IT-PROVA',
        'email' => 'info@example.test',
    ]);

    config()->set('terms.publisher_name', '   ');
    config()->set('terms.publisher_address', null);
    config()->set('terms.publisher_vat_number', '');
    config()->set('contact.email', 'not an address');

    expect(lgdProps($this->get(route('terms')))['publisher'])->toBe(['name' => null, 'address' => null, 'vatNumber' => null, 'email' => null]);
});

test('every missing value of the publisher shows its placeholder, and the law is a placeholder to check', function () {
    $source = lgdSource('js/pages/terms.tsx');

    foreach (['name' => 'missing_name', 'address' => 'missing_address', 'vatNumber' => 'missing_vat'] as $field => $key) {
        expect($source)->toMatch("/publisher\\.{$field} \\?\\? \\(\\s*<Placeholder>\\s*\\{t\\('terms\\.publisher\\.{$key}'\\)\\}/");
    }
    expect($source)
        ->toMatch("/publisher\\.email !== null \\? \\([\\s\\S]*?\\) : \\(\\s*<Placeholder>\\s*\\{t\\('terms\\.publisher\\.missing_email'\\)\\}/")
        ->toMatch("/<Placeholder>\\{t\\('terms\\.law\\.missing'\\)\\}<\\/Placeholder>/");
    expect(__('terms.publisher.missing_vat', [], 'it'))->toBe('[Partita IVA da inserire]')
        ->and(__('terms.law.missing', [], 'it'))->toBe('[Legge applicabile e foro competente da verificare]');
});

test('the hours to cancel come from the config, never written in a text', function () {
    config()->set('booking.cancellation_notice_minutes', 1440);
    expect(lgdProps($this->get(route('terms')))['cancellationHours'])->toBe(24);

    config()->set('booking.cancellation_notice_minutes', 2880);
    expect(lgdProps($this->get(route('terms')))['cancellationHours'])->toBe(48)
        ->and(__('terms.cancellation.body', ['hours' => 48], 'it'))->toContain('fino a 48 ore prima');

    foreach (['it' => 'ore', 'fr' => 'heures', 'en' => 'hours'] as $locale => $word) {
        foreach (lgdTexts($locale, 'terms.') as $key => $text) {
            expect(preg_match("/\\d+\\s*{$word}/u", $text))->toBe(0, "Hours written by hand in {$locale} {$key}");
        }
    }
});

test('the terms say what is true in the code: no payment, the saved price, the photo consent', function () {
    expect(__('terms.payments.body'))->toBe('Sul sito non si paga nulla: Griot non gestisce pagamenti.')
        ->and(__('terms.price.body'))->toContain('viene salvato con la prenotazione')
        ->and(__('terms.content.body'))->toContain('dichiara di avere il loro consenso');
    // The same declaration the provider ticks when uploading a photo.
    // For the group of photos sent together since #53.
    expect(__('provider.photos.consent'))->toBe('Le persone riconoscibili in queste foto hanno accettato che vengano pubblicate.');
});

// ---------------------------------------------------------------------------------------------
// The footer
// ---------------------------------------------------------------------------------------------

test('the footer links the terms next to the privacy notice, with the same tap target', function () {
    preg_match('/<footer[\s\S]*<\/footer>/', lgdSource('js/layouts/public-layout.tsx'), $footer);

    expect(lgdSource('js/layouts/public-layout.tsx'))->toContain("import { dashboard, home, login, privacy, terms } from '@/routes';");
    expect($footer[0] ?? '')
        ->toMatch("/<Link\\s+href=\\{privacy\\(\\)\\}[\\s\\S]*?<\\/Link>\\s*<Link\\s+href=\\{terms\\(\\)\\}\\s+className=\\{cn\\(\\s*'underline underline-offset-4',\\s*touchTarget,\\s*\\)\\}\\s*>\\s*\\{t\\('terms\\.link'\\)\\}\\s*<\\/Link>/")
        ->not->toMatch('/aria-label(?!ledby)/');
    expect(__('terms.link', [], 'it'))->toBe("Termini d'uso")
        ->and(__('terms.link', [], 'fr'))->toBe("Conditions d'utilisation")
        ->and(__('terms.link', [], 'en'))->toBe('Terms of use');
});

test('the public pages show both links in their footer', function (string $url) {
    $translations = [];
    $this->get($url)->assertOk()->assertInertia(function (Assert $page) use (&$translations) {
        // Flat keys with dots: read from the array, not with a dotted path.
        $translations = $page->toArray()['props']['translations'];
    });

    expect($translations['privacy.link'])->toBe('Informativa sulla privacy')
        ->and($translations['terms.link'])->toBe("Termini d'uso");
})->with(['home' => '/', 'privacy' => '/privacy', 'terms' => '/terms']);

// ---------------------------------------------------------------------------------------------
// The notice under the notes
// ---------------------------------------------------------------------------------------------

test('the notes of the booking form have the notice, tied to the field like the other help texts', function () {
    $source = lgdSource('js/components/booking-fields.tsx');

    expect($source)
        ->toMatch("/<textarea\\s+id=\"notes\"[^>]*?\\{\\.\\.\\.fieldA11y\\('notes', errors\\.notes, 'notes-help'\\)\\}\\s*\\/>/")
        ->toMatch("/<p id=\"notes-help\" className=\"text-sm text-muted-foreground\">\\s*\\{t\\('booking\\.form\\.notes_help'\\)\\}\\s*<\\/p>/")
        ->not->toMatch('/aria-label(?!ledby)/');
    // The customer's form and the provider's one share these fields: the notes are the customer's
    // data in both (docs/legal.md).
    expect(lgdSource('js/components/booking-sheet.tsx'))->toContain('<CustomerFields');
    expect(lgdSource('js/pages/provider/bookings/create.tsx'))->toContain('<CustomerFields');
});

test('the notice of the notes is written in the three languages', function () {
    expect(__('booking.form.notes_help', [], 'it'))->toBe('Non scrivere dati sulla salute o altre informazioni sensibili.')
        ->and(__('booking.form.notes_help', [], 'fr'))->toBe("N'écrivez pas de données sur la santé ou d'autres informations sensibles.")
        ->and(__('booking.form.notes_help', [], 'en'))->toBe('Do not write health data or other sensitive information.');
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('every terms text exists in the three languages, and every one is used', function () {
    $italian = array_keys(lgdTexts('it', 'terms.'));
    sort($italian);

    foreach (['fr', 'en'] as $locale) {
        $keys = array_keys(lgdTexts($locale, 'terms.'));
        sort($keys);
        expect($keys)->toBe($italian);
    }

    $sources = lgdSource('js/pages/terms.tsx').lgdSource('js/layouts/public-layout.tsx');
    foreach ($italian as $key) {
        expect($sources)->toContain("t('{$key}'");
    }
});

test('the legal texts are prudent, with the French spacing and no address', function () {
    $words = ['it' => '/GDPR|conform|garant/i', 'fr' => '/RGPD|GDPR|conform|garant/i', 'en' => '/GDPR|complian|guarantee/i'];

    foreach ($words as $locale => $pattern) {
        $texts = [...lgdTexts($locale, 'privacy.'), ...lgdTexts($locale, 'terms.'), 'booking.form.notes_help' => __('booking.form.notes_help', [], $locale)];

        foreach ($texts as $key => $text) {
            expect(preg_match($pattern, $text))->toBe(0, "A promise in {$locale} {$key}: {$text}")
                ->and(str_contains($text, '@'))->toBeFalse("An address in {$locale} {$key}");

            if ($locale === 'fr') {
                expect(preg_match('/ [:?!;](?![a-z_])/', $text))->toBe(0, "No non-breaking space in fr {$key}");
            }
        }
    }
});

test('the docs keep the issue open and list what is a draft', function () {
    expect((string) file_get_contents(base_path('docs/legal.md')))
        ->toContain('issue #131, **ancora aperta**')
        ->toContain('#133');
    expect((string) file_get_contents(base_path('docs/roadmap.md')))
        ->toContain('(#131, **ancora aperta**')
        ->toContain('[legal.md](legal.md)');
});

test('the decisions on issue #186 are written, one for each point, and the logs of Render have their source', function () {
    $legal = (string) file_get_contents(base_path('docs/legal.md'));
    preg_match('/\n## Decisioni sulla #186 \(2026-11-19\)\n([\s\S]*?)\n## /', $legal, $section);
    $decisions = $section[1] ?? '';

    preg_match_all('/^\d+\. \*\*([^*]+)\*\*/m', $decisions, $points);
    expect($points[1])->toBe([
        'Errori del database e della posta nel log:',
        '`failed_jobs`: 14 giorni',
        'Copie nella posta inviata di Gmail:',
        'Link privato nel testo WhatsApp e nell\'email del cliente:',
        'Log delle richieste di Render:',
        'Cronologia del browser:',
        'Nessun limite di richieste sulla pagina privata in GET:',
    ]);
    expect($decisions)
        ->toContain('PR #222')
        ->toContain('--hours=336')
        ->toContain('(#62)')
        ->toContain('**Prima di passare al workspace Pro si rivede questo punto.**')
        ->toContain('https://render.com/docs/logging, consultata il 2026-11-19');

    // The result on the logs of Render, with its source and the day it was read; no more "to check".
    $runbook = (string) file_get_contents(base_path('docs/deploy.md'));
    preg_match('/^- \*\*Log delle richieste\*\*.*$/m', $runbook, $line);
    expect($line[0] ?? '')
        ->toContain('https://render.com/docs/logging, consultata il 19/11/2026')
        ->toContain('« If you have a Pro workspace or higher, Render generates a log entry for each HTTP request »')
        ->not->toContain('da verificare');
});

test('issue #186 is closed in the documents too: the check in Render\'s panel, step 4 of going public done, no longer among the open issues', function () {
    $runbook = (string) file_get_contents(base_path('docs/deploy.md'));
    $roadmap = (string) file_get_contents(base_path('docs/roadmap.md'));

    // The check by hand in Render's panel, with its day and no data.
    expect($runbook)
        ->toContain('Verificato nel pannello di Render il 26/11/2026: workspace Hobby, nessuna riga di richiesta HTTP nei log, conservazione di circa 7 giorni.')
        ->toContain('- **Da rivedere (#186)**: con un workspace Pro')
        // Step 4 of « Da privato a pubblico »: done, with where the decisions are.
        ->toMatch('/^4\. \*\*fatto:\*\* #186 chiusa il 19\/11\/2026 \(PR #222 e #223\).*« Decisioni sulla #186 ».*verificati nel pannello il 26\/11\/2026/m');

    // Sprint 9: three open issues since 26/11/2026 (#63 and #175 closed), the same in every list, and
    // #186 among the closed ones.
    preg_match('/^Gli issue ancora aperti di questo sprint sono (\d+): (.*)$/m', $roadmap, $open);
    preg_match('/^- Aperti: (.*)$/m', $roadmap, $openList);
    $stillOpen = (string) strstr($open[2] ?? '', 'Già chiusi:', true);
    $closed = (string) strstr($open[2] ?? '', 'Già chiusi:');
    expect($open[1] ?? null)->toBe('3')
        ->and($stillOpen)->not->toContain('#186')
        ->and($closed)->toContain('#186 (dati dei clienti nei log e nell\'URL)')
        ->and($openList[1] ?? '')->not->toContain('#186')
        ->and($roadmap)->not->toContain('l\'issue resta da chiudere')
        ->toContain('chiusa il 19/11/2026 con la PR #223');
    preg_match_all('/#(\d+)\b/', $stillOpen, $numbers);
    expect(array_values(array_unique($numbers[1])))->toBe(['62', '131', '200']);
});

test('issues #63 and #175 are closed in the roadmap (26/11/2026), and no list still has them open', function () {
    $roadmap = (string) file_get_contents(base_path('docs/roadmap.md'));

    preg_match('/^Gli issue ancora aperti di questo sprint sono (\d+): (.*)$/m', $roadmap, $open);
    preg_match('/^- Aperti: (.*)$/m', $roadmap, $openList);
    $stillOpen = (string) strstr($open[2] ?? '', 'Già chiusi:', true);
    $closed = (string) strstr($open[2] ?? '', 'Già chiusi:');

    foreach (['#63', '#175'] as $issue) {
        expect(preg_match('/'.$issue.'\b/', $stillOpen))->toBe(0, "{$issue} among the open issues")
            ->and(preg_match('/'.$issue.'\b/', $openList[1] ?? ''))->toBe(0, "{$issue} in « Aperti »");
    }
    expect($closed)
        ->toContain('#63 (backup e ripristino: chiusa il 26/11/2026 per decisione del gestore)')
        ->toContain('#175 (audit con axe: audit ridotto fatto il 26/11/2026, con le correzioni A, C, D ed E; l\'issue si chiude a mano dopo il merge)');
    expect($roadmap)
        ->toContain('#63, backup e ripristino: chiusa il 26/11/2026 per decisione del gestore')
        ->toContain('#175, l\'audit con axe: audit ridotto fatto il 26/11/2026, con le correzioni A, C, D ed E')
        ->toContain('L\'audit #175 è fatto il 26/11/2026, in versione ridotta')
        ->toContain('- **Fatto il 26/11/2026 in versione ridotta**')
        ->not->toContain('L\'audit #175 resta in coda')
        ->not->toContain('per #63 il ripristino completo su Render e la decisione sulle copie automatiche');
});
