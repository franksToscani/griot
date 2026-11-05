<?php

use App\Http\Controllers\LocaleController;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// The privacy page and its links (issue #56, phase 1). The page is rendered in the browser: the
// tests read the props, the translations and the React sources.

/**
 * The props of the privacy page, read with assertInertia().
 *
 * @return array<string, mixed>
 */
function prvProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $page->component('privacy');
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * The privacy.* texts of a language.
 *
 * @return array<string, string>
 */
function prvTexts(string $locale): array
{
    /** @var array<string, string> $translations */
    $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

    return array_filter($translations, fn (string $key) => str_starts_with($key, 'privacy.'), ARRAY_FILTER_USE_KEY);
}

/**
 * The config file read again with some environment variables, then the environment as before.
 *
 * @param  array<string, string>  $variables
 * @return array<string, mixed>
 */
function prvConfigWith(array $variables): array
{
    foreach ($variables as $name => $value) {
        $_SERVER[$name] = $_ENV[$name] = $value;
    }

    try {
        return require config_path('privacy.php');
    } finally {
        foreach (array_keys($variables) as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
    }
}

// ---------------------------------------------------------------------------------------------
// The page
// ---------------------------------------------------------------------------------------------

test('the privacy page is public and has its name', function () {
    expect(route('privacy'))->toBe(url('/privacy'));

    prvProps($this->get(route('privacy')));
});

test('the page is in the chosen language, with its title and lang', function (string $locale, string $title, string $months) {
    config()->set('booking.anonymize_after_months', 6);
    $test = $locale === 'it' ? $this : $this->withCookie('locale', $locale);

    $response = $test->get(route('privacy'));
    $props = prvProps($response);

    $response->assertSee("<html lang=\"{$locale}\"", false);
    expect($props['locale'])->toBe($locale)
        ->and($props['translations']['privacy.title'])->toBe($title)
        ->and(__('privacy.retention.bookings', ['months' => 6], $locale))->toContain($months);
})->with([
    'Italian' => ['it', 'Informativa sulla privacy', '6 mesi dopo la fine della prenotazione'],
    'French' => ['fr', 'Politique de confidentialité', '6 mois après la fin de la réservation'],
    'English' => ['en', 'Privacy notice', '6 months after the end of the booking'],
]);

test('the page uses the public layout and a translated title', function () {
    expect((string) file_get_contents(resource_path('js/app.tsx')))->toMatch("/case name\.startsWith\('bookings\/'\):\s*case name === 'privacy':\s*case name === 'home':\s*return PublicLayout;/");
    expect((string) file_get_contents(resource_path('js/pages/privacy.tsx')))->toContain("<Head title={t('privacy.title')} />");
});

test('the page has every section asked for', function () {
    $source = (string) file_get_contents(resource_path('js/pages/privacy.tsx'));

    foreach (['controller', 'data', 'purpose', 'retention', 'recipients', 'rights', 'contact', 'cookies'] as $section) {
        expect($source)->toContain("<Section title={t('privacy.{$section}.heading')}>");
    }

    expect($source)->toContain("t('privacy.updated', {");
});

// ---------------------------------------------------------------------------------------------
// The draft notice
// ---------------------------------------------------------------------------------------------

test('the draft notice is shown while the text is not reviewed, and not after', function (bool $reviewed) {
    config()->set('privacy.reviewed', $reviewed);

    expect(prvProps($this->get(route('privacy')))['reviewed'])->toBe($reviewed);
})->with(['not reviewed' => false, 'reviewed' => true]);

test('the page shows the notice only when the text is not reviewed', function () {
    expect((string) file_get_contents(resource_path('js/pages/privacy.tsx')))
        ->toMatch("/\{!reviewed && \(\s*<Alert[^>]*>\s*<AlertTitle>\{t\('privacy\.draft\.title'\)\}<\/AlertTitle>/");
});

test('PRIVACY_REVIEWED is false unless the environment says true', function () {
    expect(prvConfigWith([])['reviewed'])->toBeFalse()
        ->and(prvConfigWith(['PRIVACY_REVIEWED' => 'false'])['reviewed'])->toBeFalse()
        ->and(prvConfigWith(['PRIVACY_REVIEWED' => 'true'])['reviewed'])->toBeTrue();
});

test('the draft notice is translated in every language', function () {
    expect(__('privacy.draft.title', [], 'it'))->toBe('Bozza in revisione')
        ->and(__('privacy.draft.title', [], 'fr'))->toBe('Brouillon en cours de révision')
        ->and(__('privacy.draft.title', [], 'en'))->toBe('Draft under review');
});

// ---------------------------------------------------------------------------------------------
// Controller and contact
// ---------------------------------------------------------------------------------------------

test('the name and the email of the controller come from the config', function () {
    config()->set('privacy.controller_name', ' Nome di prova ');
    config()->set('privacy.contact_email', 'privacy@example.com');

    expect(prvProps($this->get(route('privacy')))['controller'])->toBe(['name' => 'Nome di prova', 'email' => 'privacy@example.com']);
});

test('a missing or blank name or email is sent as null, and the page shows the placeholder', function (?string $value) {
    config()->set('privacy.controller_name', $value);
    config()->set('privacy.contact_email', $value);

    expect(prvProps($this->get(route('privacy')))['controller'])->toBe(['name' => null, 'email' => null]);

    $source = (string) file_get_contents(resource_path('js/pages/privacy.tsx'));
    expect($source)
        ->toMatch("/controller\.name \?\? \(\s*<Placeholder>\s*\{t\('privacy\.controller\.missing_name'\)\}/")
        ->toMatch("/controller\.email !== null \? \([\s\S]*?\) : \(\s*<Placeholder>\s*\{t\('privacy\.controller\.missing_email'\)\}/");
})->with(['missing' => null, 'blank' => '   ']);

test('the placeholders are exactly the expected ones', function () {
    expect(__('privacy.controller.missing_name', [], 'it'))->toBe('[Nome del titolare da inserire]')
        ->and(__('privacy.controller.missing_email', [], 'it'))->toBe('[Email di contatto da inserire]')
        ->and(__('privacy.controller.missing_name', [], 'fr'))->toBe('[Nom du responsable à compléter]')
        ->and(__('privacy.controller.missing_email', [], 'en'))->toBe('[Contact email to be added]');
});

test('the config reads the controller and the contact from the environment only', function () {
    $source = (string) file_get_contents(config_path('privacy.php'));

    expect($source)
        ->toContain("'controller_name' => env('PRIVACY_CONTROLLER_NAME'),")
        ->toContain("'contact_email' => env('PRIVACY_CONTACT_EMAIL'),")
        ->toContain("'reviewed' => (bool) env('PRIVACY_REVIEWED', false),");
    expect(prvConfigWith(['PRIVACY_CONTROLLER_NAME' => 'X', 'PRIVACY_CONTACT_EMAIL' => 'x@example.com']))
        ->toMatchArray(['controller_name' => 'X', 'contact_email' => 'x@example.com']);
});

test('the example environment documents the variables, commented, with neutral values', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)
        ->toContain("# PRIVACY_CONTROLLER_NAME=\"Example Name\"\n")
        ->toContain("# PRIVACY_CONTACT_EMAIL=privacy@example.com\n")
        ->toContain("# PRIVACY_REVIEWED=false\n")
        ->not->toMatch('/^PRIVACY_/m');
});

