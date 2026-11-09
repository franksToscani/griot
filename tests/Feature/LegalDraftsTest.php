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
    expect(__('provider.photos.consent'))->toBe('Le persone riconoscibili in questa foto hanno accettato che venga pubblicata.');
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
