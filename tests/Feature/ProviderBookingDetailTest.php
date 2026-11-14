<?php

use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Notifications\NewBookingNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Inertia\Testing\AssertableInertia as Assert;

// The page of one booking in the provider's area (docs/bookings.md, "Pagina di una prenotazione"),
// the link to it in the email "nuova prenotazione", and where an answer comes back to.
// "Now" is Tuesday 27 October 2026, 08:00 UTC; the bookings are on Monday 2 November.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
    config()->set('booking.cancellation_notice_minutes', 1440);
    app()->setLocale('it');
});

function pbdProvider(string $name = 'Salone Awa'): Provider
{
    $provider = Provider::factory()->create(['name' => $name, 'is_published' => true]);
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);

    return $provider;
}

/**
 * A booking of the provider on Monday 2 November, 08:00-09:00 UTC (09:00-10:00 in Rome).
 *
 * @param  array<string, mixed>  $override
 */
function pbdBooking(Provider $provider, BookingStatus $status = BookingStatus::Pending, array $override = []): Booking
{
    $service = Service::factory()->for($provider)->create(['name' => 'Treccine', 'duration_min' => 60, 'duration_max' => 60]);

    return Booking::factory()->for($provider)->for($service)->create([
        'status' => $status,
        'starts_at' => CarbonImmutable::parse('2026-11-02 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-11-02 09:00', 'UTC'),
        'customer_name' => 'Awa Diop',
        'customer_email' => 'awa@example.test',
        ...$override,
    ]);
}

function pbdMail(Booking $booking): MailMessage
{
    return (new NewBookingNotification($booking))->toMail($booking->provider->user);
}

function pbdSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

const PBD_ACTION = 'Apri la richiesta per confermarla o rifiutarla';

// ---------------------------------------------------------------------------------------------
// The email
// ---------------------------------------------------------------------------------------------

test('the email links to the booking\'s page, an absolute address with the id only, in HTML and in text', function () {
    $booking = pbdBooking(pbdProvider());
    $mail = pbdMail($booking);
    $url = route('provider.bookings.show', ['booking' => $booking->id]);

    expect($url)->toBe(url("/provider/bookings/{$booking->id}"))->toStartWith('http');
    expect($mail->viewData['url'])->toBe($url);

    $html = (string) $mail->render();
    expect($html)->toContain('<a href="'.$url.'" style="color: #18181b;">'.PBD_ACTION.'</a>')
        ->and(substr_count($html, '<a '))->toBe(1);

    $text = view($mail->view['text'], $mail->viewData)->render();
    expect($text)->toContain(PBD_ACTION."\n".$url."\n");
});

test('the email never carries the customer\'s private address', function () {
    $booking = pbdBooking(pbdProvider());
    $mail = pbdMail($booking);
    $private = route('bookings.show', ['booking' => $booking->public_token]);

    foreach ([(string) $mail->render(), view($mail->view['text'], $mail->viewData)->render()] as $part) {
        expect($part)->not->toContain($booking->public_token)->not->toContain($private);
    }
});

// ---------------------------------------------------------------------------------------------
// The page
// ---------------------------------------------------------------------------------------------

test('the page shows the booking with its answers, private and never indexed', function () {
    $provider = pbdProvider();
    $booking = pbdBooking($provider, override: ['notes' => 'Porto io le extension.']);

    $this->actingAs($provider->user)
        ->get(route('provider.bookings.show', ['booking' => $booking->id]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page
            ->component('provider/bookings/show')
            ->where('booking.id', $booking->id)
            ->where('booking.status', 'pending')
            ->where('booking.customer_name', 'Awa Diop')
            ->where('booking.notes', 'Porto io le extension.')
            ->where('booking.service.name', 'Treccine')
            // Rome's time: 09:00-10:00 on Monday 2 November.
            ->where('booking.local_date', '2026-11-02')
            ->where('booking.local_start', '09:00')
            ->where('booking.local_end', '10:00')
            ->where('booking.can_confirm', true)
            ->where('booking.can_reject', true)
            ->where('booking.can_cancel', false)
            ->has('booking.price_cents')
            ->has('booking.whatsapp_url'));
});

test('a booking of another provider, or one that does not exist, is a 404, never a 403', function () {
    $mine = pbdProvider();
    $theirs = pbdBooking(pbdProvider('Salone Altro'));

    $this->actingAs($mine->user)->get(route('provider.bookings.show', ['booking' => $theirs->id]))->assertNotFound();
    $this->actingAs($mine->user)->get(route('provider.bookings.show', ['booking' => $theirs->id + 1000]))->assertNotFound();
    $this->actingAs($mine->user)->get('/provider/bookings/abc')->assertNotFound();
});

test('a user without a provider goes to the setup page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('provider.bookings.show', ['booking' => 1]))
        ->assertRedirect(route('provider.setup'));
});

