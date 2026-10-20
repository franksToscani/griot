<?php

use App\Models\Provider;
use App\Models\User;
use App\Profile\CreateProviderProfile;
use App\Profile\ProfileDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    app()->setLocale('it');
});

/**
 * A valid form, with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function ppcForm(array $override = []): array
{
    return [
        'name' => 'Barberia Lagos',
        'city' => 'Milano',
        'bio' => 'Tagli afro e cura della barba.',
        'whatsapp' => '+39 333 123 4567',
        'instagram' => 'barberia.lagos',
        ...$override,
    ];
}

/**
 * @param  array<string, mixed>  $data
 */
function ppcPost(User $user, array $data): TestResponse
{
    return test()->actingAs($user)->post(route('provider.store'), $data);
}

/**
 * Run $run with a "creating" listener on Provider, then remove it in any case. Provider has no
 * listeners of its own, so flushEventListeners() removes only this one; a new application (and
 * a new event dispatcher) for every test would drop it anyway.
 */
function ppcWhileCreating(Closure $listener, Closure $run): mixed
{
    Provider::creating($listener);

    try {
        return $run();
    } finally {
        Provider::flushEventListeners();
    }
}

/**
 * Insert a provider with the query builder, bypassing the model and its events.
 */
function ppcInsertProvider(int $userId, string $slug): void
{
    DB::table('providers')->insert([
        'user_id' => $userId,
        'slug' => $slug,
        'name' => 'Inserito nel frattempo',
        'city' => 'Roma',
        'is_published' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('guests go to the login page, unverified users to the verification page', function () {
    $this->post(route('provider.store'), ppcForm())->assertRedirect(route('login'));

    ppcPost(User::factory()->unverified()->create(), ppcForm())->assertRedirect(route('verification.notice'));

    expect(Provider::query()->count())->toBe(0);
});

test('a valid form creates the unpublished provider of the logged-in user', function () {
    $user = User::factory()->create();

    ppcPost($user, ppcForm())
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Profilo creato. Per ora non è visibile ai clienti.']);

    $provider = Provider::query()->sole();
    $provider->refresh();
    expect($provider->user_id)->toBe($user->id);
    expect($provider->name)->toBe('Barberia Lagos');
    expect($provider->city)->toBe('Milano');
    expect($provider->bio)->toBe('Tagli afro e cura della barba.');
    expect($provider->whatsapp)->toBe('+39 333 123 4567');
    expect($provider->instagram)->toBe('barberia.lagos');
    expect($provider->slug)->toBe('barberia-lagos');
    expect($provider->is_published)->toBeFalse();
});

test('user_id, is_published, avatar_path, slug and id in the input are ignored', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    ppcPost($user, ppcForm([
        'user_id' => $other->id,
        'is_published' => '1',
        'avatar_path' => 'x',
        'slug' => 'altro',
        'id' => 999,
    ]))->assertSessionHasNoErrors();

    $provider = Provider::query()->sole();
    expect($provider->user_id)->toBe($user->id);
    expect($provider->is_published)->toBeFalse();
    expect($provider->avatar_path)->toBeNull();
    expect($provider->slug)->toBe('barberia-lagos');
    expect($provider->id)->not->toBe(999);
});

test('missing or empty optional fields are saved as null', function (array $optional) {
    $user = User::factory()->create();

    ppcPost($user, ['name' => 'Barberia Lagos', 'city' => 'Milano', ...$optional])->assertSessionHasNoErrors();

    $provider = Provider::query()->sole();
    expect($provider->bio)->toBeNull();
    expect($provider->whatsapp)->toBeNull();
    expect($provider->instagram)->toBeNull();
})->with([
    'missing' => [[]],
    'empty' => [['bio' => '', 'whatsapp' => '', 'instagram' => '']],
]);

test('spaces are trimmed and the "@" of Instagram is removed', function () {
    ppcPost(User::factory()->create(), ppcForm([
        'name' => '  Barberia Lagos  ',
        'city' => '  Milano ',
        'whatsapp' => ' +39 333 123 4567 ',
        'instagram' => '@nome.cognome',
    ]))->assertSessionHasNoErrors();

    $provider = Provider::query()->sole();
    expect($provider->name)->toBe('Barberia Lagos');
    expect($provider->city)->toBe('Milano');
    expect($provider->whatsapp)->toBe('+39 333 123 4567');
    expect($provider->instagram)->toBe('nome.cognome');

    ppcPost(User::factory()->create(), ppcForm(['name' => 'Altro Nome', 'instagram' => '@']))->assertSessionHasNoErrors();
    expect(Provider::query()->where('slug', 'altro-nome')->sole()->instagram)->toBeNull();
});

test('a taken slug gets the next free number', function () {
    Provider::factory()->create(['slug' => 'barberia-lagos']);

    ppcPost(User::factory()->create(), ppcForm())->assertSessionHasNoErrors();
    ppcPost(User::factory()->create(), ppcForm())->assertSessionHasNoErrors();
    ppcPost(User::factory()->create(), ppcForm(['name' => 'BARBERIA  LAGOS!']))->assertSessionHasNoErrors();

    expect(Provider::query()->where('slug', 'like', 'barberia-lagos%')->orderBy('id')->pluck('slug')->all())
        ->toBe(['barberia-lagos', 'barberia-lagos-2', 'barberia-lagos-3', 'barberia-lagos-4']);
});

test('a gap in the numbers is filled', function () {
    Provider::factory()->create(['slug' => 'x']);
    Provider::factory()->create(['slug' => 'x-3']);

    ppcPost(User::factory()->create(), ppcForm(['name' => 'X']))->assertSessionHasNoErrors();

    expect(Provider::query()->latest('id')->first()->slug)->toBe('x-2');
});

test('a taken slug of 100 characters gets "-2" within the 120 of the column', function () {
    Provider::factory()->create(['slug' => str_repeat('a', 100)]);

    ppcPost(User::factory()->create(), ppcForm(['name' => str_repeat('a', 150)]))->assertSessionHasNoErrors();

    $slug = Provider::query()->latest('id')->first()->slug;
    expect($slug)->toBe(str_repeat('a', 100).'-2');
    expect(strlen($slug))->toBeLessThanOrEqual(120);
});

test('a slug taken between the read and the insert is retried with the next one', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $inserted = false;

    ppcWhileCreating(function (Provider $provider) use ($other, &$inserted) {
        // Only the first time: another provider takes the chosen slug just before the insert.
        if (! $inserted) {
            $inserted = true;
            ppcInsertProvider($other->id, $provider->slug);
        }
    }, fn () => ppcPost($user, ppcForm())->assertRedirect(route('dashboard'))->assertSessionHasNoErrors());

    expect(Provider::query()->where('user_id', $user->id)->pluck('slug')->all())->toBe(['barberia-lagos-2']);
    expect(Provider::query()->where('user_id', $other->id)->value('slug'))->toBe('barberia-lagos');
});

test('a double submit of the same user ends on the dashboard with one provider', function () {
    $user = User::factory()->create();

    ppcWhileCreating(function (Provider $provider) {
        // The other request of the same user has just created its provider.
        ppcInsertProvider($provider->user_id, 'inserito-prima');
    }, fn () => ppcPost($user, ppcForm())
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlashMissing('toast'));

    expect(Provider::query()->where('user_id', $user->id)->pluck('slug')->all())->toBe(['inserito-prima']);
});

test('five collisions in a row throw a RuntimeException and leave no provider', function () {
    $user = User::factory()->create();

    $attempt = fn () => app(CreateProviderProfile::class)->create($user, new ProfileDetails('Barberia Lagos', 'Milano'));

    ppcWhileCreating(function (Provider $provider) {
        // Every time, someone else takes exactly the chosen slug.
        ppcInsertProvider(User::factory()->create()->id, $provider->slug);
    }, fn () => expect($attempt)->toThrow(RuntimeException::class, 'No free slug found'));

    expect(Provider::query()->where('user_id', $user->id)->count())->toBe(0);
    expect(Provider::query()->count())->toBe(5);
});

test('the listeners of the tests above are not registered any more', function () {
    expect(Event::hasListeners('eloquent.creating: '.Provider::class))->toBeFalse();
});

test('a user who already has a provider cannot create a second one', function () {
    $provider = Provider::factory()->create(['name' => 'Il Primo', 'slug' => 'il-primo']);

    ppcPost($provider->user, ppcForm())
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlashMissing('toast');

    expect(Provider::query()->count())->toBe(1);
    $provider->refresh();
    expect($provider->name)->toBe('Il Primo');
    expect($provider->slug)->toBe('il-primo');
});

test('missing or too long fields have the Italian messages', function (array $override, string $field, string $message) {
    ppcPost(User::factory()->create(), ppcForm($override))->assertSessionHasErrors([$field => $message]);

    expect(Provider::query()->count())->toBe(0);
})->with([
    'name missing' => [['name' => ''], 'name', 'Scrivi il nome della tua attività.'],
    'city missing' => [['city' => ''], 'city', 'Scrivi la tua città.'],
    'name of 151' => [['name' => str_repeat('a', 151)], 'name', 'Il nome può avere al massimo 150 caratteri.'],
    'city of 101' => [['city' => str_repeat('a', 101)], 'city', 'La città può avere al massimo 100 caratteri.'],
    'bio of 1001' => [['bio' => str_repeat('a', 1001)], 'bio', 'La presentazione può avere al massimo 1000 caratteri.'],
]);

test('WhatsApp numbers in international format are accepted and saved as written', function (string $number) {
    ppcPost(User::factory()->create(), ppcForm(['whatsapp' => $number]))->assertSessionHasNoErrors();

    expect(Provider::query()->sole()->whatsapp)->toBe($number);
})->with([
    '+39 333 123 4567',
    '0039 333 1234567',
    '+393331234567',
    '+39 (333) 123-4567',
]);

test('other WhatsApp numbers are refused with one message', function (string $number) {
    $response = ppcPost(User::factory()->create(), ppcForm(['whatsapp' => $number]));

    $response->assertSessionHasErrors(['whatsapp' => 'Scrivi il numero con il prefisso internazionale, per esempio +39 333 123 4567.']);
    expect(session('errors')->get('whatsapp'))->toHaveCount(1);
    expect(Provider::query()->count())->toBe(0);
})->with([
    'no prefix' => ['333 123 4567'],
    'only the prefix' => ['+39'],
    'letters' => ['+39 abc'],
    'too many digits' => ['+39 333 123 4567 8901 234'],
    '31 characters' => ['+'.str_repeat('1', 30)],
    'two plus signs' => ['++39 333 123 4567'],
]);

test('Instagram usernames are accepted', function (string $username, string $saved) {
    ppcPost(User::factory()->create(), ppcForm(['instagram' => $username]))->assertSessionHasNoErrors();

    expect(Provider::query()->sole()->instagram)->toBe($saved);
})->with([
    'with a dot' => ['nome.cognome', 'nome.cognome'],
    'with "@" and underscore' => ['@nome_cognome', 'nome_cognome'],
    'one character' => ['a', 'a'],
    '30 characters' => [str_repeat('a', 30), str_repeat('a', 30)],
]);

test('other Instagram values are refused', function (string $username, string $message) {
    ppcPost(User::factory()->create(), ppcForm(['instagram' => $username]))->assertSessionHasErrors(['instagram' => $message]);

    expect(Provider::query()->count())->toBe(0);
})->with([
    'a space' => ['nome cognome', 'Scrivi solo il nome utente, senza link: lettere, numeri, punti e trattini bassi.'],
    'a link' => ['https://instagram.com/x', 'Scrivi solo il nome utente, senza link: lettere, numeri, punti e trattini bassi.'],
    'a slash' => ['nome/cognome', 'Scrivi solo il nome utente, senza link: lettere, numeri, punti e trattini bassi.'],
    'two "@"' => ['@@x', 'Scrivi solo il nome utente, senza link: lettere, numeri, punti e trattini bassi.'],
    '31 characters' => [str_repeat('a', 31), 'Il nome utente può avere al massimo 30 caratteri.'],
]);

test('the new profile is not public, but the dashboard shows it', function () {
    $user = User::factory()->create();
    ppcPost($user, ppcForm())->assertSessionHasNoErrors();

    $this->get(route('providers.index'))->assertOk()->assertDontSee('Barberia Lagos');
    $this->get(route('providers.show', 'barberia-lagos'))->assertNotFound();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('provider.name', 'Barberia Lagos')
            ->where('provider.is_published', false));
});

