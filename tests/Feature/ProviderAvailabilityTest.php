<?php

use App\Booking\FreeSlots;
use App\Booking\TimeWindow;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// "Now" is Sunday 18 October 2026, 08:00 UTC. Monday 19 October is still summer time in Italy
// (UTC+2): 09:00 local is 07:00 UTC.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    app()->setLocale('it');
});

const PVA_MONDAY = '2026-10-19';
const PVA_TIME_INVALID = "Scrivi l'orario come ore e minuti, per esempio 09:00, a passi di 5 minuti.";
const PVA_END_BEFORE_START = "L'orario di fine deve essere dopo quello di inizio. Le fasce a cavallo di mezzanotte non sono supportate.";
const PVA_OVERLAP = "Questa fascia si sovrappone a un'altra dello stesso giorno.";
const PVA_THROTTLE = 'Hai fatto troppe richieste in poco tempo. Riprova tra qualche minuto.';

function pvaProvider(string $name = 'Salone Awa'): Provider
{
    return Provider::factory()->create(['name' => $name, 'is_published' => true]);
}

/**
 * A slot of the provider, with local times written as "HH:MM".
 */
function pvaSlot(Provider $provider, Weekday $weekday, string $start, string $end): Availability
{
    return Availability::factory()->for($provider)->create([
        'weekday' => $weekday,
        'start_time' => "{$start}:00",
        'end_time' => "{$end}:00",
    ]);
}

/**
 * A valid slot form (Monday 09:00-13:00), with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function pvaForm(array $override = []): array
{
    return ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00', ...$override];
}

/**
 * Send one of the four routes.
 *
 * @param  array<string, mixed>  $data
 */
function pvaSend(?User $user, string $route, int|string|null $availabilityId = null, array $data = []): TestResponse
{
    $test = $user === null ? test() : test()->actingAs($user);
    $url = route($route, $availabilityId === null ? [] : ['availability' => $availabilityId]);

    return match ($route) {
        'provider.availability.index' => $test->get($url),
        'provider.availability.store' => $test->post($url, $data === [] ? pvaForm() : $data),
        'provider.availability.update' => $test->put($url, $data === [] ? pvaForm() : $data),
        default => $test->delete($url),
    };
}

/**
 * The columns that must not change when an action is refused.
 *
 * @return array<string, mixed>
 */
function pvaColumns(Availability $availability): array
{
    return $availability->fresh()->only(['provider_id', 'weekday', 'start_time', 'end_time']);
}

/**
 * The start times, in UTC "H:i", of the slots of a 60 minute service on Monday 19 October,
 * computed from the database as it is now.
 *
 * @return list<string>
 */
function pvaMondaySlots(Provider $provider, Service $service): array
{
    $fresh = Provider::query()->findOrFail($provider->id);

    return app(FreeSlots::class)
        ->slotsFor($fresh, $service, [], CarbonImmutable::parse(PVA_MONDAY))
        ->map(fn (TimeWindow $slot) => $slot->start->format('H:i'))
        ->all();
}

/**
 * Run $run with a model listener on Availability, then remove it in any case. Availability has no
 * listeners of its own, so flushEventListeners() removes only this one.
 */
function pvaWhile(string $event, Closure $listener, Closure $run): mixed
{
    Availability::{$event}($listener);

    try {
        return $run();
    } finally {
        Availability::flushEventListeners();
    }
}

dataset('pva routes', [
    'index' => ['provider.availability.index', false],
    'store' => ['provider.availability.store', false],
    'update' => ['provider.availability.update', true],
    'destroy' => ['provider.availability.destroy', true],
]);

