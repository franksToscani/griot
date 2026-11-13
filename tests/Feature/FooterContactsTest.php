<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Provider;
use App\Models\Service;
use App\Support\WhatsApp;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

// The site's contacts in the footer of the public pages (issue #144, step 4, docs/home.md): the
// configuration, the shared prop, the WhatsApp rule shared with the providers, the footer's
// source and its texts. Only made-up values: no real address or number anywhere.

/**
 * The shared prop "contact", as a page gets it.
 *
 * @return array<string, mixed>
 */
function fcContact(string $url = '/'): array
{
    $contact = [];

    test()->get($url)->assertOk()->assertInertia(function (Assert $page) use (&$contact) {
        $contact = $page->toArray()['props']['contact'];
    });

    return $contact;
}

function fcSource(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/**
 * The footer of the public layout, alone.
 */
function fcFooter(): string
{
    preg_match('/<footer[\s\S]*<\/footer>/', fcSource('resources/js/layouts/public-layout.tsx'), $footer);

    return $footer[0] ?? '';
}

// ---------------------------------------------------------------------------------------------
// The configuration and the shared prop
// ---------------------------------------------------------------------------------------------

test('with a valid email and number, the pages get the email and the WhatsApp address', function () {
    config(['contact.email' => 'info@example.test', 'contact.whatsapp' => '+390000000000']);

    // The same number gives the "Chiama" link of the help panel of the home page (tel:).
    expect(fcContact())->toBe(['email' => 'info@example.test', 'whatsapp_url' => 'https://wa.me/390000000000', 'phone_url' => 'tel:+390000000000']);
});

test('a missing, empty or invalid value becomes null, and is no error', function (?string $email, ?string $whatsapp, array $expected) {
    config(['contact.email' => $email, 'contact.whatsapp' => $whatsapp]);

    expect(fcContact())->toBe($expected);
})->with([
    'nothing configured' => [null, null, ['email' => null, 'whatsapp_url' => null, 'phone_url' => null]],
    'empty' => ['', '', ['email' => null, 'whatsapp_url' => null, 'phone_url' => null]],
    'only spaces' => ['   ', '   ', ['email' => null, 'whatsapp_url' => null, 'phone_url' => null]],
    'not an email' => ['info-example.test', null, ['email' => null, 'whatsapp_url' => null, 'phone_url' => null]],
    'half an email' => ['info@', null, ['email' => null, 'whatsapp_url' => null, 'phone_url' => null]],
    'spaces around the email' => ['  info@example.test ', null, ['email' => 'info@example.test', 'whatsapp_url' => null, 'phone_url' => null]],
    'a number without + or 00' => [null, '3330000000', ['email' => null, 'whatsapp_url' => null, 'phone_url' => null]],
    'only the prefix' => [null, '+', ['email' => null, 'whatsapp_url' => null, 'phone_url' => null]],
    'with spaces and dashes' => [null, '+39 000-000 0000', ['email' => null, 'whatsapp_url' => 'https://wa.me/390000000000', 'phone_url' => 'tel:+390000000000']],
    'with 00' => [null, '0039 000 000 0000', ['email' => null, 'whatsapp_url' => 'https://wa.me/390000000000', 'phone_url' => 'tel:+390000000000']],
    'only the email' => ['info@example.test', null, ['email' => 'info@example.test', 'whatsapp_url' => null, 'phone_url' => null]],
    'only the number' => [null, '+390000000000', ['email' => null, 'whatsapp_url' => 'https://wa.me/390000000000', 'phone_url' => 'tel:+390000000000']],
]);

test('the prop has the three values only, never the privacy contact', function () {
    config([
        'contact.email' => 'info@example.test',
        'contact.whatsapp' => '+390000000000',
        'privacy.contact_email' => 'privacy@example.test',
        'privacy.controller_name' => 'Example Controller',
    ]);

    $contact = fcContact();

    expect(array_keys($contact))->toBe(['email', 'whatsapp_url', 'phone_url'])
        ->and((string) json_encode($contact))->not->toContain('privacy@example.test')->not->toContain('Example Controller');

    // Not even as a fallback: without the site's own email, there is no email.
    config(['contact.email' => null]);
    expect(fcContact()['email'])->toBeNull();
});

test('the prop is a closure: nothing is read until a page is rendered', function () {
    $shared = (new HandleInertiaRequests)->share(Request::create('/'));

    expect($shared['contact'])->toBeInstanceOf(Closure::class)
        ->and(fcSource('app/Http/Middleware/HandleInertiaRequests.php'))->toContain("'contact' => fn () => SiteContact::shared(),");
});

test('config/contact.php reads the two variables only, and .env.example leaves them empty', function () {
    expect(fcSource('config/contact.php'))
        ->toContain("'email' => env('CONTACT_EMAIL'),")
        ->toContain("'whatsapp' => env('CONTACT_WHATSAPP'),")
        ->not->toContain('PRIVACY_');

    $example = fcSource('.env.example');
    expect($example)->toMatch('/^CONTACT_EMAIL=$/m')
        ->toMatch('/^CONTACT_WHATSAPP=$/m')
        ->and(preg_match('/^#?\s*CONTACT_(EMAIL|WHATSAPP)=\S/m', $example))->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// WhatsApp, the same rule for the providers and for the site
// ---------------------------------------------------------------------------------------------

test('the shared WhatsApp rule gives what the provider\'s link always gave', function (?string $number, ?string $expected) {
    $provider = Provider::factory()->make(['whatsapp' => $number]);

    expect(WhatsApp::url($number))->toBe($expected)
        ->and($provider->whatsappUrl())->toBe($expected);
})->with([
    'with +' => ['+39 333 000 0001', 'https://wa.me/393330000001'],
    'with 00' => ['0039 333-000-0001', 'https://wa.me/393330000001'],
    'national, no prefix guessed' => ['333 000 0001', null],
    'only the prefix' => ['+', null],
    'only 00' => ['00', null],
    'spaces around' => ['  +39 333 000 0001  ', 'https://wa.me/393330000001'],
    'empty' => ['', null],
    'missing' => [null, null],
]);

test('the provider uses the shared rule, written once', function () {
    expect(fcSource('app/Models/Provider.php'))
        ->toContain('return WhatsApp::url($this->whatsapp);')
        ->not->toContain('https://wa.me/');
    expect(substr_count(fcSource('app/Support/WhatsApp.php'), "'https://wa.me/'"))->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// The pages
// ---------------------------------------------------------------------------------------------

test('the public pages answer with and without the contacts', function (string $page, bool $configured) {
    config($configured
        ? ['contact.email' => 'info@example.test', 'contact.whatsapp' => '+390000000000']
        : ['contact.email' => null, 'contact.whatsapp' => null]);
    $provider = Provider::factory()->published()->create();
    Service::factory()->for($provider)->create(['is_active' => true]);

    $url = match ($page) {
        'home' => route('home'),
        'list' => route('providers.index'),
        'provider page' => route('providers.show', $provider->slug),
        'privacy' => route('privacy'),
    };

    expect(fcContact($url)['email'])->toBe($configured ? 'info@example.test' : null);
})->with([
    'home' => 'home',
    'list' => 'list',
    'provider page' => 'provider page',
    'privacy' => 'privacy',
])->with([
    'with contacts' => true,
    'without contacts' => false,
]);

// ---------------------------------------------------------------------------------------------
// The footer
// ---------------------------------------------------------------------------------------------

test('the footer keeps the privacy link and shows a contact only when there is one', function () {
    $footer = fcFooter();

    expect($footer)
        ->toMatch("/<Link\\s+href=\\{privacy\\(\\)\\}\\s+className=\\{cn\\(\\s*'underline underline-offset-4',\\s*touchTarget,\\s*\\)\\}\\s*>\\s*\\{t\\('privacy\\.link'\\)\\}\\s*<\\/Link>/")
        // No group without a contact; each link only with its own value.
        ->toMatch('/\{\(contact\.email !== null \|\|\s*contact\.whatsapp_url !== null\) && \(\s*<div\s+role="group"\s+aria-labelledby=\{FOOTER_CONTACT_ID\}/')
        ->toMatch("/<span id=\\{FOOTER_CONTACT_ID\\} className=\"sr-only\">\\s*\\{t\\('footer\\.contact\\.label'\\)\\}\\s*<\\/span>/")
        ->toMatch("/\\{contact\\.email !== null && \\(\\s*<a\\s+href=\\{`mailto:\\\$\\{contact\\.email\\}`\\}\\s+className=\\{cn\\(\\s*'underline underline-offset-4',\\s*touchTarget,\\s*\\)\\}\\s*>\\s*\\{t\\('footer\\.contact\\.email'\\)\\}\\s*<\\/a>\\s*\\)\\}/")
        ->toMatch("/\\{contact\\.whatsapp_url !== null && \\(\\s*<a\\s+href=\\{whatsappLink\\(\\s*contact\\.whatsapp_url,\\s*t\\('footer\\.contact\\.whatsapp_message'\\),?\\s*\\)\\}\\s+target=\"_blank\"\\s+rel=\"noopener noreferrer\"\\s+className=\\{cn\\(\\s*'underline underline-offset-4',\\s*touchTarget,\\s*\\)\\}\\s*>\\s*\\{t\\('footer\\.contact\\.whatsapp'\\)\\}\\s*<NewTabHint \\/>\\s*<\\/a>\\s*\\)\\}/")
        // The links wrap on a narrow phone.
        ->toContain('flex w-full max-w-6xl flex-wrap');
    expect(fcSource('resources/js/layouts/public-layout.tsx'))
        ->toContain("const FOOTER_CONTACT_ID = 'footer-contact-label';")
        ->toContain("import { whatsappLink } from '@/lib/whatsapp';")
        ->toContain("import { NewTabHint } from '@/components/new-tab-hint';");
});

test('the footer has no heading, no language selector, nothing written by hand, nothing that sticks', function () {
    $footer = fcFooter();

    expect($footer)->not->toBeEmpty()
        ->not->toMatch('/<h[1-6]\b/')
        ->not->toContain('LocaleSwitcher')
        ->not->toMatch('/aria-label(?!ledby)/')
        ->not->toMatch('/sticky|fixed|animate-|transition/');
    // Exactly the links of before plus the two contacts: privacy, terms (#131), the list of
    // providers (the PR of the empty home page), mailto, WhatsApp.
    expect(substr_count($footer, '<Link'))->toBe(3)
        ->and(substr_count($footer, '<a'))->toBe(2);
});

test('no real contact in the code: no address, no wa.me number, no phone number', function () {
    foreach (['config/contact.php', 'app/Support/SiteContact.php', 'app/Support/WhatsApp.php', 'app/Http/Middleware/HandleInertiaRequests.php', 'resources/js/layouts/public-layout.tsx', '.env.example'] as $path) {
        $source = fcSource($path);
        // .env.example keeps the examples of the mail and privacy, all on example.com.
        $withoutExamples = preg_replace('/[\w.+-]+@example\.(com|test)/', '', $source);

        expect($withoutExamples)->not->toMatch('/[\w.+-]+@[\w-]+\.[a-z]{2,}/i', $path)
            ->and($source)->not->toMatch('/wa\.me\/\d/', $path)
            ->and($source)->not->toMatch('/\+?\d[\d \-]{7,}\d/', $path);
    }
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts of the footer exist in Italian, French and English, with the same placeholders', function () {
    $json = fn (string $locale) => json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        sort($found[0]);

        return $found[0];
    };

    preg_match_all("/t\\(\\s*'(footer\\.contact\\.[a-z_]+)'/", fcFooter(), $used);
    $keys = array_values(array_unique($used[1]));
    sort($keys);
    expect($keys)->toBe(['footer.contact.email', 'footer.contact.label', 'footer.contact.whatsapp', 'footer.contact.whatsapp_message']);

    foreach ($keys as $key) {
        foreach (['it', 'fr', 'en'] as $locale) {
            expect($json($locale))->toHaveKey($key)
                ->and($placeholders($json($locale)[$key]))->toBe($placeholders($json('it')[$key]), "{$locale} {$key}");
        }
        expect(preg_match('/ [:?!;](?![a-z])/', $json('fr')[$key]))->toBe(0, "fr {$key}");
    }

    // A generic message: nobody's data.
    expect(__('footer.contact.whatsapp_message', [], 'it'))->toBe('Ciao, ho una domanda su Griot.')
        ->and(__('footer.contact.whatsapp_message', [], 'fr'))->toBe("Bonjour, j'ai une question sur Griot.")
        ->and(__('footer.contact.whatsapp_message', [], 'en'))->toBe('Hello, I have a question about Griot.')
        ->and(__('footer.contact.email', [], 'it'))->toBe('Scrivici');
});
