<?php

use App\Booking\LocalTime;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// French (issue #54): written in phase 2, open to everyone since phase 3a-3. APP_PREVIEW_LOCALES is
// kept for the languages that are known but not open yet: English.

/**
 * The available locales of config/app.php, read again with APP_PREVIEW_LOCALES set to $preview, or
 * unset when null. With $base, the lang/ files are looked for there instead of in the project (a
 * temporary folder with a written English). The variable and the base path are put back as they were.
 *
 * @return array<string, string>
 */
function frtLocales(?string $preview, ?string $base = null): array
{
    $config = config_path('app.php');
    $basePath = base_path();
    $before = [$_SERVER['APP_PREVIEW_LOCALES'] ?? null, $_ENV['APP_PREVIEW_LOCALES'] ?? null, getenv('APP_PREVIEW_LOCALES')];

    $set = function (string|false|null $value): void {
        if ($value === null || $value === false) {
            unset($_SERVER['APP_PREVIEW_LOCALES'], $_ENV['APP_PREVIEW_LOCALES']);
            putenv('APP_PREVIEW_LOCALES');

            return;
        }

        $_SERVER['APP_PREVIEW_LOCALES'] = $_ENV['APP_PREVIEW_LOCALES'] = $value;
        putenv("APP_PREVIEW_LOCALES={$value}");
    };

    try {
        $set($preview);

        if ($base !== null) {
            app()->setBasePath($base);
        }

        return (require $config)['available_locales'];
    } finally {
        app()->setBasePath($basePath);
        $set($before[0] ?? $before[1] ?? $before[2]);
    }
}

/**
 * A temporary folder that looks like a project with a written English (lang/en.json), outside the
 * real project, so no file appears in lang/.
 */
function frtBaseWithEnglish(): string
{
    $base = sys_get_temp_dir().'/griot-lang-'.bin2hex(random_bytes(6));
    mkdir("{$base}/lang", recursive: true);
    file_put_contents("{$base}/lang/en.json", '{}');

    return $base;
}

/**
 * The next requests in French, chosen with the cookie: French is open to everyone.
 */
function frtFrench(): void
{
    test()->withCookie('locale', 'fr');
}

/**
 * The props of a page, read with assertInertia().
 *
 * @return array<string, mixed>
 */
function frtProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * Every French text: lang/fr.json and the lang/fr/*.php files, flattened.
 *
 * @return array<string, string>
 */
function frtTexts(): array
{
    /** @var array<string, string> $texts */
    $texts = json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach (glob(lang_path('fr/*.php')) ?: [] as $path) {
        $group = basename($path, '.php');

        foreach (array_filter(Arr::dot(require $path), is_string(...)) as $key => $text) {
            $texts["{$group}.{$key}"] = $text;
        }
    }

    return $texts;
}

// ---------------------------------------------------------------------------------------------
// Preview
// ---------------------------------------------------------------------------------------------

test('without APP_PREVIEW_LOCALES Italian and French are available', function () {
    expect(frtLocales(null))->toBe(['it' => 'Italiano', 'fr' => 'Français'])
        ->and(frtLocales(''))->toBe(['it' => 'Italiano', 'fr' => 'Français']);
    // The tests themselves start from there (phpunit.xml).
    expect(config()->array('app.available_locales'))->toBe(['it' => 'Italiano', 'fr' => 'Français']);
});

test('APP_PREVIEW_LOCALES does not add English while lang/en.json does not exist', function (string $preview) {
    expect(is_file(lang_path('en.json')))->toBeFalse();
    expect(frtLocales($preview))->toBe(['it' => 'Italiano', 'fr' => 'Français']);
})->with([
    'en' => 'en',
    'with spaces and an unknown language' => ' en , de ',
    'the open ones repeated' => 'it,fr,en',
]);

test('APP_PREVIEW_LOCALES adds a known language once it is written, and only the known ones', function (string $preview) {
    $base = frtBaseWithEnglish();

    try {
        expect(frtLocales($preview, $base))->toBe(['it' => 'Italiano', 'fr' => 'Français', 'en' => 'English']);
        // Without the variable, a written English stays closed.
        expect(frtLocales(null, $base))->toBe(['it' => 'Italiano', 'fr' => 'Français']);
    } finally {
        unlink("{$base}/lang/en.json");
        rmdir("{$base}/lang");
        rmdir($base);
    }

    expect(base_path())->toBe(dirname(__DIR__, 2));
})->with([
    'en' => 'en',
    'with spaces and an unknown language' => ' en , de ',
]);

test('APP_PREVIEW_LOCALES with only unknown languages adds nothing', function () {
    expect(frtLocales('de,es'))->toBe(['it' => 'Italiano', 'fr' => 'Français']);
});

test('the example environment documents the variable, commented and empty', function () {
    expect((string) file_get_contents(base_path('.env.example')))->toContain("# APP_PREVIEW_LOCALES=\n");
});

