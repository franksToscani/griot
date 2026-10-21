<?php

use App\Models\Availability;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Profile\CreateProviderProfile;
use App\Profile\ProfileDetails;
use App\Profile\PublishingRequirements;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    app()->setLocale('it');
    $this->psvCategory = Category::factory()->create(['name' => 'Treccine', 'slug' => 'treccine']);
});

function psvProvider(bool $published = false, string $name = 'Salone Awa'): Provider
{
    return Provider::factory()->create(['name' => $name, 'city' => 'Bologna', 'is_published' => $published]);
}

/**
 * A valid service form, with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function psvForm(array $override = []): array
{
    return [
        'name' => 'Box braids',
        'description' => 'Treccine lunghe.',
        'category_id' => test()->psvCategory->id,
        'price' => '25,50',
        'duration_min' => 30,
        'duration_max' => 60,
        ...$override,
    ];
}

/**
 * @param  array<string, mixed>  $data
 */
function psvPost(User $user, array $data): TestResponse
{
    return test()->actingAs($user)->post(route('provider.services.store'), $data);
}

const PSV_PRICE_INVALID = 'Scrivi un prezzo valido tra 0,01 e 9999,99 euro, per esempio 25 oppure 25,50.';
const PSV_DURATION_INVALID = 'Scrivi la durata in minuti, da 5 a 720, a passi di 5.';
const PSV_CATEGORY_INVALID = 'La categoria scelta non esiste.';

test('guests, unverified users and users without a provider are sent away', function () {
    $this->get(route('provider.services.index'))->assertRedirect(route('login'));
    $this->post(route('provider.services.store'), psvForm())->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    Provider::factory()->create(['user_id' => $unverified->id]);
    $this->actingAs($unverified)->get(route('provider.services.index'))->assertRedirect(route('verification.notice'));
    psvPost($unverified, psvForm())->assertRedirect(route('verification.notice'));

    $withoutProvider = User::factory()->create();
    $this->actingAs($withoutProvider)->get(route('provider.services.index'))->assertRedirect(route('provider.setup'));
    psvPost($withoutProvider, psvForm())->assertRedirect(route('provider.setup'));

    expect(Service::query()->count())->toBe(0);
});

