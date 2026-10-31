<?php

use App\Booking\LocalTime;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// French (issue #54, phase 2): written, but open to everyone only with phase 3. APP_PREVIEW_LOCALES
// lets it be reviewed before.

/**
 * The available locales of config/app.php, read again with APP_PREVIEW_LOCALES set to $preview, or
 * unset when null. The variable is put back as it was.
 *
 * @return array<string, string>
 */
function frtLocales(?string $preview): array
{
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

        return (require config_path('app.php'))['available_locales'];
    } finally {
        $set($before[0] ?? $before[1] ?? $before[2]);
    }
}

/**
 * French open, as with APP_PREVIEW_LOCALES=fr, and the next requests in French.
 */
function frtFrench(): void
{
    config()->set('app.available_locales', frtLocales('fr'));
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

test('without APP_PREVIEW_LOCALES only Italian is available', function () {
    expect(frtLocales(null))->toBe(['it' => 'Italiano'])
        ->and(frtLocales(''))->toBe(['it' => 'Italiano']);
    // The tests themselves start from there (phpunit.xml).
    expect(config()->array('app.available_locales'))->toBe(['it' => 'Italiano']);
});

test('APP_PREVIEW_LOCALES adds French, and only the known languages', function (string $preview) {
    expect(frtLocales($preview))->toBe(['it' => 'Italiano', 'fr' => 'Français']);
})->with([
    'fr' => 'fr',
    'with spaces and an unknown language' => ' fr , de ',
    'Italian repeated' => 'it,fr',
]);

test('APP_PREVIEW_LOCALES with an unknown language only adds nothing', function () {
    expect(frtLocales('de,en'))->toBe(['it' => 'Italiano']);
});

test('the example environment documents the variable, commented and empty', function () {
    expect((string) file_get_contents(base_path('.env.example')))->toContain("# APP_PREVIEW_LOCALES=\n");
});

// ---------------------------------------------------------------------------------------------
// The site in French
// ---------------------------------------------------------------------------------------------

test('with the preview a page is in French, with the French translations', function () {
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
