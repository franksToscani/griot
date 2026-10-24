<?php

use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Models\User;
use App\Notifications\NewBookingNotification;
use App\Profile\ManageOption;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// "Now" is Sunday 18 October 2026, 08:00 UTC. The providers work on Mondays 09:00-13:00 local:
// Monday 19 October has bookable slots from 07:00 UTC.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    app()->setLocale('it');
    $this->pohCategory = Category::factory()->create(['name' => 'Treccine', 'slug' => 'treccine']);
});

const POH_OPTIONS_INVALID = 'Alcune opzioni scelte non appartengono a questo servizio.';

function pohProvider(string $name = 'Salone Awa'): Provider
{
    $provider = Provider::factory()->published()->create(['name' => $name]);
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);

    return $provider;
}

function pohService(Provider $provider, string $name = 'Box braids'): Service
{
    return Service::factory()->for($provider)->for(test()->pohCategory)->create(['name' => $name, 'duration_min' => 60, 'duration_max' => 60]);
}

/**
 * @param  array<string, mixed>  $override
 */
function pohOption(Service $service, array $override = []): ServiceOption
{
    return ServiceOption::factory()->for($service)->create(['name' => 'Perline', 'extra_price' => '5.00', 'extra_duration' => 15, ...$override]);
}

/**
 * @param  array<string, mixed>  $data
 */
function pohSend(?User $user, string $action, int|string $serviceId, int|string $optionId, array $data = []): TestResponse
{
    $test = $user === null ? test() : test()->actingAs($user);

    return $test->post(route("provider.services.options.{$action}", ['service' => $serviceId, 'option' => $optionId]), $data);
}

/**
 * @return array<string, mixed>
 */
function pohColumns(ServiceOption $option): array
{
    return $option->fresh()->only(['service_id', 'name', 'extra_price', 'extra_duration', 'is_active']);
}

/**
 * The options shown on the public page for a service, as a guest.
 *
 * @return list<array<string, mixed>>
 */
function pohPublicOptions(Provider $provider, Service $service): array
{
    app('auth')->forgetGuards();
    $groups = test()->get(route('providers.show', $provider->slug))->assertOk()->viewData('page')['props']['provider']['service_groups'];

    return collect($groups)->flatMap(fn (array $group) => $group['services'])->firstWhere('id', $service->id)['options'];
}

/**
 * The booking form of the public page, as a guest.
 *
 * @param  list<int>  $optionIds
 */
function pohBook(Provider $provider, Service $service, array $optionIds): TestResponse
{
    app('auth')->forgetGuards();

    return test()->post(route('providers.bookings.store', $provider->slug), [
        'service' => $service->id,
        'options' => $optionIds,
        'starts_at' => '2026-10-19T07:00:00Z',
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+39 333 123 4567',
    ]);
}

dataset('poh actions', ['deactivate', 'activate']);

test('guests, unverified users and users without a provider are sent away; a GET is refused', function (string $action) {
    $service = pohService(pohProvider());
    $option = pohOption($service, ['is_active' => $action === 'deactivate']);
    $before = pohColumns($option);

    pohSend(null, $action, $service->id, $option->id)->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    Provider::factory()->create(['user_id' => $unverified->id]);
    pohSend($unverified, $action, $service->id, $option->id)->assertRedirect(route('verification.notice'));

    pohSend(User::factory()->create(), $action, $service->id, $option->id)->assertRedirect(route('provider.setup'));

    $this->actingAs($service->provider->user)
        ->get(route("provider.services.options.{$action}", ['service' => $service->id, 'option' => $option->id]))
        ->assertStatus(405);

    expect(pohColumns($option))->toBe($before);
})->with('poh actions');

