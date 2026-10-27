<?php

use App\Booking\CreateBooking;
use App\Booking\CustomerDetails;
use App\Booking\FreeSlots;
use App\Booking\TimeWindow;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Notifications\NewBookingNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// "Now" is Tuesday 27 October 2026, 08:00 UTC. Monday 2 November is winter time (UTC+1): the
// providers work 09:00-13:00 local, 08:00-12:00 UTC.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
    config()->set('booking.cancellation_notice_minutes', 1440);
    app()->setLocale('it');
});

const PBA_THROTTLE = 'Hai fatto troppe richieste in poco tempo. Riprova tra qualche minuto.';

function pbaProvider(string $name = 'Salone Awa'): Provider
{
    $provider = Provider::factory()->create(['name' => $name, 'is_published' => true]);
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);

    return $provider;
}

function pbaService(Provider $provider): Service
{
    return Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);
}

/**
 * A booking of the provider on Monday 2 November, 08:00-09:00 UTC.
 *
 * @param  array<string, mixed>  $override
 */
function pbaBooking(Provider $provider, BookingStatus $status = BookingStatus::Pending, array $override = []): Booking
{
    return Booking::factory()->for($provider)->for($override['service'] ?? pbaService($provider))->create([
        'status' => $status,
        'starts_at' => CarbonImmutable::parse('2026-11-02 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-11-02 09:00', 'UTC'),
        ...array_diff_key($override, ['service' => true]),
    ]);
}

/**
 * Send one of the three actions.
 *
 * @param  array<string, mixed>  $data
 */
function pbaSend(?User $user, string $action, int|string $bookingId, array $data = []): TestResponse
{
    $test = $user === null ? test() : test()->actingAs($user);

    return $test->post(route("provider.bookings.{$action}", ['booking' => $bookingId]), $data);
}

/**
 * The columns that must not change when an action is refused.
 *
 * @return array<string, mixed>
 */
function pbaColumns(Booking $booking): array
{
    return (array) DB::table('bookings')->where('id', $booking->id)->first(['provider_id', 'status', 'cancellation_reason', 'starts_at', 'ends_at', 'updated_at']);
}

dataset('pba actions', ['confirm', 'reject', 'cancel']);

test('guests, unverified users and users without a provider are sent away', function (string $action) {
    $booking = pbaBooking(pbaProvider());
    $before = pbaColumns($booking);

    pbaSend(null, $action, $booking->id)->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    Provider::factory()->create(['user_id' => $unverified->id]);
    pbaSend($unverified, $action, $booking->id)->assertRedirect(route('verification.notice'));

    pbaSend(User::factory()->create(), $action, $booking->id)->assertRedirect(route('provider.setup'));

    expect(pbaColumns($booking))->toBe($before);
})->with('pba actions');

test('the same 404 for a booking of another provider, a missing id and one that is not a number, and nothing changes', function (string $action) {
    $mine = pbaProvider();
    $theirs = pbaBooking(pbaProvider('Barberia Kofi'), $action === 'cancel' ? BookingStatus::Confirmed : BookingStatus::Pending);
    $before = pbaColumns($theirs);

    foreach ([$theirs->id, 999999, 'abc'] as $id) {
        pbaSend($mine->user, $action, $id, ['reason' => 'Motivo'])->assertNotFound();
    }

    expect(pbaColumns($theirs))->toBe($before);
})->with('pba actions');

test('a GET on the actions is not allowed', function (string $action) {
    $provider = pbaProvider();
    $booking = pbaBooking($provider);

    $this->actingAs($provider->user)->get(route("provider.bookings.{$action}", ['booking' => $booking->id]))->assertMethodNotAllowed();
})->with('pba actions');

test('a pending booking in the future is confirmed', function () {
    $provider = pbaProvider();
    $booking = pbaBooking($provider);

    pbaSend($provider->user, 'confirm', $booking->id)
        ->assertRedirect(route('provider.bookings.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Prenotazione confermata.']);

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    expect($booking->fresh()->cancellation_reason)->toBeNull();
});