test('no email address or name is written in the privacy sources and texts', function () {
    $sources = [
        config_path('privacy.php'),
        app_path('Http/Controllers/PrivacyController.php'),
        resource_path('js/pages/privacy.tsx'),
        resource_path('js/components/privacy-link.tsx'),
    ];

    foreach ($sources as $path) {
        expect(preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', (string) file_get_contents($path)))->toBe(0, "An email address in {$path}");
    }

    foreach (['it', 'fr', 'en'] as $locale) {
        foreach (prvTexts($locale) as $key => $text) {
            expect(str_contains($text, '@'))->toBeFalse("An address in {$locale} {$key}");
        }
    }

    // The only addresses of the example environment are on the reserved domain example.com.
    preg_match_all('/@([A-Za-z0-9.-]+)/', (string) file_get_contents(base_path('.env.example')), $domains);
    expect(array_unique($domains[1]))->toBe(['example.com']);
});

// ---------------------------------------------------------------------------------------------
// Retention, dates and cookies
// ---------------------------------------------------------------------------------------------

test('the number of months comes from the config', function () {
    config()->set('booking.anonymize_after_months', 9);

    expect(prvProps($this->get(route('privacy')))['retentionMonths'])->toBe(9)
        ->and(__('privacy.retention.bookings', ['months' => 9]))->toContain('9 mesi dopo la fine della prenotazione');
    expect((string) file_get_contents(resource_path('js/pages/privacy.tsx')))
        ->toMatch("/t\('privacy\.retention\.bookings', \{\s*months: retentionMonths,\s*\}\)/");
});

test('the retention is six months, and no text writes a number of months by hand', function () {
    expect(config()->integer('booking.anonymize_after_months'))->toBe(6);

    foreach (['it' => 'mesi', 'fr' => 'mois', 'en' => 'months'] as $locale => $word) {
        foreach (prvTexts($locale) as $key => $text) {
            expect(preg_match("/\\d+\\s*{$word}/u", $text))->toBe(0, "Months written by hand in {$locale} {$key}");
        }
    }
});

test('the date of the last update is a day of the config', function () {
    expect(prvProps($this->get(route('privacy')))['updatedOn'])->toBe(config('privacy.updated_on'))
        ->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

test('the cookies of the page are the real ones, with their real duration', function () {
    config()->set('session.lifetime', 120);

    expect(prvProps($this->get(route('privacy')))['cookies'])->toBe([
        'session' => config('session.cookie'),
        'sessionMinutes' => 120,
        'locale' => 'locale',
    ]);

    $page = (string) file_get_contents(resource_path('js/pages/privacy.tsx'));
    expect($page)
        ->toContain("name: 'XSRF-TOKEN',")
        ->toContain("name: 'appearance',")
        ->toContain("name: 'sidebar_state',");

    // One year for the language and the theme, seven days for the sidebar.
    expect((new ReflectionClassConstant(LocaleController::class, 'COOKIE_MINUTES'))->getValue())->toBe(60 * 24 * 365);
    expect((string) file_get_contents(resource_path('js/hooks/use-appearance.tsx')))->toContain('const setCookie = (name: string, value: string, days = 365): void => {');
    expect((string) file_get_contents(resource_path('js/components/ui/sidebar.tsx')))->toContain('const SIDEBAR_COOKIE_MAX_AGE = 60 * 60 * 24 * 7');
    expect(__('privacy.cookies.duration_year'))->toBe('1 anno')
        ->and(__('privacy.cookies.duration_week'))->toBe('7 giorni');
});

// ---------------------------------------------------------------------------------------------
// Links
// ---------------------------------------------------------------------------------------------

test('the link of the forms opens the page in a new tab, without the opener', function () {
    expect((string) file_get_contents(resource_path('js/components/privacy-link.tsx')))
        ->toContain('href={privacy.url()}')
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"')
        ->toContain("{t('privacy.link')}")
        // The "new tab" notice, shared with every link that opens a new tab (issue #55).
        ->toMatch("/\\{t\\('privacy\\.link'\\)\\}\\s*<NewTabHint \\/>\\s*<\\/a>/");
    expect((string) file_get_contents(resource_path('js/components/new-tab-hint.tsx')))
        ->toContain("<span className=\"sr-only\"> {t('privacy.new_tab')}</span>");
});

test('the booking form has a short line with the link, under the button', function () {
    expect((string) file_get_contents(resource_path('js/components/booking-sheet.tsx')))
        ->toContain("import { PrivacyLink } from '@/components/privacy-link';")
        ->toMatch("/\{t\('booking\.form\.submit'\)\}\s*<\/Button>\s*<p[^>]*>\s*\{t\('booking\.form\.privacy_note'\)\}(\{' '\})?\s+<PrivacyLink \/>/");
});

test('the registration has the link too', function () {
    expect((string) file_get_contents(resource_path('js/pages/auth/register.tsx')))
        ->toContain("import { PrivacyLink } from '@/components/privacy-link';")
        ->toMatch("/\{t\('account\.register\.privacy_note'\)\}(\{' '\})?\s+<PrivacyLink \/>/");
});

test('the public layout has the link in its footer', function () {
    expect((string) file_get_contents(resource_path('js/layouts/public-layout.tsx')))
        ->toContain("import { dashboard, home, login, privacy } from '@/routes';")
        ->toMatch("/<footer[^>]*>[\s\S]*<Link\s+href=\{privacy\(\)\}[\s\S]*\{t\('privacy\.link'\)\}[\s\S]*<\/footer>/");
});

// ---------------------------------------------------------------------------------------------
// Texts
// ---------------------------------------------------------------------------------------------

test('every privacy text exists in the three languages, and every one is used by the page', function () {
    $italian = array_keys(prvTexts('it'));
    sort($italian);

    foreach (['fr', 'en'] as $locale) {
        $keys = array_keys(prvTexts($locale));
        sort($keys);
        expect($keys)->toBe($italian);
    }

    $sources = (string) file_get_contents(resource_path('js/pages/privacy.tsx'))
        .file_get_contents(resource_path('js/components/privacy-link.tsx'))
        .file_get_contents(resource_path('js/components/new-tab-hint.tsx'))
        .file_get_contents(resource_path('js/layouts/public-layout.tsx'));

    foreach ($italian as $key) {
        expect($sources)->toContain("t('{$key}'");
    }

    foreach (['booking.form.privacy_note', 'account.register.privacy_note'] as $key) {
        expect(__($key, [], 'fr'))->not->toBe($key)
            ->and(__($key, [], 'en'))->not->toBe($key);
    }
});

test('the texts are prudent: no promise of compliance or guarantee', function () {
    $words = ['it' => '/GDPR|conform|garant/i', 'fr' => '/RGPD|GDPR|conform|garant/i', 'en' => '/GDPR|complian|guarantee/i'];

    foreach ($words as $locale => $pattern) {
        foreach (prvTexts($locale) as $key => $text) {
            expect(preg_match($pattern, $text))->toBe(0, "A promise in {$locale} {$key}: {$text}");
        }
    }
});

test('the Italian texts of the forms are exactly the expected ones', function () {
    expect(__('booking.form.privacy_note'))->toBe('I dati che lasci servono a gestire la prenotazione e li riceve il professionista che hai scelto.')
        ->and(__('account.register.privacy_note'))->toBe("Come usiamo i dati dell'account e del profilo è spiegato nell'informativa.")
        ->and(__('privacy.link'))->toBe('Informativa sulla privacy');
});
