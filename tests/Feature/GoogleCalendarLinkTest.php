<?php

use App\Booking\CancelledBy;
use App\Booking\GoogleCalendarLink;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

// "Add to Google Calendar" for the provider (docs/bookings.md, App\Booking\GoogleCalendarLink): a
// plain link to Google Calendar with the event already written, for a confirmed booking that is
// not anonymous and still to come. Never the phone, the email, the notes, the reason of a
// cancellation nor the customer's private link. Fake values only.

const GCL_NAME = 'Cliente Prova';
const GCL_PHONE = '+39 000 000 0000';
const GCL_EMAIL = 'cliente@example.test';
const GCL_NOTES = 'Note riservate di prova';
const GCL_REASON = 'Motivo riservato di prova';

beforeEach(function () {
    // Now: 1 March 2026, 10:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00', 'UTC'));
});

/**
 * A confirmed booking on 26 November 2026, 14:00-16:00 in Italy (13:00-15:00 UTC), with one option.
 *
 * @param  array<string, mixed>  $override
 */
function gclBooking(array $override = [], string $service = 'Treccine'): Booking
{
    $provider = Provider::factory()->published()->create();
    $serviceModel = Service::factory()->for($provider)->create(['name' => $service]);
    $option = ServiceOption::factory()->for($serviceModel)->create(['name' => 'Perline']);

    $booking = Booking::factory()->for($provider)->for($serviceModel)->confirmed()->create([
        'starts_at' => CarbonImmutable::parse('2026-11-26 13:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-11-26 15:00', 'UTC'),
        'customer_name' => GCL_NAME,
        'customer_phone' => GCL_PHONE,
        'customer_email' => GCL_EMAIL,
        'notes' => GCL_NOTES,
        ...$override,
    ]);
    // A reason saved on the row, even on a confirmed booking: the link must never read it.
    $booking->forceFill(['cancellation_reason' => GCL_REASON])->save();
    attachBookingOptions($booking, $option);

    return $booking->load(['service', 'options']);
}

/**
 * The parameters of a link, decoded.
 *
 * @return array<string, string>
 */
function gclQuery(string $link): array
{
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    return $query;
}

test('the link opens Google Calendar with the service, the name, the UTC times, the time zone and the booking in the provider\'s area', function () {
    $booking = gclBooking();
    $link = GoogleCalendarLink::for($booking);

    expect($link)->toStartWith('https://calendar.google.com/calendar/render?action=TEMPLATE&');
    expect(gclQuery($link))->toBe([
        'action' => 'TEMPLATE',
        'text' => 'Treccine – '.GCL_NAME,
        'dates' => '20261126T130000Z/20261126T150000Z',
        'ctz' => 'Europe/Rome',
        'details' => 'Prenotazione su Griot: '.route('provider.bookings.show', ['booking' => $booking->id])."\nOpzioni: Perline",
    ]);
});

test('the link never holds the phone, the email, the notes, the reason nor the secret code, encoded or decoded', function () {
    $booking = gclBooking();
    $token = $booking->public_token;
    expect(strlen($token))->toBe(48);

    $link = (string) GoogleCalendarLink::for($booking);
    $decoded = rawurldecode($link);

    foreach ([$link, $decoded, implode("\n", gclQuery($link))] as $text) {
        expect($text)
            ->not->toContain(GCL_PHONE)
            ->not->toContain('000 000 0000')
            ->not->toContain(GCL_EMAIL)
            ->not->toContain(GCL_NOTES)
            ->not->toContain(GCL_REASON)
            ->not->toContain($token)
            ->not->toContain('/bookings/'.$token)
            ->not->toContain('location=');
    }
    // The class never reads the code, nor the other private fields.
    expect((string) file_get_contents(app_path('Booking/GoogleCalendarLink.php')))
        ->not->toContain('->public_token')
        ->not->toContain('customer_phone')
        ->not->toContain('customer_email')
        ->not->toContain('->notes')
        ->not->toContain('->cancellation_reason')
        ->not->toContain('totalCents')
        ->not->toContain('price_cents')
        ->not->toContain('->price');
});

test('the times are UTC instants: the clocks changing in March and October need nothing', function (string $local, string $dates) {
    $start = CarbonImmutable::parse($local, 'Europe/Rome');
    $booking = gclBooking(['starts_at' => $start->utc(), 'ends_at' => $start->addMinutes(90)->utc()]);

    expect(gclQuery((string) GoogleCalendarLink::for($booking))['dates'])->toBe($dates);
})->with([
    // Summer time (UTC+2) from 29 March 2026: 09:00 in Italy is 07:00 UTC.
    'end of March, summer time' => ['2026-03-29 09:00', '20260329T070000Z/20260329T083000Z'],
    // Winter time (UTC+1) from 25 October 2026: 09:00 in Italy is 08:00 UTC.
    'end of October, winter time' => ['2026-10-25 09:00', '20261025T080000Z/20261025T093000Z'],
]);

test('accents, "&", "#" and apostrophes are encoded and arrive whole', function () {
    $booking = gclBooking(service: "Trecce & perline #1 à l'été");
    $link = (string) GoogleCalendarLink::for($booking);

    expect(gclQuery($link)['text'])->toBe("Trecce & perline #1 à l'été – ".GCL_NAME)
        ->and(gclQuery($link))->toHaveCount(5)
        // Only the four separators of the five parameters: the "&" of the name is encoded.
        ->and(substr_count($link, '&'))->toBe(4)
        ->and($link)->not->toContain('#')
        ->and($link)->not->toContain(' ');
});