test('creating for user B does not touch the provider of user A', function () {
    $first = Provider::factory()->create(['name' => 'Il Primo', 'slug' => 'il-primo', 'city' => 'Roma']);

    ppcPost(User::factory()->create(), ppcForm())->assertSessionHasNoErrors();

    $first->refresh();
    expect($first->name)->toBe('Il Primo');
    expect($first->slug)->toBe('il-primo');
    expect($first->city)->toBe('Roma');
    expect(Provider::query()->count())->toBe(2);
});

test('a bio with HTML is saved as written', function () {
    ppcPost(User::factory()->create(), ppcForm(['bio' => '<script>alert(1)</script>']))->assertSessionHasNoErrors();

    expect(Provider::query()->sole()->bio)->toBe('<script>alert(1)</script>');
});

test('the Italian messages are exactly the expected ones', function () {
    expect(__('provider.validation.name_required'))->toBe('Scrivi il nome della tua attività.');
    expect(__('provider.validation.name_max'))->toBe('Il nome può avere al massimo :max caratteri.');
    expect(__('provider.validation.city_required'))->toBe('Scrivi la tua città.');
    expect(__('provider.validation.city_max'))->toBe('La città può avere al massimo :max caratteri.');
    expect(__('provider.validation.bio_max'))->toBe('La presentazione può avere al massimo :max caratteri.');
    expect(__('provider.validation.whatsapp_invalid'))->toBe('Scrivi il numero con il prefisso internazionale, per esempio +39 333 123 4567.');
    expect(__('provider.validation.instagram_invalid'))->toBe('Scrivi solo il nome utente, senza link: lettere, numeri, punti e trattini bassi.');
    expect(__('provider.validation.instagram_max'))->toBe('Il nome utente può avere al massimo :max caratteri.');
    expect(__('provider.setup.created'))->toBe('Profilo creato. Per ora non è visibile ai clienti.');
});