test('guests, unverified users and users without a provider are sent away', function (string $route, bool $withId) {
    $slot = pvaSlot(pvaProvider(), Weekday::Tuesday, '10:00', '12:00');
    $id = $withId ? $slot->id : null;

    pvaSend(null, $route, $id)->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    Provider::factory()->create(['user_id' => $unverified->id]);
    pvaSend($unverified, $route, $id)->assertRedirect(route('verification.notice'));

    pvaSend(User::factory()->create(), $route, $id)->assertRedirect(route('provider.setup'));

    expect(Availability::query()->count())->toBe(1);
    expect(pvaColumns($slot))->toBe(['provider_id' => $slot->provider_id, 'weekday' => Weekday::Tuesday, 'start_time' => '10:00:00', 'end_time' => '12:00:00']);
})->with('pva routes');

test('the same 404 for a slot of another provider, a missing id and one that is not a number, and nothing changes', function (string $route) {
    $mine = pvaProvider();
    $mySlot = pvaSlot($mine, Weekday::Monday, '09:00', '13:00');
    $theirSlot = pvaSlot(pvaProvider('Barberia Kofi'), Weekday::Monday, '09:00', '13:00');
    $before = [pvaColumns($mySlot), pvaColumns($theirSlot)];

    foreach ([$theirSlot->id, 999999, 'abc'] as $id) {
        pvaSend($mine->user, $route, $id, pvaForm(['start_time' => '15:00', 'end_time' => '16:00']))->assertNotFound();
    }

    expect([pvaColumns($mySlot), pvaColumns($theirSlot)])->toBe($before);
    expect(Availability::query()->count())->toBe(2);
})->with(['provider.availability.update', 'provider.availability.destroy']);