test('no name: the title is the service alone', function () {
    $booking = gclBooking(['customer_name' => null]);

    expect(gclQuery((string) GoogleCalendarLink::for($booking))['text'])->toBe('Treccine');
});

test('the details are in the language of the provider\'s page', function () {
    app()->setLocale('fr');
    $booking = gclBooking();

    expect(gclQuery((string) GoogleCalendarLink::for($booking))['details'])
        ->toStartWith("Réservation sur Griot\u{00A0}: ")
        ->toEndWith("\nOptions\u{00A0}: Perline");
});

test('no link unless the booking is confirmed, not anonymous and still to come', function (Closure $change, bool $shown) {
    $booking = gclBooking();
    $change($booking);
    $booking->refresh()->load(['service', 'options']);

    expect(GoogleCalendarLink::for($booking) !== null)->toBe($shown);
})->with([
    'confirmed, still to come' => [fn (Booking $b) => null, true],
    'written by the provider, born confirmed' => [fn (Booking $b) => $b->forceFill(['created_by_provider' => true])->save(), true],
    'pending' => [fn (Booking $b) => $b->forceFill(['status' => BookingStatus::Pending])->save(), false],
    'rejected' => [fn (Booking $b) => $b->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_by' => CancelledBy::ProviderReject])->save(), false],
    'cancelled by the provider' => [fn (Booking $b) => $b->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_by' => CancelledBy::ProviderCancel])->save(), false],
    'cancelled by the customer' => [fn (Booking $b) => $b->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_by' => CancelledBy::Customer])->save(), false],
    'anonymous' => [fn (Booking $b) => $b->forceFill(['anonymized_at' => CarbonImmutable::now()])->save(), false],
    'already started' => [fn (Booking $b) => $b->forceFill(['starts_at' => CarbonImmutable::now()->subMinute(), 'ends_at' => CarbonImmutable::now()->addHour()])->save(), false],
    'starting right now' => [fn (Booking $b) => $b->forceFill(['starts_at' => CarbonImmutable::now(), 'ends_at' => CarbonImmutable::now()->addHour()])->save(), false],
    'past' => [fn (Booking $b) => $b->forceFill(['starts_at' => CarbonImmutable::now()->subDay(), 'ends_at' => CarbonImmutable::now()->subDay()->addHour()])->save(), false],
]);

test('the provider\'s page of a booking gets the link; the list too; a pending request does not', function () {
    $booking = gclBooking();
    $pending = Booking::factory()->for($booking->provider)->for($booking->service)->create([
        'starts_at' => CarbonImmutable::parse('2026-11-27 13:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-11-27 15:00', 'UTC'),
    ]);
    $user = $booking->provider->user;

    $this->actingAs($user)->get(route('provider.bookings.show', $booking->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('booking.google_calendar_url', GoogleCalendarLink::for($booking)));
    $this->actingAs($user)->get(route('provider.bookings.show', $pending->id))
        ->assertInertia(fn (Assert $page) => $page->where('booking.google_calendar_url', null));
});

test('the button: a link in a new tab without the Referer, the hint for screen readers, the note, no aria-label', function () {
    $card = (string) file_get_contents(resource_path('js/components/provider-booking-card.tsx'));
    preg_match('/\{booking\.google_calendar_url !== null && \([\s\S]*?\n {16}\)\}/', $card, $block);

    expect($block[0] ?? '')
        ->toMatch('/<Button\s+variant="outline"\s+size="sm"\s+asChild\s+className=\{wrappingLabel\}\s*>/')
        ->toMatch('/<a\s+href=\{booking\.google_calendar_url\}\s+target="_blank"\s+rel="noopener noreferrer"\s*>/')
        ->toContain('<CalendarPlus aria-hidden="true" />')
        ->toMatch("/\\{t\\(\\s*'provider\\.bookings\\.google_calendar_button',?\\s*\\)\\}/")
        ->toContain('<NewTabHint />')
        ->toMatch("/\\{t\\(\\s*'provider\\.bookings\\.google_calendar_note',?\\s*\\)\\}/")
        ->not->toMatch('/aria-label(?!ledby)/');
    expect($card)->toContain("import { CalendarPlus } from 'lucide-react';");
});

test('the texts exist in the three languages, with the French spaces, and the privacy page names Google', function () {
    $texts = [
        'it' => ['Aggiungi a Google Calendar', "Se la prenotazione viene annullata, l'evento resta nel tuo calendario."],
        'fr' => ['Ajouter à Google Agenda', "Si la réservation est annulée, l'événement reste dans votre agenda."],
        'en' => ['Add to Google Calendar', 'If the booking is cancelled, the event stays in your calendar.'],
    ];

    foreach ($texts as $locale => [$button, $note]) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

        expect($json['provider.bookings.google_calendar_button'])->toBe($button)
            ->and($json['provider.bookings.google_calendar_note'])->toBe($note)
            ->and($json['provider.bookings.calendar_details'])->toContain(':url')
            ->and($json['provider.bookings.calendar_options'])->toContain(':options')
            ->and($json['privacy.recipients.google_calendar'])->toContain('Google');

        foreach (['provider.bookings.calendar_details', 'provider.bookings.calendar_options', 'privacy.recipients.google_calendar', 'provider.bookings.google_calendar_note'] as $key) {
            if ($locale === 'fr') {
                expect(preg_match('/ [:?!;](?![a-z_])/', $json[$key]))->toBe(0, "No non-breaking space in fr {$key}");
            }
        }
    }

    expect((string) file_get_contents(resource_path('js/pages/privacy.tsx')))->toContain("{t('privacy.recipients.google_calendar')}");
});