test('a guest goes to the login, and after it comes back to the booking\'s page', function () {
    $provider = pbdProvider();
    $booking = pbdBooking($provider);
    $url = route('provider.bookings.show', ['booking' => $booking->id]);

    $this->get($url)->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => $provider->user->email, 'password' => 'password'])
        ->assertRedirect($url);
    $this->assertAuthenticatedAs($provider->user);
});

// ---------------------------------------------------------------------------------------------
// Where an answer comes back to
// ---------------------------------------------------------------------------------------------

test('an answer from the booking\'s page comes back to it, with its toast; from the list, to the list', function (string $action, BookingStatus $status, string $toast) {
    $provider = pbdProvider();
    $fromPage = pbdBooking($provider, $status);
    $fromList = pbdBooking($provider, $status, ['starts_at' => CarbonImmutable::parse('2026-11-02 10:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-11-02 11:00', 'UTC')]);

    $this->actingAs($provider->user)
        ->post(route("provider.bookings.{$action}", ['booking' => $fromPage->id]), ['return' => 'detail'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('provider.bookings.show', ['booking' => $fromPage->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $toast]);

    $this->actingAs($provider->user)
        ->post(route("provider.bookings.{$action}", ['booking' => $fromList->id]))
        ->assertRedirect(route('provider.bookings.index'));

    expect($fromPage->fresh()->status)->not->toBe($status)
        ->and($fromList->fresh()->status)->not->toBe($status);
})->with([
    'confirm' => ['confirm', BookingStatus::Pending, 'Prenotazione confermata.'],
    'reject' => ['reject', BookingStatus::Pending, "Richiesta rifiutata: l'orario è di nuovo libero."],
    'cancel' => ['cancel', BookingStatus::Confirmed, "Prenotazione annullata: l'orario è di nuovo libero."],
]);

test('a refused answer, a message too long and the request limit come back to the booking\'s page too', function () {
    $provider = pbdProvider();
    $confirmed = pbdBooking($provider, BookingStatus::Confirmed);
    $pending = pbdBooking($provider, override: ['starts_at' => CarbonImmutable::parse('2026-11-02 10:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-11-02 11:00', 'UTC')]);
    $page = fn (Booking $booking) => route('provider.bookings.show', ['booking' => $booking->id]);

    // A change the state does not allow.
    $this->actingAs($provider->user)
        ->post(route('provider.bookings.confirm', ['booking' => $confirmed->id]), ['return' => 'detail'])
        ->assertRedirect($page($confirmed))
        ->assertSessionHasErrors(['booking' => 'Questa prenotazione non è più in attesa.']);

    // A message over 500 characters.
    $this->actingAs($provider->user)
        ->post(route('provider.bookings.reject', ['booking' => $pending->id]), ['return' => 'detail', 'reason' => str_repeat('a', 501)])
        ->assertRedirect($page($pending))
        ->assertSessionHasErrors('reason');
    expect($pending->fresh()->status)->toBe(BookingStatus::Pending);

    // Over the limit of the answers (the limiter reads its value at each request).
    config()->set('booking.rate_limit_services_manage_per_minute', 2);
    $this->actingAs($provider->user)
        ->post(route('provider.bookings.cancel', ['booking' => $confirmed->id]), ['return' => 'detail'])
        ->assertRedirect($page($confirmed))
        ->assertSessionHasErrors(['services' => 'Hai fatto troppe richieste in poco tempo. Riprova tra qualche minuto.']);
    expect($confirmed->fresh()->status)->toBe(BookingStatus::Confirmed);
});

test('any other value of "return" goes back to the list, never to an address it names', function (mixed $value) {
    $provider = pbdProvider();
    $booking = pbdBooking($provider);

    $this->actingAs($provider->user)
        ->post(route('provider.bookings.confirm', ['booking' => $booking->id]), ['return' => $value])
        ->assertRedirect(route('provider.bookings.index'));
})->with([
    'another word' => ['details'],
    'an address' => ['https://evil.example.test/'],
    'a path' => ['/provider/bookings/1'],
    'empty' => [''],
    'an array' => [['detail']],
]);

// ---------------------------------------------------------------------------------------------
// The pages (no JavaScript test runner: the source is read)
// ---------------------------------------------------------------------------------------------

test('on the list the date of each booking is a link to its page; on the page it is plain text', function () {
    $card = pbdSource('js/components/provider-booking-card.tsx');

    expect($card)->toMatch('/\{onDetailPage \? \(\s*date\s*\) : \(\s*<Link\s+href=\{showBooking\(booking\.id\)\}\s+className=\{cn\(\s*\'underline underline-offset-4\',\s*touchTarget,?\s*\)\}\s*>\s*\{date\}\s*<\/Link>/')
        ->toContain('onDetailPage = false,');
    expect(pbdSource('js/pages/provider/bookings/index.tsx'))->toContain('<BookingCard booking={booking} />');
    expect(pbdSource('js/pages/provider/bookings/show.tsx'))->toContain('<BookingCard booking={booking} onDetailPage />');
});

test('every answer of the booking\'s page sends return=detail, and only there', function () {
    $card = pbdSource('js/components/provider-booking-card.tsx');

    expect($card)->toMatch('/return onDetailPage \? \(\s*<input type="hidden" name="return" value="detail" \/>\s*\) : null;/')
        // The confirmation and the window of the rejection and of the cancellation.
        ->and(substr_count($card, '<ReturnField onDetailPage={onDetailPage} />'))->toBe(2)
        ->and(substr_count($card, 'onDetailPage={onDetailPage}'))->toBe(5);
});

test('the booking\'s page: a link back to the list, one h1 for the focus, the refused answers announced', function () {
    $page = pbdSource('js/pages/provider/bookings/show.tsx');

    expect($page)
        ->toMatch("/<Link\\s+href=\\{bookingsIndex\\(\\)\\}\\s+className=\\{cn\\(\\s*'w-fit text-sm underline underline-offset-4',\\s*touchTarget,?\\s*\\)\\}\\s*>\\s*\\{t\\('provider\\.bookings\\.back'\\)\\}/")
        ->toMatch('/<h1\s+id=\{PAGE_TITLE_ID\}\s+tabIndex=\{-1\}/')
        ->toMatch('/<div ref=\{actionErrors\} className="max-w-2xl">\s*<InputError message=\{errors\.booking\} announce \/>\s*<InputError message=\{errors\.services\} announce \/>/')
        ->not->toContain('aria-label');
});

test('the new texts in the three languages', function () {
    $expected = [
        'it' => ['booking.notification.action' => PBD_ACTION, 'provider.bookings.back' => 'Torna alle prenotazioni', 'provider.bookings.show.title' => 'Dettagli della prenotazione'],
        'fr' => ['booking.notification.action' => 'Ouvrez la demande pour la confirmer ou la refuser', 'provider.bookings.back' => 'Retour aux réservations', 'provider.bookings.show.title' => 'Détails de la réservation'],
        'en' => ['booking.notification.action' => 'Open the request to confirm or reject it', 'provider.bookings.back' => 'Back to bookings', 'provider.bookings.show.title' => 'Booking details'],
    ];

    foreach ($expected as $locale => $texts) {
        foreach ($texts as $key => $text) {
            expect(__($key, [], $locale))->toBe($text)
                // No ":", "?", "!" or ";" that would need the French no-break space.
                ->not->toMatch('/[:?!;]/');
        }
    }
});
