<?php

use App\Models\Provider;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    app()->setLocale('it');
});

/**
 * A provider with known values, with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 */
function ppeProvider(array $override = []): Provider
{
    return Provider::factory()->create([
        'name' => 'Salone Awa',
        'city' => 'Bologna',
        'bio' => 'Treccine e cura dei capelli.',
        'whatsapp' => '+39 333 000 1111',
        'instagram' => 'salone.awa',
        'slug' => 'salone-awa',
        'avatar_path' => null,
        'is_published' => false,
        ...$override,
    ]);
}

/**
 * A valid edit form, with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function ppeForm(array $override = []): array
{
    return [
        'name' => 'Salone Awa Diop',
        'city' => 'Modena',
        'bio' => 'Nuova presentazione.',
        'whatsapp' => '+39 333 222 3333',
        'instagram' => 'awa.diop',
        'slug' => 'salone-awa',
        ...$override,
    ];
}

/**
 * @param  array<string, mixed>  $data
 */
function ppePut(User $user, array $data): TestResponse
{
    return test()->actingAs($user)->put(route('provider.update'), $data);
}

/**
 * Run $run with an "updating" listener on Provider, then remove it in any case. Provider has no
 * listeners of its own, so flushEventListeners() removes only this one.
 */
function ppeWhileUpdating(Closure $listener, Closure $run): mixed
{
    Provider::updating($listener);

    try {
        return $run();
    } finally {
        Provider::flushEventListeners();
    }
}

/**
 * The provider's profile columns, to compare before and after.
 *
 * @return array<string, mixed>
 */
function ppeColumns(Provider $provider): array
{
    return $provider->fresh()->only(['user_id', 'name', 'city', 'bio', 'whatsapp', 'instagram', 'slug', 'is_published', 'avatar_path']);
}

test('guests go to the login page', function () {
    $this->get(route('provider.edit'))->assertRedirect(route('login'));
    $this->put(route('provider.update'), ppeForm())->assertRedirect(route('login'));
});

test('a user with an unverified email goes to the verification page', function () {
    $user = User::factory()->unverified()->create();
    ppeProvider(['user_id' => $user->id]);

    $this->actingAs($user)->get(route('provider.edit'))->assertRedirect(route('verification.notice'));
    $this->actingAs($user)->put(route('provider.update'), ppeForm())->assertRedirect(route('verification.notice'));
});

test('a user without a provider goes to the setup page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('provider.edit'))->assertRedirect(route('provider.setup'));
    // With valid data: invalid data would get the validation errors first, as in the creation.
    ppePut($user, ppeForm())->assertRedirect(route('provider.setup'));
    expect(Provider::query()->count())->toBe(0);
});