test('the page lists only the own services, inactive too, in creation order, with exactly eight keys', function () {
    $provider = psvProvider();
    $first = Service::factory()->for($provider)->for($this->psvCategory)->create(['name' => 'Primo', 'price' => '10.00', 'duration_min' => 30, 'duration_max' => 45, 'description' => null]);
    $second = Service::factory()->for($provider)->for($this->psvCategory)->inactive()->create(['name' => 'Secondo']);
    // Same category: the factory would otherwise create a random one, which would join the list.
    Service::factory()->for(psvProvider(name: 'Barberia Kofi'))->for($this->psvCategory)->create(['name' => 'Servizio Di Un Altro']);
    Category::factory()->create(['name' => 'Ciglia', 'slug' => 'ciglia']);
    Category::factory()->create(['name' => 'Zeta', 'slug' => 'zeta']);

    $this->actingAs($provider->user)
        ->get(route('provider.services.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('provider/services/index')
            ->has('services', 2)
            ->where('services.0', [
                'id' => $first->id,
                'name' => 'Primo',
                'description' => null,
                'category' => ['id' => $this->psvCategory->id, 'name' => 'Treccine'],
                'price' => '10.00',
                'duration_min' => 30,
                'duration_max' => 45,
                'is_active' => true,
            ])
            ->where('services.1.id', $second->id)
            ->where('services.1.is_active', false)
            ->where('categories', fn ($categories) => collect($categories)->pluck('name')->all() === ['Ciglia', 'Treccine', 'Zeta']))
        ->assertDontSee('Servizio Di Un Altro');
});

test('the number of queries does not change with 1 or 5 services', function () {
    $one = psvProvider();
    Service::factory()->for($one)->create();
    $five = psvProvider(name: 'Barberia Kofi');
    Service::factory()->count(5)->for($five)->create();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs($one->user)->get(route('provider.services.index'))->assertOk();
    $countOne = $queries;

    $queries = 0;
    $this->actingAs($five->user)->get(route('provider.services.index'))->assertOk();

    expect($queries)->toBe($countOne);
});

test('a valid form adds an active service to that provider', function () {
    $provider = psvProvider();

    psvPost($provider->user, psvForm())
        ->assertRedirect(route('provider.services.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Servizio aggiunto.']);

    $service = Service::query()->sole();
    expect($service->provider_id)->toBe($provider->id);
    expect($service->only(['name', 'description', 'category_id', 'price', 'duration_min', 'duration_max', 'is_active']))->toBe([
        'name' => 'Box braids',
        'description' => 'Treccine lunghe.',
        'category_id' => $this->psvCategory->id,
        'price' => '25.50',
        'duration_min' => 30,
        'duration_max' => 60,
        'is_active' => true,
    ]);
});

test('provider_id, is_active, id and created_at in the input are ignored', function () {
    $provider = psvProvider();
    $other = psvProvider(name: 'Barberia Kofi');
    $otherService = Service::factory()->for($other)->create(['name' => 'Di Kofi']);
    $otherBefore = $otherService->fresh()->toArray();

    psvPost($provider->user, psvForm([
        'provider_id' => $other->id,
        'is_active' => '0',
        'id' => 999,
        'created_at' => '2000-01-01 00:00:00',
    ]))->assertSessionHasNoErrors();

    $service = Service::query()->where('name', 'Box braids')->sole();
    expect($service->provider_id)->toBe($provider->id);
    expect($service->is_active)->toBeTrue();
    expect($service->id)->not->toBe(999);
    expect($service->created_at->year)->not->toBe(2000);
    expect($otherService->fresh()->toArray())->toBe($otherBefore);
    expect($other->services()->count())->toBe(1);
});

test('the category is required and must exist', function (Closure $value, string $message) {
    $provider = psvProvider();

    psvPost($provider->user, psvForm(['category_id' => $value()]))->assertSessionHasErrors(['category_id' => $message]);

    expect(Service::query()->count())->toBe(0);
})->with([
    'missing' => [fn () => null, 'Scegli una categoria.'],
    'not existing' => [fn () => 999999, PSV_CATEGORY_INVALID],
    'zero' => [fn () => 0, PSV_CATEGORY_INVALID],
    'negative' => [fn () => -3, PSV_CATEGORY_INVALID],
    'not a number' => [fn () => 'abc', PSV_CATEGORY_INVALID],
    'an array' => [fn () => [1], PSV_CATEGORY_INVALID],
]);

test('any existing category can be chosen: categories are global', function () {
    $category = Category::factory()->create(['name' => 'Couture', 'slug' => 'couture']);

    psvPost(psvProvider()->user, psvForm(['category_id' => $category->id]))->assertSessionHasNoErrors();

    expect(Service::query()->sole()->category_id)->toBe($category->id);
});

test('accepted prices are saved with two decimals', function (string $price, string $saved) {
    psvPost(psvProvider()->user, psvForm(['price' => $price]))->assertSessionHasNoErrors();

    expect(Service::query()->sole()->price)->toBe($saved);
})->with([
    ['25', '25.00'],
    ['25,50', '25.50'],
    ['25.5', '25.50'],
    [' 25 ', '25.00'],
    ['0,01', '0.01'],
    ['9999,99', '9999.99'],
    ['0025', '25.00'],
]);

test('other prices are refused with one message', function (mixed $price) {
    psvPost(psvProvider()->user, psvForm(['price' => $price]))->assertSessionHasErrors(['price' => PSV_PRICE_INVALID]);

    expect(session('errors')->get('price'))->toHaveCount(1);
    expect(Service::query()->count())->toBe(0);
})->with(['abc', '0', '0,00', '-5', '1e3', '1.000,50', '25,999', '10000', '25,5,0', '25 €', '0x1A']);

test('a missing or empty price asks to write it', function (array $override) {
    psvPost(psvProvider()->user, psvForm($override))->assertSessionHasErrors(['price' => 'Scrivi il prezzo.']);

    expect(Service::query()->count())->toBe(0);
})->with([
    'missing' => [['price' => null]],
    'empty' => [['price' => '']],
]);

test('accepted durations', function (int $min, int $max) {
    psvPost(psvProvider()->user, psvForm(['duration_min' => $min, 'duration_max' => $max]))->assertSessionHasNoErrors();

    expect(Service::query()->sole()->only(['duration_min', 'duration_max']))->toBe(['duration_min' => $min, 'duration_max' => $max]);
})->with([
    [5, 5],
    [60, 120],
    [720, 720],
    [60, 60],
]);

test('invalid durations are refused on their own field', function (mixed $value) {
    $provider = psvProvider();

    psvPost($provider->user, psvForm(['duration_min' => $value, 'duration_max' => 720]))->assertSessionHasErrors(['duration_min' => PSV_DURATION_INVALID]);
    psvPost($provider->user, psvForm(['duration_min' => 5, 'duration_max' => $value]))->assertSessionHasErrors(['duration_max' => PSV_DURATION_INVALID]);

    expect(Service::query()->count())->toBe(0);
})->with([0, 4, 7, 725, 1000, 'abc', '1.5', '-5']);

test('missing durations ask to write them', function () {
    psvPost(psvProvider()->user, psvForm(['duration_min' => null, 'duration_max' => null]))
        ->assertSessionHasErrors([
            'duration_min' => 'Scrivi la durata minima.',
            'duration_max' => 'Scrivi la durata massima.',
        ]);
});

test('a longest duration shorter than the shortest one is refused', function () {
    psvPost(psvProvider()->user, psvForm(['duration_min' => 120, 'duration_max' => 60]))
        ->assertSessionHasErrors(['duration_max' => 'La durata massima non può essere minore della minima.']);

    expect(Service::query()->count())->toBe(0);
});

test('an invalid shortest duration does not add a misleading order error', function () {
    // 7 is not a valid minimum; 5 is a valid maximum but smaller than 7.
    psvPost(psvProvider()->user, psvForm(['duration_min' => 7, 'duration_max' => 5]))
        ->assertSessionHasErrors(['duration_min' => PSV_DURATION_INVALID])
        ->assertSessionDoesntHaveErrors('duration_max');
});

test('name and description limits, and trimmed spaces', function () {
    $provider = psvProvider();

    psvPost($provider->user, psvForm(['name' => '']))->assertSessionHasErrors(['name' => 'Scrivi il nome del servizio.']);
    psvPost($provider->user, psvForm(['name' => '    ']))->assertSessionHasErrors(['name' => 'Scrivi il nome del servizio.']);
    psvPost($provider->user, psvForm(['name' => str_repeat('a', 151)]))->assertSessionHasErrors(['name' => 'Il nome può avere al massimo 150 caratteri.']);
    psvPost($provider->user, psvForm(['description' => str_repeat('a', 1001)]))->assertSessionHasErrors(['description' => 'La descrizione può avere al massimo 1000 caratteri.']);
    expect(Service::query()->count())->toBe(0);

    psvPost($provider->user, psvForm(['name' => str_repeat('a', 150), 'description' => str_repeat('b', 1000)]))->assertSessionHasNoErrors();
    psvPost($provider->user, psvForm(['name' => '  Spazi ai bordi  ', 'description' => '']))->assertSessionHasNoErrors();

    $trimmed = Service::query()->where('name', 'Spazi ai bordi')->sole();
    expect($trimmed->description)->toBeNull();
    expect(Service::query()->count())->toBe(2);
});

test('HTML in the name and in the description is saved as written', function () {
    psvPost(psvProvider()->user, psvForm(['name' => '<script>alert(1)</script>', 'description' => '<b>x</b>']))->assertSessionHasNoErrors();

    $service = Service::query()->sole();
    expect($service->name)->toBe('<script>alert(1)</script>');
    expect($service->description)->toBe('<b>x</b>');
});

test('a new service shows on the public page only for a published provider, inside its category', function () {
    $published = psvProvider(published: true);
    psvPost($published->user, psvForm(['name' => 'Servizio Dal Form']))->assertSessionHasNoErrors();
    Service::factory()->for($published)->for($this->psvCategory)->inactive()->create(['name' => 'Servizio Spento']);

    // As a customer: without the logged-in user, which HandleInertiaRequests would share.
    app('auth')->forgetGuards();
    $groups = $this->get(route('providers.show', $published->slug))->assertOk()->viewData('page')['props']['provider']['service_groups'];
    $names = collect($groups)->flatMap(fn (array $group) => collect($group['services'])->pluck('name'))->all();
    $treccine = collect($groups)->firstWhere('category.name', 'Treccine');

    expect($names)->toContain('Servizio Dal Form')->not->toContain('Servizio Spento');
    expect(collect($treccine['services'])->pluck('name')->all())->toContain('Servizio Dal Form');

    $unpublished = psvProvider(name: 'Barberia Kofi');
    psvPost($unpublished->user, psvForm())->assertSessionHasNoErrors();
    app('auth')->forgetGuards();
    $this->get(route('providers.show', $unpublished->slug))->assertNotFound();
});

test('adding a service fills the service requirement for publishing', function () {
    $user = User::factory()->create();
    $provider = app(CreateProviderProfile::class)->create($user, new ProfileDetails('Salone Nuovo', 'Parma'));
    $requirements = app(PublishingRequirements::class);

    expect($requirements->missing($provider))->toBe(['service', 'availability']);

    psvPost($user, psvForm())->assertSessionHasNoErrors();
    expect($requirements->missing($provider))->toBe(['availability']);

    Availability::factory()->for($provider)->create();
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('publishing.can_publish', true));
});