// ---------------------------------------------------------------------------------------------
// The site in French
// ---------------------------------------------------------------------------------------------

test('with the cookie a page is in French, with the French translations', function () {
    frtFrench();

    $response = $this->get(route('providers.index'));
    $props = frtProps($response);

    expect($props['locale'])->toBe('fr')
        ->and($props['translations'])->toHaveKey('providers.list.title', 'Trouver un professionnel')
        ->and($props['locales'])->toBe([['code' => 'it', 'name' => 'Italiano'], ['code' => 'fr', 'name' => 'Français']]);
    $response->assertSee('<html lang="fr"', false);
});

test('a validation message of Laravel is in French', function () {
    frtFrench();

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => '', 'password' => ''])
        ->assertSessionHasErrors([
            'email' => 'Le champ e-mail est obligatoire.',
            'password' => 'Le champ mot de passe est obligatoire.',
        ]);
});

test('wrong credentials get the French message of Fortify', function () {
    frtFrench();
    $user = User::factory()->create();

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'pas-le-mot-de-passe'])
        ->assertSessionHasErrors(['email' => 'E-mail ou mot de passe incorrect.']);
});

test('a message of the application is in French', function () {
    frtFrench();
    $provider = Provider::factory()->published()->create();
    $service = Service::factory()->for($provider)->create();

    $this->from(route('providers.show', $provider->slug))
        ->post(route('providers.bookings.store', $provider->slug), ['service' => $service->id, 'starts_at' => '', 'customer_name' => '', 'customer_phone' => ''])
        ->assertSessionHasErrors([
            'customer_name' => 'Indiquez votre nom.',
            'customer_phone' => 'Indiquez un numéro de téléphone.',
            'starts_at' => 'Choisissez un créneau.',
        ]);
});

test('the dates of Carbon follow the language: French days and months, same hours', function () {
    app()->setLocale('fr');
    $start = LocalTime::fromUtc(CarbonImmutable::parse('2026-10-29 08:00', 'UTC'));

    expect($start->isoFormat('dddd D MMMM YYYY'))->toBe('jeudi 29 octobre 2026')
        ->and($start->format('H:i'))->toBe('09:00');
});

// ---------------------------------------------------------------------------------------------
// Categories
// ---------------------------------------------------------------------------------------------

test('every category of the seeder has its name in every language', function () {
    $this->seed(CategorySeeder::class);
    $slugs = Category::query()->orderBy('slug')->pluck('slug');

    expect($slugs)->toHaveCount(7);

    foreach (glob(lang_path('*.json')) ?: [] as $path) {
        $translations = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($slugs as $slug) {
            expect($translations)->toHaveKey("categories.{$slug}");
        }
    }
});

test('a category shows the name of the language, or its saved name without a key', function () {
    $this->seed(CategorySeeder::class);
    $braids = Category::query()->where('slug', 'treccine')->sole();
    $new = Category::factory()->create(['slug' => 'nuova-categoria', 'name' => 'Nuova categoria']);

    expect($braids->displayName())->toBe('Treccine')
        ->and($new->displayName())->toBe('Nuova categoria');

    app()->setLocale('fr');
    expect($braids->displayName())->toBe('Tresses')
        ->and($new->displayName())->toBe('Nuova categoria');
});

test('the filters of the providers list show the categories in French', function () {
    $this->seed(CategorySeeder::class);
    $provider = Provider::factory()->published()->create();
    Service::factory()->for($provider)->for(Category::query()->where('slug', 'barbiere')->sole())->create();
    frtFrench();

    $categories = frtProps($this->get(route('providers.index')))['filterOptions']['categories'];

    expect(array_column($categories, 'name'))->toContain('Barbier')->not->toContain('Barbiere');
});

// ---------------------------------------------------------------------------------------------
// Typography and texts
// ---------------------------------------------------------------------------------------------

test('the French punctuation : ? ! ; has a non-breaking space before it, never a normal one', function () {
    foreach (frtTexts() as $key => $text) {
        // A colon followed by a letter starts a placeholder (":name"), it is not punctuation.
        expect(preg_match('/ [?!;]| :(?![A-Za-z_])/u', $text))->toBe(0, "A normal space before the punctuation in {$key}: {$text}");
    }

    expect(frtTexts()['booking.form.title'])->toBe("Réserver\u{00A0}: :service")
        ->and(frtTexts()['booking.cancel.confirm_title'])->toBe("Voulez-vous annuler la réservation\u{00A0}?");
});