test('the same 404 for any id that is not the user\'s, and nothing changes', function (string $action) {
    $mine = pohProvider();
    $myService = pohService($mine);
    $optionOfMyOtherService = pohOption(pohService($mine, 'Cornrows'), ['is_active' => $action === 'deactivate']);
    $theirService = pohService(pohProvider('Barberia Kofi'));
    $theirOption = pohOption($theirService, ['is_active' => $action === 'deactivate']);
    $watched = [$optionOfMyOtherService, $theirOption];
    $before = array_map(fn (ServiceOption $option) => pohColumns($option), $watched);

    foreach ([
        'service of another provider' => [$theirService->id, $theirOption->id],
        'option of another service' => [$myService->id, $optionOfMyOtherService->id],
        'missing id' => [$myService->id, 999999],
        'not a number' => [$myService->id, 'abc'],
    ] as [$serviceId, $optionId]) {
        pohSend($mine->user, $action, $serviceId, $optionId)->assertNotFound();
    }

    expect(array_map(fn (ServiceOption $option) => pohColumns($option), $watched))->toBe($before);
})->with('poh actions');

test('hiding and showing again, both idempotent, the rest unchanged and the body ignored', function () {
    $provider = pohProvider();
    $service = pohService($provider);
    $option = pohOption($service);
    $page = route('provider.services.options.index', $service->id);

    pohSend($provider->user, 'deactivate', $service->id, $option->id, ['is_active' => '1', 'service_id' => 999, 'id' => 999])
        ->assertRedirect($page)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Opzione nascosta: i nuovi clienti non la vedono più.']);
    expect(pohColumns($option))->toBe(['service_id' => $service->id, 'name' => 'Perline', 'extra_price' => '5.00', 'extra_duration' => 15, 'is_active' => false]);

    $updatedAt = $option->fresh()->updated_at->toIso8601String();
    $this->travel(1)->hour();
    pohSend($provider->user, 'deactivate', $service->id, $option->id)->assertRedirect($page);
    expect($option->fresh()->updated_at->toIso8601String())->toBe($updatedAt);

    pohSend($provider->user, 'activate', $service->id, $option->id, ['is_active' => '0'])
        ->assertRedirect($page)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Opzione di nuovo visibile ai clienti.']);
    expect(pohColumns($option))->toBe(['service_id' => $service->id, 'name' => 'Perline', 'extra_price' => '5.00', 'extra_duration' => 15, 'is_active' => true]);

    $updatedAt = $option->fresh()->updated_at->toIso8601String();
    $this->travel(1)->hour();
    pohSend($provider->user, 'activate', $service->id, $option->id)->assertRedirect($page);
    expect($option->fresh()->updated_at->toIso8601String())->toBe($updatedAt);
});

test('a hidden option is not on the public page, the others are, and it comes back', function () {
    $provider = pohProvider();
    $service = pohService($provider);
    $visible = pohOption($service, ['name' => 'Perline']);
    $hidden = pohOption($service, ['name' => 'Extension']);

    pohSend($provider->user, 'deactivate', $service->id, $hidden->id)->assertSessionHasNoErrors();
    expect(collect(pohPublicOptions($provider, $service))->pluck('id')->all())->toBe([$visible->id]);

    pohSend($provider->user, 'activate', $service->id, $hidden->id)->assertSessionHasNoErrors();
    expect(collect(pohPublicOptions($provider, $service))->pluck('id')->all())->toBe([$visible->id, $hidden->id]);
});

test('the public option has exactly four keys, and a service with only hidden options shows none', function () {
    $provider = pohProvider();
    $service = pohService($provider);
    $option = pohOption($service);

    expect(pohPublicOptions($provider, $service))->toBe([['id' => $option->id, 'name' => 'Perline', 'extra_price' => '5.00', 'extra_duration' => 15]]);

    $option->forceFill(['is_active' => false])->save();
    expect(pohPublicOptions($provider, $service))->toBe([]);
});

test('the slots refuse a hidden option like an option of another service', function () {
    $provider = pohProvider();
    $service = pohService($provider);
    $visible = pohOption($service, ['name' => 'Perline']);
    $hidden = pohOption($service, ['name' => 'Extension', 'is_active' => false]);
    $foreign = pohOption(pohService($provider, 'Cornrows'), ['name' => 'Di un altro servizio']);
    $slots = fn (array $optionIds) => $this->getJson(route('providers.slots', ['publishedProvider' => $provider->slug, 'service' => $service->id, 'date' => '2026-10-19', 'options' => $optionIds]));

    $slots([$hidden->id])->assertStatus(422)->assertJsonValidationErrors(['options' => POH_OPTIONS_INVALID]);
    $slots([$foreign->id])->assertStatus(422)->assertJsonValidationErrors(['options' => POH_OPTIONS_INVALID]);
    expect($slots([$visible->id])->assertOk()->json('slots'))->not->toBeEmpty();
});