test('the form of user B does not change the services of user A', function () {
    $first = psvProvider();
    $service = Service::factory()->for($first)->create(['name' => 'Di A']);
    $before = $service->fresh()->toArray();

    psvPost(psvProvider(name: 'Barberia Kofi')->user, psvForm())->assertSessionHasNoErrors();

    expect($service->fresh()->toArray())->toBe($before);
    expect($first->services()->count())->toBe(1);
});

test('the 21st new service in a minute of the same user is refused', function () {
    $provider = psvProvider();

    for ($i = 1; $i <= 20; $i++) {
        psvPost($provider->user, psvForm(['name' => "Servizio {$i}"]))->assertSessionHasNoErrors();
    }

    psvPost($provider->user, psvForm(['name' => 'Servizio 21']))
        ->assertRedirect(route('provider.services.index'))
        ->assertSessionHasErrors(['services' => 'Hai fatto troppe richieste in poco tempo. Riprova tra qualche minuto.']);
    expect($provider->services()->count())->toBe(20);

    $other = psvProvider(name: 'Barberia Kofi');
    psvPost($other->user, psvForm())->assertSessionHasNoErrors();

    $this->travel(61)->seconds();
    psvPost($provider->user, psvForm(['name' => 'Servizio 22']))->assertSessionHasNoErrors();
    expect($provider->services()->count())->toBe(21);
});