test('the page lists the own slots by day and start, with exactly four keys, and the seven days', function () {
    $provider = pvaProvider();
    $tuesday = pvaSlot($provider, Weekday::Tuesday, '09:00', '13:00');
    $mondayLate = pvaSlot($provider, Weekday::Monday, '15:00', '19:30');
    $mondayEarly = pvaSlot($provider, Weekday::Monday, '08:05', '12:00');
    $sunday = pvaSlot($provider, Weekday::Sunday, '10:00', '12:00');
    pvaSlot(pvaProvider('Barberia Kofi'), Weekday::Monday, '06:00', '07:00');

    $this->actingAs($provider->user)
        ->get(route('provider.availability.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('provider/availability/index')
            ->where('availabilities', [
                ['id' => $mondayEarly->id, 'weekday' => 1, 'start_time' => '08:05', 'end_time' => '12:00'],
                ['id' => $mondayLate->id, 'weekday' => 1, 'start_time' => '15:00', 'end_time' => '19:30'],
                ['id' => $tuesday->id, 'weekday' => 2, 'start_time' => '09:00', 'end_time' => '13:00'],
                ['id' => $sunday->id, 'weekday' => 7, 'start_time' => '10:00', 'end_time' => '12:00'],
            ])
            ->where('weekdays', [
                ['weekday' => 1, 'day' => 'monday'],
                ['weekday' => 2, 'day' => 'tuesday'],
                ['weekday' => 3, 'day' => 'wednesday'],
                ['weekday' => 4, 'day' => 'thursday'],
                ['weekday' => 5, 'day' => 'friday'],
                ['weekday' => 6, 'day' => 'saturday'],
                ['weekday' => 7, 'day' => 'sunday'],
            ]));
});

test('a provider without slots gets an empty list', function () {
    $this->actingAs(pvaProvider()->user)
        ->get(route('provider.availability.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('provider/availability/index')->where('availabilities', []));
});

test('a valid form adds the slot with normalized times, the body cannot move it', function () {
    $provider = pvaProvider();
    $other = pvaProvider('Barberia Kofi');

    pvaSend($provider->user, 'provider.availability.store', data: pvaForm([
        'weekday' => '3',
        'provider_id' => $other->id,
        'id' => 999,
    ]))
        ->assertRedirect(route('provider.availability.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Fascia aggiunta.']);

    $slot = Availability::query()->sole();
    expect($slot->provider_id)->toBe($provider->id);
    expect($slot->id)->not->toBe(999);
    expect($slot->only(['weekday', 'start_time', 'end_time']))->toBe(['weekday' => Weekday::Wednesday, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);
});

test('accepted times', function (string $start, string $end) {
    $provider = pvaProvider();

    pvaSend($provider->user, 'provider.availability.store', data: pvaForm(['start_time' => $start, 'end_time' => $end]))->assertSessionHasNoErrors();

    expect(Availability::query()->sole()->only(['start_time', 'end_time']))->toBe(['start_time' => "{$start}:00", 'end_time' => "{$end}:00"]);
})->with([
    'morning' => ['09:00', '13:00'],
    'from midnight' => ['00:00', '09:00'],
    'until 23:55' => ['09:00', '23:55'],
    'the whole day' => ['00:00', '23:55'],
    'on a step of 5' => ['08:05', '08:10'],
]);

test('refused times give one message on that field only', function (mixed $time, string $message) {
    $provider = pvaProvider();

    // An invalid start: one error on start_time, no "order" error on end_time.
    pvaSend($provider->user, 'provider.availability.store', data: pvaForm(['start_time' => $time]))
        ->assertSessionHasErrors(['start_time' => $message])
        ->assertSessionDoesntHaveErrors(['end_time', 'weekday']);

    // An invalid end: one error on end_time.
    pvaSend($provider->user, 'provider.availability.store', data: pvaForm(['end_time' => $time]))
        ->assertSessionHasErrors(['end_time' => $message])
        ->assertSessionDoesntHaveErrors(['start_time', 'weekday']);

    expect(Availability::query()->count())->toBe(0);
})->with([
    'one digit hour' => ['9:00', PVA_TIME_INVALID],
    '24:00' => ['24:00', PVA_TIME_INVALID],
    'not on a step of 5' => ['09:07', PVA_TIME_INVALID],
    'with seconds' => ['09:00:00', PVA_TIME_INVALID],
    'letters' => ['abc', PVA_TIME_INVALID],
    'HTML' => ['<b>09:00</b>', PVA_TIME_INVALID],
    'a list' => [['09:00'], PVA_TIME_INVALID],
    'empty' => ['', "Scrivi l'orario."],
]);

test('accepted and refused days', function (mixed $weekday, ?Weekday $saved) {
    $provider = pvaProvider();
    $response = pvaSend($provider->user, 'provider.availability.store', data: pvaForm(['weekday' => $weekday]));

    if ($saved === null) {
        $response->assertSessionHasErrors(['weekday' => 'Scegli un giorno della settimana.'])
            ->assertSessionDoesntHaveErrors(['start_time', 'end_time']);
        expect(Availability::query()->count())->toBe(0);
    } else {
        $response->assertSessionHasNoErrors();
        expect(Availability::query()->sole()->weekday)->toBe($saved);
    }
})->with([
    'Monday' => [1, Weekday::Monday],
    'Sunday as a string' => ['7', Weekday::Sunday],
    'zero' => [0, null],
    'eight' => [8, null],
    'letters' => ['lunedì', null],
    'a decimal' => ['1.5', null],
    'empty' => ['', null],
    'a list' => [[1], null],
]);

test('an end at or before the start is refused, also across midnight', function (string $start, string $end) {
    $provider = pvaProvider();

    pvaSend($provider->user, 'provider.availability.store', data: pvaForm(['start_time' => $start, 'end_time' => $end]))
        ->assertSessionHasErrors(['end_time' => PVA_END_BEFORE_START])
        ->assertSessionDoesntHaveErrors(['start_time', 'weekday']);

    expect(Availability::query()->count())->toBe(0);
})->with([
    'end before start' => ['13:00', '09:00'],
    'same time' => ['09:00', '09:00'],
    'across midnight' => ['22:00', '02:00'],
    'until midnight' => ['22:00', '00:00'],
]);

test('overlapping slots of the same day are refused on start_time', function (string $start, string $end) {
    $provider = pvaProvider();
    $existing = pvaSlot($provider, Weekday::Monday, '09:00', '13:00');
    $before = pvaColumns($existing);

    pvaSend($provider->user, 'provider.availability.store', data: pvaForm(['start_time' => $start, 'end_time' => $end]))
        ->assertRedirect(route('provider.availability.index'))
        ->assertSessionHasErrors(['start_time' => PVA_OVERLAP])
        ->assertSessionDoesntHaveErrors(['end_time', 'weekday']);

    expect(Availability::query()->count())->toBe(1);
    expect(pvaColumns($existing))->toBe($before);
})->with([
    'partial at the end' => ['12:00', '14:00'],
    'partial at the start' => ['08:00', '09:05'],
    'contained' => ['10:00', '11:00'],
    'containing' => ['08:00', '14:00'],
    'identical' => ['09:00', '13:00'],
]);

test('slots that touch, of another day or of another provider are accepted', function (int $weekday, string $start, string $end) {
    $provider = pvaProvider();
    pvaSlot($provider, Weekday::Monday, '09:00', '13:00');
    // Another provider, the same hours: it does not matter.
    pvaSlot(pvaProvider('Barberia Kofi'), Weekday::Monday, '13:00', '17:00');

    pvaSend($provider->user, 'provider.availability.store', data: pvaForm(['weekday' => $weekday, 'start_time' => $start, 'end_time' => $end]))
        ->assertSessionHasNoErrors();

    expect($provider->availabilities()->count())->toBe(2);
})->with([
    'touching after' => [1, '13:00', '17:00'],
    'touching before' => [1, '07:00', '09:00'],
    'another day' => [2, '09:00', '13:00'],
]);

test('editing: the slot does not conflict with itself, but with the others of the day it does', function () {
    $provider = pvaProvider();
    $slot = pvaSlot($provider, Weekday::Monday, '09:00', '13:00');
    $afternoon = pvaSlot($provider, Weekday::Monday, '15:00', '19:00');

    // Same values, then a shorter slot inside its own hours.
    pvaSend($provider->user, 'provider.availability.update', $slot->id, pvaForm())->assertSessionHasNoErrors();
    pvaSend($provider->user, 'provider.availability.update', $slot->id, pvaForm(['start_time' => '09:30', 'end_time' => '12:00']))
        ->assertRedirect(route('provider.availability.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Fascia aggiornata.']);
    expect(pvaColumns($slot))->toBe(['provider_id' => $provider->id, 'weekday' => Weekday::Monday, 'start_time' => '09:30:00', 'end_time' => '12:00:00']);

    // Up to the afternoon slot: touching it is fine, entering it is not.
    pvaSend($provider->user, 'provider.availability.update', $slot->id, pvaForm(['start_time' => '09:30', 'end_time' => '15:00']))->assertSessionHasNoErrors();
    pvaSend($provider->user, 'provider.availability.update', $slot->id, pvaForm(['start_time' => '09:30', 'end_time' => '15:05']))
        ->assertRedirect(route('provider.availability.index'))
        ->assertSessionHasErrors(['start_time' => PVA_OVERLAP]);

    expect(pvaColumns($slot)['end_time'])->toBe('15:00:00');
    expect(pvaColumns($afternoon))->toBe(['provider_id' => $provider->id, 'weekday' => Weekday::Monday, 'start_time' => '15:00:00', 'end_time' => '19:00:00']);
});

test('editing: the body cannot move the slot to another provider', function () {
    $provider = pvaProvider();
    $other = pvaProvider('Barberia Kofi');
    $slot = pvaSlot($provider, Weekday::Monday, '09:00', '13:00');

    pvaSend($provider->user, 'provider.availability.update', $slot->id, pvaForm([
        'weekday' => 2,
        'provider_id' => $other->id,
        'id' => 999,
    ]))->assertSessionHasNoErrors();

    expect(Availability::query()->sole()->only(['id', 'provider_id', 'weekday']))->toBe(['id' => $slot->id, 'provider_id' => $provider->id, 'weekday' => Weekday::Tuesday]);
});

test('the 8th slot of a day is created, the 9th is refused on weekday; another day is free', function () {
    $provider = pvaProvider();

    for ($hour = 0; $hour < 7; $hour++) {
        pvaSlot($provider, Weekday::Monday, sprintf('%02d:00', $hour), sprintf('%02d:30', $hour));
    }

    pvaSend($provider->user, 'provider.availability.store', data: pvaForm(['start_time' => '07:00', 'end_time' => '07:30']))->assertSessionHasNoErrors();
    pvaSend($provider->user, 'provider.availability.store', data: pvaForm(['start_time' => '20:00', 'end_time' => '21:00']))
        ->assertRedirect(route('provider.availability.index'))
        ->assertSessionHasErrors(['weekday' => 'Un giorno può avere al massimo 8 fasce.'])
        ->assertSessionDoesntHaveErrors(['start_time', 'end_time']);

    expect($provider->availabilities()->where('weekday', Weekday::Monday)->count())->toBe(8);

    pvaSend($provider->user, 'provider.availability.store', data: pvaForm(['weekday' => 2, 'start_time' => '20:00', 'end_time' => '21:00']))->assertSessionHasNoErrors();
});

test('editing: a slot of a full day can change its times, but no slot can move into a full day', function () {
    $provider = pvaProvider();

    for ($hour = 0; $hour < 8; $hour++) {
        pvaSlot($provider, Weekday::Monday, sprintf('%02d:00', $hour), sprintf('%02d:30', $hour));
    }

    $mondaySlot = $provider->availabilities()->where('start_time', '07:00:00')->sole();
    $tuesdaySlot = pvaSlot($provider, Weekday::Tuesday, '09:00', '13:00');

    pvaSend($provider->user, 'provider.availability.update', $mondaySlot->id, pvaForm(['start_time' => '07:00', 'end_time' => '07:55']))->assertSessionHasNoErrors();

    pvaSend($provider->user, 'provider.availability.update', $tuesdaySlot->id, pvaForm(['weekday' => 1, 'start_time' => '20:00', 'end_time' => '21:00']))
        ->assertRedirect(route('provider.availability.index'))
        ->assertSessionHasErrors(['weekday' => 'Un giorno può avere al massimo 8 fasce.']);

    expect(pvaColumns($tuesdaySlot))->toBe(['provider_id' => $provider->id, 'weekday' => Weekday::Tuesday, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);
});

test('documents a limit: an insert in the same transaction does not go through the lock', function () {
    // NOT a proof of the protection. Overlap and limit are checked in ManageAvailability after
    // lockForUpdate on the provider, which makes two REQUESTS wait for each other on MySQL. This
    // listener inserts an overlapping slot with the query builder inside the same transaction and
    // connection, after the check and before the insert, so it never meets the lock; SQLite, used by
    // the tests, ignores lockForUpdate anyway. The provider ends with two overlapping slots.
    $provider = pvaProvider();
    $inserted = false;

    pvaWhile('creating', function () use ($provider, &$inserted) {
        if (! $inserted) {
            $inserted = true;
            DB::table('availabilities')->insert([
                'provider_id' => $provider->id,
                'weekday' => 1,
                'start_time' => '10:00:00',
                'end_time' => '11:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }, fn () => pvaSend($provider->user, 'provider.availability.store', data: pvaForm())->assertSessionHasNoErrors());

    expect($inserted)->toBeTrue();
    expect($provider->availabilities()->orderBy('start_time')->get()->map(fn (Availability $slot) => [$slot->start_time, $slot->end_time])->all())
        ->toBe([['09:00:00', '13:00:00'], ['10:00:00', '11:00:00']]);
});

test('the listener of the test above is not registered any more', function () {
    expect(Event::hasListeners('eloquent.creating: '.Availability::class))->toBeFalse();
});

test('a slot is deleted, even the last one; the others do not change', function () {
    $provider = pvaProvider();
    $first = pvaSlot($provider, Weekday::Monday, '09:00', '13:00');
    $second = pvaSlot($provider, Weekday::Tuesday, '09:00', '13:00');
    $before = pvaColumns($second);

    pvaSend($provider->user, 'provider.availability.destroy', $first->id)
        ->assertRedirect(route('provider.availability.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Fascia eliminata.']);
    expect(Availability::query()->whereKey($first->id)->exists())->toBeFalse();
    expect(pvaColumns($second))->toBe($before);

    // The last one: allowed. The provider stays published and offers no slots.
    pvaSend($provider->user, 'provider.availability.destroy', $second->id)->assertRedirect(route('provider.availability.index'));
    expect($provider->availabilities()->count())->toBe(0);
    expect($provider->fresh()->is_published)->toBeTrue();
});

test('the slots offered change at once with create, update and delete; the bookings do not', function () {
    $provider = pvaProvider();
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);
    $option = ServiceOption::factory()->for($service)->create();
    // Monday 09:00-10:00 local (07:00-08:00 UTC), made when the provider had 09:00-11:00.
    $slot = pvaSlot($provider, Weekday::Monday, '09:00', '11:00');
    $booking = Booking::factory()->for($provider)->for($service)->create([
        'starts_at' => CarbonImmutable::parse(PVA_MONDAY.' 07:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse(PVA_MONDAY.' 08:00', 'UTC'),
    ]);
    $booking->options()->attach($option->id);
    $unchanged = fn () => [
        $booking->fresh()->starts_at->toIso8601String(),
        $booking->fresh()->ends_at->toIso8601String(),
        $booking->options()->pluck('service_options.id')->all(),
    ];
    $expected = ['2026-10-19T07:00:00+00:00', '2026-10-19T08:00:00+00:00', [$option->id]];

    // 09:00 is booked: only 10:00 local (08:00 UTC) is free.
    expect(pvaMondaySlots($provider, $service))->toBe(['08:00']);

    pvaSend($provider->user, 'provider.availability.store', data: pvaForm(['start_time' => '15:00', 'end_time' => '16:00']))->assertSessionHasNoErrors();
    expect(pvaMondaySlots($provider, $service))->toBe(['08:00', '13:00']);

    // The booked hour is no longer inside a slot: the booking stays as it was.
    pvaSend($provider->user, 'provider.availability.update', $slot->id, pvaForm(['start_time' => '10:30', 'end_time' => '12:00']))->assertSessionHasNoErrors();
    // 10:30-12:00 local is 08:30-10:00 UTC: two slots of 60 minutes, every 30.
    expect(pvaMondaySlots($provider, $service))->toBe(['08:30', '09:00', '13:00']);
    expect($unchanged())->toBe($expected);

    pvaSend($provider->user, 'provider.availability.destroy', $slot->id)->assertSessionHasNoErrors();
    expect(pvaMondaySlots($provider, $service))->toBe(['13:00']);
    expect($unchanged())->toBe($expected);
    expect(Booking::query()->count())->toBe(1);
});

test('the 21st new slot in a minute of the same user goes back to the hours page; another user is free', function () {
    $provider = pvaProvider();

    // Three a day at most: the limit of eight per day never gets in the way.
    for ($i = 0; $i < 20; $i++) {
        $start = sprintf('%02d:00', 8 + intdiv($i, 7));
        pvaSend($provider->user, 'provider.availability.store', data: pvaForm(['weekday' => $i % 7 + 1, 'start_time' => $start, 'end_time' => sprintf('%02d:30', 8 + intdiv($i, 7))]))
            ->assertSessionHasNoErrors();
    }

    pvaSend($provider->user, 'provider.availability.store', data: pvaForm(['start_time' => '20:00', 'end_time' => '21:00']))
        ->assertRedirect(route('provider.availability.index'))
        ->assertSessionHasErrors(['services' => PVA_THROTTLE]);
    expect(Availability::query()->count())->toBe(20);

    $other = pvaProvider('Barberia Kofi');
    pvaSend($other->user, 'provider.availability.store')->assertSessionHasNoErrors();
});

test('the 31st edit or delete in a minute goes back to the hours page; a service action still goes back to the services', function () {
    $provider = pvaProvider();
    $slot = pvaSlot($provider, Weekday::Monday, '09:00', '13:00');
    $service = Service::factory()->for($provider)->create();

    for ($i = 1; $i <= 30; $i++) {
        pvaSend($provider->user, 'provider.availability.update', $slot->id, pvaForm(['end_time' => $i % 2 === 0 ? '13:00' : '12:00']))->assertSessionHasNoErrors();
    }

    pvaSend($provider->user, 'provider.availability.destroy', $slot->id)
        ->assertRedirect(route('provider.availability.index'))
        ->assertSessionHasErrors(['services' => PVA_THROTTLE]);
    expect(Availability::query()->whereKey($slot->id)->exists())->toBeTrue();

    // The same limiter, so the same counter: a service action of this user is refused too, and its
    // answer still goes back to the services page.
    $this->actingAs($provider->user)
        ->post(route('provider.services.deactivate', $service->id))
        ->assertRedirect(route('provider.services.index'))
        ->assertSessionHasErrors(['services' => PVA_THROTTLE]);
    expect($service->fresh()->is_active)->toBeTrue();

    $other = pvaProvider('Barberia Kofi');
    $theirs = pvaSlot($other, Weekday::Monday, '09:00', '13:00');
    pvaSend($other->user, 'provider.availability.update', $theirs->id)->assertSessionHasNoErrors();
});

test('the Italian texts are exactly the expected ones', function () {
    $texts = [
        'provider.availability.validation.weekday_invalid' => 'Scegli un giorno della settimana.',
        'provider.availability.validation.time_required' => "Scrivi l'orario.",
        'provider.availability.validation.time_invalid' => "Scrivi l'orario come ore e minuti, per esempio 09:00, a passi di 5 minuti.",
        'provider.availability.validation.end_before_start' => "L'orario di fine deve essere dopo quello di inizio. Le fasce a cavallo di mezzanotte non sono supportate.",
        'provider.availability.validation.overlap' => "Questa fascia si sovrappone a un'altra dello stesso giorno.",
        'provider.availability.validation.limit_reached' => 'Un giorno può avere al massimo :max fasce.',
        'provider.availability.created' => 'Fascia aggiunta.',
        'provider.availability.updated' => 'Fascia aggiornata.',
        'provider.availability.deleted' => 'Fascia eliminata.',
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});

test('the texts of the hours page are exactly the expected ones', function () {
    $texts = [
        'provider.availability.title' => 'I tuoi orari',
        'provider.availability.description' => 'Quando lavori ogni settimana. I clienti possono prenotare solo dentro queste fasce.',
        'provider.availability.help_touch' => 'Due fasce che si toccano (per esempio 09:00-13:00 e 13:00-17:00) non permettono una prenotazione a cavallo delle 13:00.',
        'provider.availability.help_midnight' => 'Le fasce a cavallo di mezzanotte non sono supportate: usa due giorni separati.',
        'provider.availability.help_timezone' => 'Gli orari sono quelli italiani.',
        'provider.availability.closed' => 'Chiuso',
        'provider.availability.add_title' => 'Aggiungi una fascia',
        'provider.availability.form.weekday' => 'Giorno',
        'provider.availability.form.weekday_placeholder' => 'Scegli un giorno',
        'provider.availability.form.start' => 'Inizio',
        'provider.availability.form.end' => 'Fine',
        'provider.availability.form.submit' => 'Aggiungi la fascia',
        'provider.availability.form.save' => 'Salva le modifiche',
        'provider.availability.edit_button' => 'Modifica',
        'provider.availability.edit_title' => 'Modifica la fascia',
        'provider.availability.delete_button' => 'Elimina',
        'provider.availability.delete_confirm_title' => 'Vuoi eliminare la fascia?',
        'provider.availability.delete_confirm_description' => 'La fascia verrà cancellata. Le prenotazioni già fatte non cambiano.',
        'provider.availability.delete_confirm_yes' => 'Sì, eliminala',
        'provider.availability.delete_confirm_no' => 'No, tienila',
        'provider.area.availability' => 'I tuoi orari',
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});