test('the edit page has exactly the seven fields of the provider', function () {
    $provider = ppeProvider(['is_published' => true, 'avatar_path' => 'avatars/awa.jpg']);
    ppeProvider(['name' => 'Barberia Kofi', 'slug' => 'barberia-kofi']);

    $this->actingAs($provider->user)
        ->get(route('provider.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('provider/edit')
            ->where('provider', [
                'name' => 'Salone Awa',
                'city' => 'Bologna',
                'bio' => 'Treccine e cura dei capelli.',
                'whatsapp' => '+39 333 000 1111',
                'instagram' => 'salone.awa',
                'slug' => 'salone-awa',
                'is_published' => true,
            ]))
        ->assertDontSee('Barberia Kofi');
});

test('the addresses have no parameters', function () {
    expect(route('provider.edit', absolute: false))->toBe('/provider/profile');
    expect(route('provider.update', absolute: false))->toBe('/provider/profile');
});

test('the number of queries does not change with or without avatar and bio', function () {
    $providers = [
        ppeProvider(['slug' => 'uno']),
        ppeProvider(['slug' => 'due', 'avatar_path' => 'avatars/due.jpg']),
        ppeProvider(['slug' => 'tre', 'bio' => null]),
        ppeProvider(['slug' => 'quattro', 'bio' => null, 'avatar_path' => 'avatars/quattro.jpg']),
    ];

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $counts = [];

    foreach ($providers as $provider) {
        $queries = 0;
        $this->actingAs($provider->user)->get(route('provider.edit'))->assertOk();
        $counts[] = $queries;
    }

    expect(array_unique($counts))->toHaveCount(1);
});

test('a valid form updates the profile, not is_published nor avatar_path', function () {
    $provider = ppeProvider(['is_published' => true, 'avatar_path' => 'avatars/awa.jpg']);

    ppePut($provider->user, ppeForm())
        ->assertRedirect(route('provider.edit'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Profilo aggiornato.']);

    expect(ppeColumns($provider))->toBe([
        'user_id' => $provider->user_id,
        'name' => 'Salone Awa Diop',
        'city' => 'Modena',
        'bio' => 'Nuova presentazione.',
        'whatsapp' => '+39 333 222 3333',
        'instagram' => 'awa.diop',
        'slug' => 'salone-awa',
        'is_published' => true,
        'avatar_path' => 'avatars/awa.jpg',
    ]);
});

test('user_id, is_published, avatar_path and id in the input are ignored', function () {
    $provider = ppeProvider();
    $other = ppeProvider(['name' => 'Barberia Kofi', 'slug' => 'barberia-kofi']);
    $otherBefore = ppeColumns($other);

    ppePut($provider->user, ppeForm([
        'user_id' => $other->user_id,
        'is_published' => '1',
        'avatar_path' => 'x',
        'id' => 999,
    ]))->assertSessionHasNoErrors();

    $after = $provider->fresh();
    expect($after->id)->toBe($provider->id);
    expect($after->user_id)->toBe($provider->user_id);
    expect($after->is_published)->toBeFalse();
    expect($after->avatar_path)->toBeNull();
    expect($after->name)->toBe('Salone Awa Diop');
    expect(ppeColumns($other))->toBe($otherBefore);
});

test('optional fields left empty become null', function () {
    $provider = ppeProvider();

    ppePut($provider->user, ppeForm(['bio' => '', 'whatsapp' => '', 'instagram' => '']))->assertSessionHasNoErrors();

    $after = $provider->fresh();
    expect($after->bio)->toBeNull();
    expect($after->whatsapp)->toBeNull();
    expect($after->instagram)->toBeNull();
});

test('the "@" of Instagram is removed', function () {
    $provider = ppeProvider();

    ppePut($provider->user, ppeForm(['instagram' => '@nome.cognome']))->assertSessionHasNoErrors();

    expect($provider->fresh()->instagram)->toBe('nome.cognome');
});

test('the same slug passes, a free one changes it, a taken one is refused', function () {
    $provider = ppeProvider();
    ppeProvider(['name' => 'Barberia Kofi', 'slug' => 'barberia-kofi']);

    // The same slug: the uniqueness check ignores the provider itself.
    ppePut($provider->user, ppeForm(['slug' => 'salone-awa']))->assertSessionHasNoErrors();

    ppePut($provider->user, ppeForm(['slug' => 'salone-di-awa']))->assertSessionHasNoErrors();
    expect($provider->fresh()->slug)->toBe('salone-di-awa');

    $before = ppeColumns($provider);
    ppePut($provider->user, ppeForm(['name' => 'Altro Nome', 'slug' => 'barberia-kofi']))
        ->assertSessionHasErrors(['slug' => 'Questo indirizzo è già usato da un altro professionista: scegline un altro.']);
    expect(ppeColumns($provider))->toBe($before);
});

test('the name never changes the slug by itself', function () {
    $provider = ppeProvider();

    ppePut($provider->user, ppeForm(['name' => 'Tutto Un Altro Nome']))->assertSessionHasNoErrors();

    expect($provider->fresh()->slug)->toBe('salone-awa');
});

test('valid slugs are accepted', function (string $slug) {
    $provider = ppeProvider();

    ppePut($provider->user, ppeForm(['slug' => $slug]))->assertSessionHasNoErrors();

    expect($provider->fresh()->slug)->toBe($slug);
})->with([
    'words' => ['il-mio-salone'],
    'one letter' => ['a'],
    'with a number' => ['salone-54'],
    '120 characters' => [str_repeat('a', 120)],
]);

test('invalid slugs are refused with one message', function (string $slug, string $message) {
    $provider = ppeProvider();

    ppePut($provider->user, ppeForm(['slug' => $slug]))->assertSessionHasErrors(['slug' => $message]);

    expect(session('errors')->get('slug'))->toHaveCount(1);
    expect($provider->fresh()->slug)->toBe('salone-awa');
})->with([
    'capitals and spaces' => ['Il Mio Salone', 'Usa solo lettere minuscole, numeri e trattini, per esempio il-mio-salone.'],
    'underscore' => ['mio_salone', 'Usa solo lettere minuscole, numeri e trattini, per esempio il-mio-salone.'],
    'leading dash' => ['-salone', 'Usa solo lettere minuscole, numeri e trattini, per esempio il-mio-salone.'],
    'trailing dash' => ['salone-', 'Usa solo lettere minuscole, numeri e trattini, per esempio il-mio-salone.'],
    'double dash' => ['salone--bello', 'Usa solo lettere minuscole, numeri e trattini, per esempio il-mio-salone.'],
    'space' => ['salone bello', 'Usa solo lettere minuscole, numeri e trattini, per esempio il-mio-salone.'],
    'slash' => ['salone/uno', 'Usa solo lettere minuscole, numeri e trattini, per esempio il-mio-salone.'],
    'empty' => ['', "Scrivi l'indirizzo della tua pagina."],
    '121 characters' => [str_repeat('a', 121), "L'indirizzo può avere al massimo 120 caratteri."],
]);

test('a new slug moves the public page of a published provider', function () {
    $provider = ppeProvider(['is_published' => true]);

    ppePut($provider->user, ppeForm(['slug' => 'nuovo-indirizzo']))->assertSessionHasNoErrors();

    $this->get(route('providers.show', 'salone-awa'))->assertNotFound();
    $this->get(route('providers.show', 'nuovo-indirizzo'))->assertOk();
});

test('an unpublished provider has no public page, before or after a new slug', function () {
    $provider = ppeProvider();

    ppePut($provider->user, ppeForm(['slug' => 'nuovo-indirizzo']))->assertSessionHasNoErrors();

    $this->get(route('providers.show', 'salone-awa'))->assertNotFound();
    $this->get(route('providers.show', 'nuovo-indirizzo'))->assertNotFound();
});

test('a slug taken between the validation and the save gives an error on slug, not a 500', function () {
    $provider = ppeProvider();
    $other = User::factory()->create();
    $before = ppeColumns($provider);
    $inserted = false;

    ppeWhileUpdating(function (Provider $updating) use ($other, &$inserted) {
        // Only the first time: another provider takes the requested slug just before the save.
        if (! $inserted) {
            $inserted = true;
            DB::table('providers')->insert([
                'user_id' => $other->id,
                'slug' => $updating->slug,
                'name' => 'Inserito nel frattempo',
                'city' => 'Roma',
                'is_published' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }, fn () => ppePut($provider->user, ppeForm(['slug' => 'indirizzo-conteso']))
        ->assertSessionHasErrors(['slug' => 'Questo indirizzo è già usato da un altro professionista: scegline un altro.']));

    expect($inserted)->toBeTrue();
    expect(ppeColumns($provider))->toBe($before);
});

test('the listener of the test above is not registered any more', function () {
    expect(Event::hasListeners('eloquent.updating: '.Provider::class))->toBeFalse();
});

test('the field messages of the creation apply to the edit too', function (array $override, string $field, string $message) {
    $provider = ppeProvider();

    ppePut($provider->user, ppeForm($override))->assertSessionHasErrors([$field => $message]);

    expect($provider->fresh()->name)->toBe('Salone Awa');
})->with([
    'name missing' => [['name' => ''], 'name', 'Scrivi il nome della tua attività.'],
    'city missing' => [['city' => ''], 'city', 'Scrivi la tua città.'],
    'name of 151' => [['name' => str_repeat('a', 151)], 'name', 'Il nome può avere al massimo 150 caratteri.'],
    'city of 101' => [['city' => str_repeat('a', 101)], 'city', 'La città può avere al massimo 100 caratteri.'],
    'bio of 1001' => [['bio' => str_repeat('a', 1001)], 'bio', 'La presentazione può avere al massimo 1000 caratteri.'],
    'WhatsApp without prefix' => [['whatsapp' => '333 123 4567'], 'whatsapp', 'Scrivi il numero con il prefisso internazionale, per esempio +39 333 123 4567.'],
    'Instagram with a space' => [['instagram' => 'nome cognome'], 'instagram', 'Scrivi solo il nome utente, senza link: lettere, numeri, punti e trattini bassi.'],
    'Instagram of 31' => [['instagram' => str_repeat('a', 31)], 'instagram', 'Il nome utente può avere al massimo 30 caratteri.'],
]);

test('a user cannot edit the provider of another one', function () {
    $mine = ppeProvider();
    $theirs = ppeProvider(['name' => 'Barberia Kofi', 'slug' => 'barberia-kofi']);
    $theirsBefore = ppeColumns($theirs);

    // The id of the other provider in the body: only the user's own provider changes.
    ppePut($mine->user, ppeForm(['id' => $theirs->id]))->assertSessionHasNoErrors();
    expect($mine->fresh()->name)->toBe('Salone Awa Diop');
    expect(ppeColumns($theirs))->toBe($theirsBefore);

    // The slug of the other provider: refused as taken, and neither provider changes.
    $mineBefore = ppeColumns($mine);
    ppePut($mine->user, ppeForm(['name' => 'Altro', 'slug' => 'barberia-kofi']))->assertSessionHasErrors('slug');
    expect(ppeColumns($mine))->toBe($mineBefore);
    expect(ppeColumns($theirs))->toBe($theirsBefore);
});

test('published and unpublished providers can both edit their profile', function (bool $published) {
    $provider = ppeProvider(['is_published' => $published]);

    $this->actingAs($provider->user)->get(route('provider.edit'))->assertOk();
    ppePut($provider->user, ppeForm())->assertSessionHasNoErrors();

    expect($provider->fresh()->name)->toBe('Salone Awa Diop');
    expect($provider->fresh()->is_published)->toBe($published);
})->with([
    'unpublished' => [false],
    'published' => [true],
]);

test('the Italian texts are exactly the expected ones', function () {
    expect(__('provider.validation.slug_required'))->toBe("Scrivi l'indirizzo della tua pagina.");
    expect(__('provider.validation.slug_invalid'))->toBe('Usa solo lettere minuscole, numeri e trattini, per esempio il-mio-salone.');
    expect(__('provider.validation.slug_max'))->toBe("L'indirizzo può avere al massimo :max caratteri.");
    expect(__('provider.validation.slug_taken'))->toBe('Questo indirizzo è già usato da un altro professionista: scegline un altro.');
    expect(__('provider.edit.updated'))->toBe('Profilo aggiornato.');
    expect(__('provider.edit.title'))->toBe('Il tuo profilo');
    expect(__('provider.edit.description'))->toBe('Cambia i dati che i clienti vedono nella tua pagina.');
    expect(__('provider.edit.slug'))->toBe('Indirizzo della tua pagina');
    expect(__('provider.edit.slug_help'))->toBe('Compare nel link della tua pagina pubblica. Se la pagina è già pubblicata e lo cambi, il vecchio link smette di funzionare.');
    expect(__('provider.edit.submit'))->toBe('Salva le modifiche');
    expect(__('provider.area.edit_profile'))->toBe('Modifica il tuo profilo');
});

test('the dashboard links to the edit page and still gets five provider fields', function () {
    // The link is checked in the page source: there is no JavaScript test runner.
    $source = file_get_contents(resource_path('js/pages/dashboard.tsx'));
    expect($source)
        ->toContain("import { edit } from '@/routes/provider';")
        ->toContain('<Link href={edit()}>')
        ->toContain("t('provider.area.edit_profile')");

    $provider = ppeProvider();
    $this->actingAs($provider->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Five keys, and exactly these: has() with a count, then each one.
            ->has('provider', 5)
            ->has('provider', fn (Assert $fields) => $fields->hasAll(['name', 'city', 'slug', 'is_published', 'avatar_url'])));
});

test('the seeder still runs twice and keeps the example providers', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Provider::query()->orderBy('slug')->pluck('is_published', 'slug')->all())->toBe([
        'atelier-wax' => false,
        'barberia-lagos' => true,
        'trecce-sahel' => true,
        'unghie-kente' => true,
    ]);
});