test('the addresses have no parameters and other methods are refused', function () {
    expect(route('provider.services.index', absolute: false))->toBe('/provider/services');
    expect(route('provider.services.store', absolute: false))->toBe('/provider/services');

    $user = psvProvider()->user;
    $this->actingAs($user)->put('/provider/services', psvForm())->assertStatus(405);
    $this->actingAs($user)->delete('/provider/services')->assertStatus(405);
    expect(Service::query()->count())->toBe(0);
});

test('the dashboard still gets five provider keys and two publishing keys', function () {
    $this->actingAs(psvProvider()->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('provider', 5)
            ->has('publishing', 2));
});

test('the Italian texts are exactly the expected ones', function () {
    expect(__('provider.services.validation.name_required'))->toBe('Scrivi il nome del servizio.');
    expect(__('provider.services.validation.name_max'))->toBe('Il nome può avere al massimo :max caratteri.');
    expect(__('provider.services.validation.description_max'))->toBe('La descrizione può avere al massimo :max caratteri.');
    expect(__('provider.services.validation.category_required'))->toBe('Scegli una categoria.');
    expect(__('provider.services.validation.category_invalid'))->toBe(PSV_CATEGORY_INVALID);
    expect(__('provider.services.validation.price_required'))->toBe('Scrivi il prezzo.');
    expect(__('provider.services.validation.price_invalid'))->toBe(PSV_PRICE_INVALID);
    expect(__('provider.services.validation.duration_min_required'))->toBe('Scrivi la durata minima.');
    expect(__('provider.services.validation.duration_max_required'))->toBe('Scrivi la durata massima.');
    expect(__('provider.services.validation.duration_invalid'))->toBe(PSV_DURATION_INVALID);
    expect(__('provider.services.validation.duration_order'))->toBe('La durata massima non può essere minore della minima.');
    expect(__('provider.services.created'))->toBe('Servizio aggiunto.');
    expect(__('provider.services.title'))->toBe('I tuoi servizi');
    expect(__('provider.services.description'))->toBe('Quello che offri ai clienti: nome, prezzo e durata.');
    expect(__('provider.services.empty'))->toBe('Non hai ancora aggiunto servizi.');
    expect(__('provider.services.add_title'))->toBe('Aggiungi un servizio');
    expect(__('provider.services.active'))->toBe('Attivo');
    expect(__('provider.services.inactive'))->toBe('Non attivo');
    expect(__('provider.services.form.name'))->toBe('Nome del servizio');
    expect(__('provider.services.form.description'))->toBe('Descrizione (facoltativa)');
    expect(__('provider.services.form.category'))->toBe('Categoria');
    expect(__('provider.services.form.category_placeholder'))->toBe('Scegli una categoria');
    expect(__('provider.services.form.price'))->toBe('Prezzo in euro');
    expect(__('provider.services.form.price_help'))->toBe('Per esempio 25 oppure 25,50.');
    expect(__('provider.services.form.duration_min'))->toBe('Durata minima (minuti)');
    expect(__('provider.services.form.duration_max'))->toBe('Durata massima (minuti)');
    expect(__('provider.services.form.duration_help'))->toBe('Il calendario blocca il tempo della durata massima.');
    expect(__('provider.services.form.submit'))->toBe('Aggiungi il servizio');
    expect(__('provider.area.services'))->toBe('I tuoi servizi');
});

test('the seeder still runs twice', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Service::query()->count())->toBe(10);
});