test('the booking form refuses a hidden option and accepts a visible one', function () {
    $provider = pohProvider();
    $service = pohService($provider);
    $visible = pohOption($service, ['name' => 'Perline', 'extra_duration' => 15]);
    $hidden = pohOption($service, ['name' => 'Extension', 'is_active' => false]);

    pohBook($provider, $service, [$hidden->id])->assertSessionHasErrors(['options' => POH_OPTIONS_INVALID]);
    expect(Booking::query()->count())->toBe(0);

    pohBook($provider, $service, [$visible->id])->assertSessionHasNoErrors();
    // 60 minutes of the service (duration_max) plus 15 of the option.
    expect(Booking::query()->sole()->ends_at->toIso8601String())->toBe('2026-10-19T08:15:00+00:00');
});

test('a booking made before the option is hidden keeps it everywhere', function () {
    $provider = pohProvider();
    $service = pohService($provider);
    $option = pohOption($service, ['name' => 'Perline', 'extra_price' => '5.00', 'extra_duration' => 15]);

    // First the booking, through the real form, with the option still visible.
    pohBook($provider, $service, [$option->id])->assertSessionHasNoErrors();
    $booking = Booking::query()->sole();

    // Then the provider hides it, through the real route.
    pohSend($provider->user, 'deactivate', $service->id, $option->id)->assertSessionHasNoErrors();
    expect($option->fresh()->is_active)->toBeFalse();

    // The private page still shows it, with its price.
    app('auth')->forgetGuards();
    $this->get(route('bookings.show', ['booking' => $booking->public_token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('booking.options', [['name' => 'Perline', 'extra_price' => '5.00']]));

    // The link and the times do not change.
    $fresh = $booking->fresh();
    expect(DB::table('booking_service_option')->where('booking_id', $booking->id)->pluck('service_option_id')->all())->toBe([$option->id]);
    expect($fresh->starts_at->toIso8601String())->toBe('2026-10-19T07:00:00+00:00');
    expect($fresh->ends_at->toIso8601String())->toBe('2026-10-19T08:15:00+00:00');

    // The email built after the option was hidden still lists it.
    $mail = (new NewBookingNotification($fresh))->toMail($provider->user);
    expect(view($mail->view['text'], $mail->viewData)->render())->toContain('Opzioni: Perline');

    // And it still blocks its own deletion.
    expect(app(ManageOption::class)->blockedBy($option->fresh()))->toBe('bookings');
});

test('a hidden option never booked can be deleted, a booked one cannot', function () {
    $provider = pohProvider();
    $service = pohService($provider);
    $free = pohOption($service, ['name' => 'Mai prenotata', 'is_active' => false]);
    $booked = pohOption($service, ['name' => 'Prenotata', 'is_active' => false]);
    $booking = Booking::factory()->for($provider)->for($service)->create();
    $booking->options()->attach($booked->id);

    $this->actingAs($provider->user)->delete(route('provider.services.options.destroy', ['service' => $service->id, 'option' => $free->id]))
        ->assertSessionHasNoErrors();
    expect(ServiceOption::query()->whereKey($free->id)->exists())->toBeFalse();

    $this->actingAs($provider->user)->delete(route('provider.services.options.destroy', ['service' => $service->id, 'option' => $booked->id]))
        ->assertSessionHasErrors(['option' => 'Questa opzione è già stata scelta in alcune prenotazioni e non si può eliminare.']);
    expect(ServiceOption::query()->whereKey($booked->id)->exists())->toBeTrue();
});

test('the limit of 20 and the unique name count the hidden options too', function () {
    $provider = pohProvider();
    $service = pohService($provider);
    ServiceOption::factory()->count(19)->for($service)->sequence(fn ($sequence) => ['name' => "Opzione {$sequence->index}"])->create();
    pohOption($service, ['name' => 'Nascosta', 'is_active' => false]);
    $store = fn (string $name) => $this->actingAs($provider->user)->post(route('provider.services.options.store', $service->id), ['name' => $name, 'extra_price' => '0', 'extra_duration' => 0]);

    // 19 visible + 1 hidden = 20: the 21st is refused.
    $store('La ventunesima')->assertSessionHasErrors(['name' => 'Un servizio può avere al massimo 20 opzioni.']);
    expect($service->options()->count())->toBe(20);

    // A free place, and the name of the hidden option is still taken.
    ServiceOption::query()->where('name', 'Opzione 0')->delete();
    $store('Nascosta')->assertSessionHasErrors(['name' => "Hai già un'opzione con questo nome per questo servizio."]);
});

test('the options page has six keys with is_active, and its queries do not grow', function () {
    $provider = pohProvider();
    $service = pohService($provider);
    $hidden = pohOption($service, ['name' => 'Nascosta', 'is_active' => false]);

    $this->actingAs($provider->user)
        ->get(route('provider.services.options.index', $service->id))
        ->assertInertia(fn (Assert $page) => $page->where('options', [
            ['id' => $hidden->id, 'name' => 'Nascosta', 'extra_price' => '5.00', 'extra_duration' => 15, 'used' => false, 'is_active' => false],
        ]));

    // A provider each: actingAs() keeps the same User object across requests of one user.
    $count = function (int $options) {
        $provider = pohProvider("Salone {$options}");
        $service = pohService($provider);

        for ($i = 1; $i <= $options; $i++) {
            pohOption($service, ['name' => "Opzione {$i}", 'is_active' => $i % 2 === 0]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->actingAs($provider->user)->get(route('provider.services.options.index', $service->id))->assertOk();

        return $queries;
    };

    expect($count(5))->toBe($count(1));
});

test('hiding and showing share the counter of provider-services-manage', function () {
    $provider = pohProvider();
    $service = pohService($provider);
    $option = pohOption($service);

    for ($i = 1; $i <= 15; $i++) {
        pohSend($provider->user, 'deactivate', $service->id, $option->id)->assertSessionHasNoErrors();
        pohSend($provider->user, 'activate', $service->id, $option->id)->assertSessionHasNoErrors();
    }

    // The 31st action of the same user, here an edit, is refused.
    $this->actingAs($provider->user)
        ->put(route('provider.services.options.update', ['service' => $service->id, 'option' => $option->id]), ['name' => 'Nuovo nome', 'extra_price' => '1', 'extra_duration' => 0])
        ->assertSessionHasErrors(['services' => 'Hai fatto troppe richieste in poco tempo. Riprova tra qualche minuto.']);
    pohSend($provider->user, 'deactivate', $service->id, $option->id)->assertSessionHasErrors('services');
    expect(pohColumns($option))->toMatchArray(['name' => 'Perline', 'is_active' => true]);

    $other = pohProvider('Barberia Kofi');
    $theirs = pohOption(pohService($other));
    pohSend($other->user, 'deactivate', $theirs->service_id, $theirs->id)->assertSessionHasNoErrors();
});

test('the Italian texts are exactly the expected ones', function () {
    expect(__('provider.options.deactivated'))->toBe('Opzione nascosta: i nuovi clienti non la vedono più.');
    expect(__('provider.options.activated'))->toBe('Opzione di nuovo visibile ai clienti.');
});

test('the texts of the hide button and its window are exactly the expected ones', function () {
    $texts = [
        'provider.options.hide_button' => 'Nascondi',
        'provider.options.hide_confirm_title' => "Vuoi nascondere l'opzione?",
        'provider.options.hide_confirm_description' => 'I nuovi clienti non la vedranno più. Le prenotazioni già fatte la mantengono. Potrai mostrarla di nuovo.',
        'provider.options.hide_confirm_yes' => 'Sì, nascondila',
        'provider.options.hide_confirm_no' => 'No, lasciala visibile',
        'provider.options.show_button' => 'Mostra',
        'provider.options.hidden_badge' => 'Nascosta',
        // Changed in this phase: it now suggests to hide the option.
        'provider.options.delete_blocked' => 'Questa opzione è già stata scelta in alcune prenotazioni e non si può eliminare: puoi nasconderla.',
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});

test('all the options of the seeder are visible', function () {
    $this->seed(DatabaseSeeder::class);

    expect(ServiceOption::query()->count())->toBe(14);
    expect(ServiceOption::query()->where('is_active', false)->count())->toBe(0);
});