test('refused actions go back to the bookings page with the message of their reason, and nothing changes', function (string $action, BookingStatus $status, string $start, string $message) {
    $provider = pbaProvider();
    $booking = pbaBooking($provider, $status, ['starts_at' => CarbonImmutable::parse($start, 'UTC')]);
    $before = pbaColumns($booking);

    pbaSend($provider->user, $action, $booking->id, ['reason' => 'Un motivo'])
        ->assertRedirect(route('provider.bookings.index'))
        ->assertSessionHasErrors(['booking' => $message]);

    expect(pbaColumns($booking))->toBe($before);
})->with([
    'confirm a pending that started now' => ['confirm', BookingStatus::Pending, '2026-10-27 08:00', "L'orario è già iniziato: non si può più confermare. Puoi solo rifiutarla."],
    'confirm a past pending' => ['confirm', BookingStatus::Pending, '2026-10-20 08:00', "L'orario è già iniziato: non si può più confermare. Puoi solo rifiutarla."],
    'confirm a confirmed' => ['confirm', BookingStatus::Confirmed, '2026-11-02 08:00', 'Questa prenotazione non è più in attesa.'],
    'confirm a cancelled' => ['confirm', BookingStatus::Cancelled, '2026-11-02 08:00', 'Questa prenotazione è già stata annullata.'],
    'reject a confirmed' => ['reject', BookingStatus::Confirmed, '2026-11-02 08:00', 'Questa prenotazione non è più in attesa.'],
    'reject a cancelled' => ['reject', BookingStatus::Cancelled, '2026-11-02 08:00', 'Questa prenotazione è già stata annullata.'],
    'cancel a pending' => ['cancel', BookingStatus::Pending, '2026-11-02 08:00', 'Questa prenotazione non è confermata.'],
    'cancel a cancelled' => ['cancel', BookingStatus::Cancelled, '2026-11-02 08:00', 'Questa prenotazione è già stata annullata.'],
]);

