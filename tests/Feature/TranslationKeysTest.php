<?php

use App\Booking\BookingOutcome;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

// Translation files are data: these tests read them and the sources, without a browser.

/**
 * The keys of a JSON translation file.
 *
 * @return array<string, string>
 */
function trkJson(string $locale): array
{
    /** @var array<string, string> $translations */
    $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

    return $translations;
}

/**
 * The languages that have a JSON file, Italian first.
 *
 * @return list<string>
 */
function trkLocales(): array
{
    $locales = array_map(fn (string $path) => basename($path, '.json'), glob(lang_path('*.json')) ?: []);

    return array_values(array_unique(['it', ...$locales]));
}

/**
 * A PHP translation group of a language, flattened: "max.string" => "…".
 *
 * @return array<string, string>
 */
function trkGroup(string $locale, string $group): array
{
    /** @var array<string, mixed> $lines */
    $lines = require lang_path("{$locale}/{$group}.php");

    // An empty group ("custom" => []) has no text to compare.
    return array_filter(Arr::dot($lines), is_string(...));
}

/**
 * The placeholders of a text (":name", ":max"), sorted.
 *
 * @return list<string>
 */
function trkPlaceholders(string $text): array
{
    preg_match_all('/:([a-z_]+)/', $text, $matches);
    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

/**
 * The source files under a folder, with the given extensions; generated folders left out.
 *
 * @param  list<string>  $extensions
 * @return list<string>
 */
function trkSources(string $folder, array $extensions): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($folder), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = $file->getPathname();

        if (preg_match('#/resources/js/(routes|actions|wayfinder)/#', $path) === 1) {
            continue;
        }

        foreach ($extensions as $extension) {
            if (str_ends_with($path, $extension)) {
                $files[] = $path;
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Every literal key the code asks for: t('…'), props ending in "Key" and the titleKey/descriptionKey
 * of the static breadcrumbs, menus and auth layouts in React, __('…') in PHP and Blade. Keys built at run time ('provider.bookings.transition.'.$reason) are not literals:
 * trkDynamicKeys() lists them.
 *
 * @return list<string>
 */
function trkUsedKeys(): array
{
    $keys = [];

    foreach (trkSources('resources/js', ['.ts', '.tsx']) as $path) {
        $source = (string) file_get_contents($path);
        preg_match_all('/\bt\(\s*([\'"])([^\'"`$]+)\1\s*[,)]/', $source, $calls);
        preg_match_all('/\b[a-zA-Z]+Key="([a-z0-9_.]+)"/', $source, $props);
        preg_match_all('/\b(?:titleKey|descriptionKey): \'([a-z0-9_.]+)\'/', $source, $objects);
        $keys = [...$keys, ...$calls[2], ...$props[1], ...$objects[1]];
    }

    foreach ([...trkSources('app', ['.php']), ...trkSources('resources/views', ['.php']), ...trkSources('routes', ['.php'])] as $path) {
        preg_match_all('/__\(\s*([\'"])([^\'"$]+)\1\s*[,)]/', (string) file_get_contents($path), $calls);
        $keys = [...$keys, ...$calls[2]];
    }

    $keys = array_values(array_unique($keys));
    sort($keys);

    return $keys;
}

/**
 * The keys built at run time, with every value the code can give.
 *
 * @return list<string>
 */
function trkDynamicKeys(): array
{
    $outcomes = array_map(fn (BookingOutcome $outcome) => $outcome->value, BookingOutcome::cases());

    return [
        // ProviderBookingResource::whatsappUrl().
        ...array_map(fn (string $outcome) => "provider.bookings.whatsapp_text_{$outcome}", $outcomes),
        // BookingStatusNotification::toMail().
        ...array_map(fn (string $outcome) => "booking.notice.subject_{$outcome}", $outcomes),
        ...array_map(fn (string $outcome) => "booking.notice.{$outcome}", $outcomes),
        // The provider's answers (BookingTransitions' reasons).
        ...array_map(fn (string $reason) => "provider.bookings.transition.{$reason}", ['not_pending', 'not_confirmed', 'already_started', 'already_cancelled']),
        // dashboard.tsx (PublishingRequirements' codes).
        ...array_map(fn (string $code) => "provider.publish.missing_{$code}", ['name', 'city', 'service', 'availability']),
        // provider/bookings/index.tsx.
        ...array_map(fn (BookingStatus $status) => "provider.bookings.status.{$status->value}", BookingStatus::cases()),
        // provider/availability/index.tsx.
        ...array_map(fn (Weekday $weekday) => 'common.weekdays.'.strtolower($weekday->name), Weekday::cases()),
    ];
}

/**
 * The PHP translation groups of Italian ("validation", "auth"…).
 *
 * @return list<string>
 */
function trkGroups(): array
{
    return array_map(fn (string $path) => basename($path, '.php'), glob(lang_path('it/*.php')) ?: []);
}

// ---------------------------------------------------------------------------------------------
// Every language has the same keys
// ---------------------------------------------------------------------------------------------

test('every language has the keys of Italian, with the same placeholders', function () {
    $italian = trkJson('it');

    foreach (trkLocales() as $locale) {
        $translations = trkJson($locale);

        expect(array_keys(array_diff_key($italian, $translations)))->toBe([], "Missing in {$locale}.json")
            ->and(array_keys(array_diff_key($translations, $italian)))->toBe([], "Not in it.json but in {$locale}.json");

        foreach ($italian as $key => $text) {
            expect(trkPlaceholders($translations[$key]))->toBe(trkPlaceholders($text), "Placeholders of {$key} in {$locale}.json");
        }
    }
});

test('every language has the PHP files of Italian, with the same keys and placeholders', function () {
    $languages = array_map(fn (string $path) => basename($path), glob(lang_path('*'), GLOB_ONLYDIR) ?: []);

    expect($languages)->toContain('it');

    foreach ($languages as $locale) {
        $groups = array_map(fn (string $path) => basename($path, '.php'), glob(lang_path("{$locale}/*.php")) ?: []);
        expect($groups)->toEqualCanonicalizing(trkGroups(), "PHP files of lang/{$locale}");

        foreach (trkGroups() as $group) {
            $italian = trkGroup('it', $group);
            $lines = trkGroup($locale, $group);

            expect(array_keys($lines))->toEqualCanonicalizing(array_keys($italian), "Keys of lang/{$locale}/{$group}.php");

            foreach ($italian as $key => $text) {
                expect(trkPlaceholders($lines[$key]))->toBe(trkPlaceholders($text), "Placeholders of {$group}.{$key} in {$locale}");
            }
        }
    }
});

test('every available language has its files, complete', function () {
    foreach (array_keys(config()->array('app.available_locales')) as $locale) {
        expect(is_file(lang_path("{$locale}.json")))->toBeTrue("lang/{$locale}.json is missing");

        foreach (trkGroups() as $group) {
            expect(is_file(lang_path("{$locale}/{$group}.php")))->toBeTrue("lang/{$locale}/{$group}.php is missing");
        }
    }
});

// ---------------------------------------------------------------------------------------------
// Every key the code uses exists
// ---------------------------------------------------------------------------------------------

test('every key used in the code exists in Italian', function () {
    $italian = trkJson('it');
    $used = trkUsedKeys();

    // The scan really reads the sources.
    expect(count($used))->toBeGreaterThan(300)
        ->and($used)->toContain('providers.list.title', 'booking.form.name', 'common.locale.label', 'booking.reminder.subject', 'settings.nav.profile', 'account.login.title', 'common.close');

    $missing = array_values(array_filter([...$used, ...trkDynamicKeys()], function (string $key) use ($italian) {
        // A key of a PHP group ("validation.required", "auth.failed") lives in lang/it/{group}.php.
        $group = explode('.', $key)[0];

        if (in_array($group, trkGroups(), true)) {
            return ! Lang::has($key, 'it', false);
        }

        return ! array_key_exists($key, $italian);
    }));

    expect($missing)->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// Laravel's messages in Italian
// ---------------------------------------------------------------------------------------------

test('a validation message of Laravel is now in Italian', function () {
    app()->setLocale('it');

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => '', 'password' => ''])
        ->assertSessionHasErrors([
            'email' => 'Il campo email è obbligatorio.',
            'password' => 'Il campo password è obbligatorio.',
        ]);
});

test('wrong credentials get the Italian message of Fortify', function () {
    $user = User::factory()->create();

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'non-la-password'])
        ->assertSessionHasErrors(['email' => 'Email o password non corretti.']);
});

test('every rule the application uses has an Italian message', function (array $data, array $rules, string $message) {
    app()->setLocale('it');
    $this->actingAs(User::factory()->create(['email' => 'esiste@example.com']));

    $errors = Validator::make($data, $rules)->errors()->all();

    expect($errors)->toBe([$message]);
})->with([
    'required' => [['email' => ''], ['email' => 'required'], 'Il campo email è obbligatorio.'],
    'string' => [['name' => 12], ['name' => 'string'], 'Il campo nome deve essere un testo.'],
    'email' => [['email' => 'non-una-email'], ['email' => 'email'], 'Il campo email deve essere un indirizzo email valido.'],
    'integer' => [['service' => 'a'], ['service' => 'integer'], 'Il campo servizio deve essere un numero intero.'],
    'array' => [['options' => 'a'], ['options' => 'array'], 'Il campo opzioni deve essere un elenco.'],
    'max string' => [['name' => 'abc'], ['name' => 'max:2'], 'Il campo nome può avere al massimo 2 caratteri.'],
    'max array' => [['options' => [1, 2, 3]], ['options' => 'array|max:2'], 'Il campo opzioni può avere al massimo 2 elementi.'],
    'max file' => [['avatar' => UploadedFile::fake()->create('foto.jpg', 20)], ['avatar' => 'max:10'], 'Il file foto può pesare al massimo 10 kilobyte.'],
    'min string' => [['password' => 'a'], ['password' => 'min:8'], 'Il campo password deve avere almeno 8 caratteri.'],
    'date' => [['date' => 'ieri'], ['date' => 'date'], 'Il campo giorno deve essere una data valida.'],
    'date_format' => [['date' => '19/10/2026'], ['date' => 'date_format:Y-m-d'], 'Il campo giorno deve avere il formato Y-m-d.'],
    'regex' => [['name' => '!'], ['name' => 'regex:/^[a-z]+$/'], 'Il formato del campo nome non è valido.'],
    'confirmed' => [['password' => 'una', 'password_confirmation' => 'altra'], ['password' => 'confirmed'], 'La conferma di password non corrisponde.'],
    'current_password' => [['current_password' => 'sbagliata'], ['current_password' => 'current_password'], 'La password non è corretta.'],
    'file' => [['avatar' => 'testo'], ['avatar' => 'file'], 'Il campo foto deve essere un file.'],
    'mimes' => [['avatar' => UploadedFile::fake()->create('foto.txt', 1)], ['avatar' => 'mimes:jpg,png'], 'Il file foto deve essere di tipo: jpg, png.'],
    'dimensions' => [['avatar' => UploadedFile::fake()->image('foto.jpg', 10, 10)], ['avatar' => 'dimensions:min_width=200'], "Le dimensioni dell'immagine in foto non sono valide."],
    'uploaded' => [['avatar' => new UploadedFile(__FILE__, 'foto.jpg', null, UPLOAD_ERR_INI_SIZE, true)], ['avatar' => 'file'], 'Il caricamento di foto non è riuscito.'],
    'unique' => [['email' => 'esiste@example.com'], ['email' => Rule::unique('users', 'email')], 'Questo valore di email è già in uso.'],
    'exists' => [['category_id' => 999999], ['category_id' => Rule::exists(Category::class, 'id')], 'Il valore scelto per categoria non è valido.'],
    'in' => [['city' => 'Atlantide'], ['city' => Rule::in(['Milano'])], 'Il valore scelto per città non è valido.'],
    'enum' => [['status' => 'nessuno'], ['status' => Rule::enum(BookingStatus::class)], 'Il valore scelto per stato non è valido.'],
    'password letters' => [['password' => '12345678'], ['password' => Password::min(1)->letters()], 'Il campo password deve contenere almeno una lettera.'],
    'password mixed case' => [['password' => 'minuscole'], ['password' => Password::min(1)->mixedCase()], 'Il campo password deve contenere almeno una lettera maiuscola e una minuscola.'],
    'password numbers' => [['password' => 'senzanumeri'], ['password' => Password::min(1)->numbers()], 'Il campo password deve contenere almeno un numero.'],
    'password symbols' => [['password' => 'senzasimboli1'], ['password' => Password::min(1)->symbols()], 'Il campo password deve contenere almeno un simbolo.'],
    'password min' => [['password' => 'corta'], ['password' => Password::min(12)], 'Il campo password deve avere almeno 12 caratteri.'],
]);

test('the password leak message exists in Italian, though it needs the network to be shown', function () {
    // uncompromised() asks an external service: only the text is checked here.
    expect(__('validation.password.uncompromised', ['attribute' => 'password'], 'it'))
        ->toBe("Questa password è comparsa in una fuga di dati. Scegline un'altra.");
});

test('the confirmations of the kit\'s settings are in Italian', function () {
    expect(__('settings.profile.updated'))->toBe('Profilo aggiornato.')
        ->and(__('settings.password.updated'))->toBe('Password aggiornata.');
});

test('the Italian texts of Laravel\'s files are exactly the expected ones', function () {
    $texts = [
        'auth.failed' => 'Email o password non corretti.',
        'auth.password' => 'La password non è corretta.',
        'auth.throttle' => 'Troppi tentativi di accesso. Riprova tra :seconds secondi.',
        'passwords.reset' => 'La tua password è stata reimpostata.',
        'passwords.sent' => 'Ti abbiamo mandato per email il link per reimpostare la password.',
        'passwords.throttled' => 'Aspetta un momento prima di riprovare.',
        'passwords.token' => 'Il link per reimpostare la password non è valido o è scaduto.',
        'passwords.user' => 'Non troviamo nessun account con questo indirizzo email.',
        'pagination.previous' => '&laquo; Precedente',
        'pagination.next' => 'Successiva &raquo;',
    ];

    foreach ($texts as $key => $text) {
        expect(__($key, [], 'it'))->toBe($text);
    }
});