test('the French texts of the glossary are exactly the expected ones', function (string $key, string $text) {
    expect(__($key, [], 'fr'))->toBe($text);
})->with([
    ['booking.form.open', 'Réserver'],
    ['booking.form.submit', 'Envoyer la demande'],
    ['booking.confirmation.status_pending', 'En attente de confirmation'],
    ['provider.bookings.status.confirmed', 'Confirmée'],
    ['provider.bookings.status.cancelled', 'Annulée'],
    ['booking.notice.subject_rejected', "Demande non acceptée\u{00A0}: :service"],
    ['provider.services.title', 'Vos prestations'],
    ['provider.services.deactivate_confirm', 'Oui, la désactiver'],
    ['provider.options.title', 'Options et suppléments'],
    ['provider.availability.add_title', 'Ajouter une plage horaire'],
    ['booking.form.times', 'Créneaux disponibles'],
    ['providers.list.title', 'Trouver un professionnel'],
    ['booking.notification.customer', 'Client'],
    ['provider.publish.unpublish', 'Retirer de la publication'],
    ['booking.reminder.subject', "Demande en attente\u{00A0}: :service"],
    ['booking.confirmation.link_title', 'Votre lien privé'],
    ['booking.notification.email', 'E-mail'],
    ['nav.dashboard', 'Mon tableau de bord'],
    ['provider.area.title', 'Votre espace'],
    ['account.forgot.return', 'Ou revenez à la'],
    ['account.forgot.return_link', 'page de connexion'],
    ['categories.treccine', 'Tresses'],
    ['provider.bookings.whatsapp_text_confirmed', 'Bonjour :name, :provider a confirmé votre réservation de :service pour le :when.'],
    ['validation.attributes.service', 'prestation'],
    ['Hello!', "Bonjour\u{00A0}!"],
]);

test('the Italian texts changed in this phase are exactly the expected ones', function () {
    expect(__('provider.bookings.create.unpublished_link', [], 'it'))->toBe('Vai al mio pannello')
        ->and(__('categories.treccine', [], 'it'))->toBe('Treccine')
        ->and(__('categories.ristorazione', [], 'it'))->toBe('Ristorazione');
});

// ---------------------------------------------------------------------------------------------
// Open to everyone (phase 3a-3), without any variable
// ---------------------------------------------------------------------------------------------

test('without any variable the selector offers Italian and French, on public and login pages', function (string $route) {
    $props = frtProps($this->get(route($route)));

    expect($props['locales'])->toBe([['code' => 'it', 'name' => 'Italiano'], ['code' => 'fr', 'name' => 'Français']])
        // Still Italian until someone chooses.
        ->and($props['locale'])->toBe('it');
})->with(['providers.index', 'login']);

test('a logged-in user who chooses French keeps it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('locale.update'), ['locale' => 'fr'])
        ->assertSessionHasNoErrors()
        ->assertCookie('locale', 'fr');

    expect($user->fresh()->locale)->toBe('fr');
});

test('a booking sent with the cookie fr keeps French as the customer\'s language', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
    $provider = Provider::factory()->published()->create();
    Availability::factory()->for($provider)->create(['weekday' => Weekday::Monday, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);

    $this->withCookie('locale', 'fr')
        ->post(route('providers.bookings.store', $provider->slug), [
            'service' => $service->id,
            'starts_at' => '2026-11-02T09:00:00Z',
            'customer_name' => 'Awa Diop',
            'customer_phone' => '+33612345678',
        ])
        ->assertSessionHasNoErrors();

    expect(Booking::query()->sole()->locale)->toBe('fr');
});

test('English is not accepted while it is not open: cookie, selector and provider form', function () {
    // The cookie: Italian.
    expect(frtProps($this->withCookie('locale', 'en')->get(route('providers.index')))['locale'])->toBe('it');

    // The selector: refused.
    $this->from('/providers')->post(route('locale.update'), ['locale' => 'en'])
        ->assertSessionHasErrors(['locale' => 'Il valore scelto per lingua non è valido.'])
        ->assertCookieMissing('locale');

    // The provider form: refused.
    $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
    $provider = Provider::factory()->published()->create();
    Availability::factory()->for($provider)->create(['weekday' => Weekday::Monday, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);

    $this->actingAs($provider->user)->post(route('provider.bookings.store'), [
        'service' => $service->id,
        'starts_at' => '2026-11-02T09:00:00Z',
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+33612345678',
        'customer_locale' => 'en',
    ])->assertSessionHasErrors(['customer_locale' => 'Scegli una lingua tra quelle disponibili.']);

    expect(Booking::query()->count())->toBe(0);
});

test('the two French texts corrected before the opening are exactly the expected ones', function () {
    expect(__('common.email_placeholder', [], 'fr'))->toBe('nom@exemple.com')
        ->and(__('booking.phone.invalid', [], 'fr'))->toBe("Indiquez le numéro avec l'indicatif du pays (par exemple +39 333 123 4567 ou +237 6 77 12 34 56). Pour les numéros italiens et camerounais, l'indicatif du pays n'est pas obligatoire.");
});