test('reject and cancel, with and without a reason; HTML and Markdown are saved as written', function (string $action, BookingStatus $from, array $data, ?string $saved, string $toast) {
    $provider = pbaProvider();
    $booking = pbaBooking($provider, $from);

    pbaSend($provider->user, $action, $booking->id, $data)
        ->assertRedirect(route('provider.bookings.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $toast]);

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($booking->fresh()->cancellation_reason)->toBe($saved);
})->with([
    'reject with a reason' => ['reject', BookingStatus::Pending, ['reason' => '  Sono malata.  '], 'Sono malata.', "Richiesta rifiutata: l'orario è di nuovo libero."],
    'reject without a reason' => ['reject', BookingStatus::Pending, [], null, "Richiesta rifiutata: l'orario è di nuovo libero."],
    'reject with spaces only' => ['reject', BookingStatus::Pending, ['reason' => '    '], null, "Richiesta rifiutata: l'orario è di nuovo libero."],
    'reject a past pending' => ['reject', BookingStatus::Pending, ['reason' => 'Tardi'], 'Tardi', "Richiesta rifiutata: l'orario è di nuovo libero."],
    'cancel with a reason' => ['cancel', BookingStatus::Confirmed, ['reason' => 'Chiuso per lutto'], 'Chiuso per lutto', "Prenotazione annullata: l'orario è di nuovo libero."],
    'cancel without a reason' => ['cancel', BookingStatus::Confirmed, ['reason' => ''], null, "Prenotazione annullata: l'orario è di nuovo libero."],
    'HTML and Markdown' => ['cancel', BookingStatus::Confirmed, ['reason' => '<script>x</script> **no**'], '<script>x</script> **no**', "Prenotazione annullata: l'orario è di nuovo libero."],
]);

test('a reason of 500 characters is accepted, 501 are refused and nothing changes', function (string $action, BookingStatus $from) {
    $provider = pbaProvider();
    $long = pbaBooking($provider, $from);
    $before = pbaColumns($long);

    // Characters, not bytes: "è" counts as one.
    pbaSend($provider->user, $action, $long->id, ['reason' => str_repeat('è', 501)])
        ->assertRedirect(route('provider.bookings.index'))
        ->assertSessionHasErrors(['reason' => 'Il motivo può avere al massimo 500 caratteri.']);
    expect(pbaColumns($long))->toBe($before);

    pbaSend($provider->user, $action, $long->id, ['reason' => str_repeat('è', 500)])->assertSessionHasNoErrors();
    expect($long->fresh()->cancellation_reason)->toBe(str_repeat('è', 500));
})->with([
    'reject' => ['reject', BookingStatus::Pending],
    'cancel' => ['cancel', BookingStatus::Confirmed],
]);

test('the body cannot change anything else', function () {
    $provider = pbaProvider();
    $other = pbaProvider('Barberia Kofi');
    $booking = pbaBooking($provider);
    $startsAt = $booking->starts_at->toIso8601String();

    pbaSend($provider->user, 'confirm', $booking->id, [
        'status' => 'cancelled',
        'provider_id' => $other->id,
        'cancellation_reason' => 'dal corpo',
        'starts_at' => '2026-12-01T08:00:00Z',
    ])->assertSessionHasNoErrors();

    $fresh = $booking->fresh();
    expect($fresh->status)->toBe(BookingStatus::Confirmed);
    expect($fresh->provider_id)->toBe($provider->id);
    expect($fresh->cancellation_reason)->toBeNull();
    expect($fresh->starts_at->toIso8601String())->toBe($startsAt);
});

test('the customer cancels first: the provider gets "already cancelled" on confirm and nothing is written', function () {
    $provider = pbaProvider();
    $booking = pbaBooking($provider);

    $this->post(route('bookings.cancel', ['booking' => $booking->public_token]))->assertSessionHasNoErrors();
    $before = pbaColumns($booking);

    pbaSend($provider->user, 'confirm', $booking->id)
        ->assertRedirect(route('provider.bookings.index'))
        ->assertSessionHasErrors(['booking' => 'Questa prenotazione è già stata annullata.']);

    expect(pbaColumns($booking))->toBe($before);
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

test('a rejected booking frees its slot at once, and another customer can book it', function () {
    $provider = pbaProvider();
    $service = pbaService($provider);
    $booking = pbaBooking($provider, override: ['service' => $service]);
    $day = CarbonImmutable::parse('2026-11-02', config()->string('booking.timezone'));
    $starts = fn () => app(FreeSlots::class)
        ->slotsFor(Provider::query()->findOrFail($provider->id), $service, [], $day)
        ->map(fn (TimeWindow $slot) => $slot->start->format('H:i'))
        ->all();

    // 08:00 and 08:30 UTC overlap the booking.
    expect($starts())->not->toContain('08:00')->not->toContain('08:30');

    pbaSend($provider->user, 'reject', $booking->id)->assertSessionHasNoErrors();
    expect($starts())->toContain('08:00')->toContain('08:30');

    $again = app(CreateBooking::class)->create($provider, $service, [], CarbonImmutable::parse('2026-11-02 08:00', 'UTC'), new CustomerDetails('Fatou Ndiaye', '+39 333 765 4321'));
    expect($again->status)->toBe(BookingStatus::Pending);
    expect(Booking::query()->count())->toBe(2);
});

test('the list has the three buttons from the rules, and never the reason', function () {
    $provider = pbaProvider();
    $pending = pbaBooking($provider);
    $confirmed = pbaBooking($provider, BookingStatus::Confirmed, ['starts_at' => CarbonImmutable::parse('2026-11-02 10:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-11-02 11:00', 'UTC')]);
    $cancelled = pbaBooking($provider, BookingStatus::Confirmed, ['starts_at' => CarbonImmutable::parse('2026-11-02 11:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-11-02 12:00', 'UTC')]);
    pbaSend($provider->user, 'cancel', $cancelled->id, ['reason' => 'MOTIVO SEGRETO DEL PROVIDER'])->assertSessionHasNoErrors();

    $response = $this->actingAs($provider->user)->get(route('provider.bookings.index'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('provider/bookings/index')
        ->where('bookings.data.0.id', $pending->id)
        ->where('bookings.data.0.can_confirm', true)
        ->where('bookings.data.0.can_reject', true)
        ->where('bookings.data.0.can_cancel', false)
        ->where('bookings.data.1.id', $confirmed->id)
        ->where('bookings.data.1.can_confirm', false)
        ->where('bookings.data.1.can_reject', false)
        ->where('bookings.data.1.can_cancel', true)
        ->where('bookings.data.2.id', $cancelled->id)
        ->where('bookings.data.2.can_confirm', false)
        ->where('bookings.data.2.can_reject', false)
        ->where('bookings.data.2.can_cancel', false));

    expect($response->getContent())->not->toContain('MOTIVO SEGRETO DEL PROVIDER');
    // The key in the bookings only: the shared translations of every page hold the customer page's
    // "booking.confirmation.cancellation_reason" text.
    expect(json_encode($response->viewData('page')['props']['bookings']))->not->toContain('cancellation_reason');
});

test('the private page shows the reason only on a cancelled booking', function () {
    $provider = pbaProvider();
    $cancelled = pbaBooking($provider, BookingStatus::Confirmed);
    pbaSend($provider->user, 'cancel', $cancelled->id, ['reason' => 'Chiuso per lutto'])->assertSessionHasNoErrors();
    // A reason left on a booking that is not cancelled (it cannot happen through the actions).
    $confirmed = pbaBooking($provider, BookingStatus::Confirmed, ['starts_at' => CarbonImmutable::parse('2026-11-02 10:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-11-02 11:00', 'UTC')]);
    DB::table('bookings')->where('id', $confirmed->id)->update(['cancellation_reason' => 'NON DEVE COMPARIRE']);

    app('auth')->forgetGuards();
    $this->get(route('bookings.show', ['booking' => $cancelled->public_token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('booking.status', 'cancelled')->where('booking.cancellation_reason', 'Chiuso per lutto'));

    $this->get(route('bookings.show', ['booking' => $confirmed->public_token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('booking.cancellation_reason', null))
        ->assertDontSee('NON DEVE COMPARIRE');
});

test('the reason is not in the email to the provider', function () {
    $provider = pbaProvider();
    $booking = pbaBooking($provider, BookingStatus::Confirmed);
    pbaSend($provider->user, 'cancel', $booking->id, ['reason' => 'MOTIVO NELLA MAIL'])->assertSessionHasNoErrors();

    $mail = (new NewBookingNotification($booking->fresh()))->toMail($provider->user);

    expect((string) $mail->render())->not->toContain('MOTIVO NELLA MAIL');
    expect(view($mail->view['text'], $mail->viewData)->render())->not->toContain('MOTIVO NELLA MAIL');
});

test('the 31st action in a minute goes back to the bookings page; services and hours still go back to theirs', function () {
    $provider = pbaProvider();
    $booking = pbaBooking($provider, BookingStatus::Confirmed);
    $service = pbaService($provider);
    $slot = $provider->availabilities()->sole();

    // Refused confirmations count as well.
    for ($i = 1; $i <= 30; $i++) {
        pbaSend($provider->user, 'confirm', $booking->id)->assertSessionHasErrors(['booking' => 'Questa prenotazione non è più in attesa.']);
    }

    pbaSend($provider->user, 'cancel', $booking->id)
        ->assertRedirect(route('provider.bookings.index'))
        ->assertSessionHasErrors(['services' => PBA_THROTTLE]);
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    // The same limiter, so the same counter, shared with services, options and hours: each answer
    // still goes back to its own page.
    $this->actingAs($provider->user)->post(route('provider.services.deactivate', $service->id))
        ->assertRedirect(route('provider.services.index'))
        ->assertSessionHasErrors(['services' => PBA_THROTTLE]);
    $this->actingAs($provider->user)->put(route('provider.availability.update', $slot->id), ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00'])
        ->assertRedirect(route('provider.availability.index'))
        ->assertSessionHasErrors(['services' => PBA_THROTTLE]);

    $other = pbaProvider('Barberia Kofi');
    pbaSend($other->user, 'confirm', pbaBooking($other)->id)->assertSessionHasNoErrors();
});

test('the Italian texts are exactly the expected ones', function () {
    $texts = [
        'provider.bookings.confirmed' => 'Prenotazione confermata.',
        'provider.bookings.rejected' => "Richiesta rifiutata: l'orario è di nuovo libero.",
        'provider.bookings.cancelled_by_provider' => "Prenotazione annullata: l'orario è di nuovo libero.",
        'provider.bookings.transition.not_pending' => 'Questa prenotazione non è più in attesa.',
        'provider.bookings.transition.not_confirmed' => 'Questa prenotazione non è confermata.',
        'provider.bookings.transition.already_started' => "L'orario è già iniziato: non si può più confermare. Puoi solo rifiutarla.",
        'provider.bookings.transition.already_cancelled' => 'Questa prenotazione è già stata annullata.',
        'provider.bookings.validation.reason_max' => 'Il motivo può avere al massimo :max caratteri.',
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});

test('the texts of the buttons, the windows and the customer page are exactly the expected ones', function () {
    $texts = [
        'provider.bookings.actions.confirm' => 'Conferma',
        'provider.bookings.actions.reject' => 'Rifiuta',
        'provider.bookings.actions.cancel' => 'Annulla la prenotazione',
        'provider.bookings.actions.reject_title' => 'Vuoi rifiutare la richiesta?',
        'provider.bookings.actions.cancel_title' => 'Vuoi annullare la prenotazione?',
        'provider.bookings.actions.reason_label' => 'Motivo (facoltativo)',
        'provider.bookings.actions.reason_help' => 'Il cliente lo vedrà nella sua pagina. Scrivi solo quello che vuoi che legga.',
        'provider.bookings.actions.reject_description' => "L'orario tornerà libero subito.",
        'provider.bookings.actions.cancel_description' => "L'orario tornerà libero subito. L'operazione non si può annullare.",
        'provider.bookings.actions.confirm_yes_reject' => 'Sì, rifiuta',
        'provider.bookings.actions.confirm_yes_cancel' => 'Sì, annulla',
        'provider.bookings.actions.back' => 'No, torna indietro',
        'provider.bookings.no_notice' => 'Il cliente non riceve ancora un avviso automatico: contattalo tu.',
        'booking.confirmation.cancellation_reason' => "Motivo dell'annullamento",
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});

test('the buttons of the bookings page follow the three flags of the server', function () {
    // Read in the page source: there is no JavaScript test runner.
    $source = file_get_contents(resource_path('js/pages/provider/bookings/index.tsx'));

    // Each button only under its own flag, sent by the server: the page computes no rule.
    expect($source)
        ->toMatch('/\{booking\.can_confirm && \(\s*<ConfirmBookingButton\b/')
        ->toMatch('/\{booking\.can_reject && \(\s*<ReasonDialog\s+action=\{rejectBooking\.form\(/')
        ->toMatch('/\{booking\.can_cancel && \(\s*<ReasonDialog\s+action=\{cancelBooking\.form\(/');
});

test('the customer page shows the reason as plain text, only on a cancelled booking that has one', function () {
    // Read in the page source: there is no JavaScript test runner.
    $source = file_get_contents(resource_path('js/pages/bookings/show.tsx'));

    expect($source)->not->toContain('dangerouslySetInnerHTML');
    // Printed once, as a JSX expression (escaped by React), and only inside both conditions.
    expect(substr_count($source, '{booking.cancellation_reason}'))->toBe(1);
    expect($source)->toMatch(
        // Inside the fragment <>...</> that follows the two conditions.
        "/\\{booking\\.status === 'cancelled' &&\\s*booking\\.cancellation_reason !== null && \\(\\s*<>(?:(?!<\\/>).)*\\{booking\\.cancellation_reason\\}/s",
    );
});
