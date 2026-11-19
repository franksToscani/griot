<?php

use App\Booking\CalendarEvent;
use App\Booking\CancelledBy;
use App\Booking\IcsFile;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

// The .ics file of a booking for its provider (docs/bookings.md, App\Booking\IcsFile): the same
// event as "Add to Google Calendar" (App\Booking\CalendarEvent), downloaded on the provider's
// device. RFC 5545: CRLF, escaped text, lines folded at 75 bytes without splitting a character,
// a stable UID. Never the phone, the email, the notes, the reason nor the private link. Fake values.

const ICS_NAME = 'Cliente Prova';
const ICS_PHONE = '+39 000 000 0000';
const ICS_EMAIL = 'cliente@example.test';
const ICS_NOTES = 'Note riservate di prova';
const ICS_REASON = 'Motivo riservato di prova';

beforeEach(function () {
    // Now: 1 March 2026, 10:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00', 'UTC'));
});

/**
 * A confirmed booking on 26 November 2026, 14:00-16:00 in Italy (13:00-15:00 UTC), with one option.
 *
 * @param  array<string, mixed>  $override
 */
function icsBooking(array $override = [], string $service = 'Treccine'): Booking
{
    $provider = Provider::factory()->published()->create();
    $serviceModel = Service::factory()->for($provider)->create(['name' => $service]);
    $option = ServiceOption::factory()->for($serviceModel)->create(['name' => 'Perline']);

    $booking = Booking::factory()->for($provider)->for($serviceModel)->confirmed()->create([
        'starts_at' => CarbonImmutable::parse('2026-11-26 13:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-11-26 15:00', 'UTC'),
        'customer_name' => ICS_NAME,
        'customer_phone' => ICS_PHONE,
        'customer_email' => ICS_EMAIL,
        'notes' => ICS_NOTES,
        ...$override,
    ]);
    // A reason saved on the row, even on a confirmed booking: the file must never read it.
    $booking->forceFill(['cancellation_reason' => ICS_REASON])->save();
    attachBookingOptions($booking, $option);

    return $booking;
}

/**
 * The file of a booking, downloaded by its provider.
 */
function icsGet(Booking $booking): TestResponse
{
    return test()->actingAs($booking->provider->user)->get(route('provider.bookings.calendar', $booking->id));
}

/**
 * The logical lines of a file: the folded lines joined again (RFC 5545, 3.1).
 *
 * @return list<string>
 */
function icsLines(string $file): array
{
    return explode("\r\n", rtrim(str_replace("\r\n ", '', $file), "\r\n"));
}

/**
 * A TEXT value read back.
 */
function icsUnescape(string $value): string
{
    return strtr($value, ['\\\\' => '\\', '\\,' => ',', '\\;' => ';', '\\n' => "\n", '\\N' => "\n"]);
}

test('the file holds the event: the same title and details as Google, UTC times, a UID from the id and the host', function () {
    $booking = icsBooking();
    $file = icsGet($booking)->assertOk()->getContent();
    $lines = icsLines((string) $file);
    $host = parse_url(config('app.url'), PHP_URL_HOST);

    expect($lines[7])->toMatch('/^DTSTAMP:20260301T100000Z$/');
    $lines[7] = 'DTSTAMP';
    expect($lines)->toBe([
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Griot//Prenotazioni//IT',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        "UID:booking-{$booking->id}@{$host}",
        'DTSTAMP',
        'DTSTART:20261126T130000Z',
        'DTEND:20261126T150000Z',
        'SUMMARY:Treccine – '.ICS_NAME,
        'DESCRIPTION:Prenotazione su Griot: '.route('provider.bookings.show', ['booking' => $booking->id]).'\\nOpzioni: Perline',
        'END:VEVENT',
        'END:VCALENDAR',
    ]);
});

test('the UID is the same at every download: adding the event again does not double it', function () {
    $booking = icsBooking();
    $first = icsLines((string) icsGet($booking)->getContent());
    $this->travel(5)->minutes();
    $second = icsLines((string) icsGet($booking)->getContent());

    expect($first[6])->toStartWith('UID:booking-')->toBe($second[6])
        // DTSTAMP is the moment of the file: it changes, the UID does not.
        ->and($first[7])->not->toBe($second[7]);
});

test('the times are UTC instants: the clocks changing in March and October need nothing', function (string $local, string $start, string $end) {
    $begin = CarbonImmutable::parse($local, 'Europe/Rome');
    $booking = icsBooking(['starts_at' => $begin->utc(), 'ends_at' => $begin->addMinutes(90)->utc()]);
    $lines = icsLines((string) icsGet($booking)->getContent());

    expect($lines[8])->toBe("DTSTART:{$start}")->and($lines[9])->toBe("DTEND:{$end}");
})->with([
    'end of March, summer time' => ['2026-03-29 09:00', '20260329T070000Z', '20260329T083000Z'],
    'end of October, winter time' => ['2026-10-25 09:00', '20261025T080000Z', '20261025T093000Z'],
]);

test('the file never holds the phone, the email, the notes, the reason nor the secret code', function () {
    $booking = icsBooking();
    $token = $booking->public_token;
    expect(strlen($token))->toBe(48);

    $file = (string) icsGet($booking)->getContent();
    $read = icsUnescape(implode("\n", icsLines($file)));

    foreach ([$file, $read] as $text) {
        expect($text)
            ->not->toContain(ICS_PHONE)
            ->not->toContain('000 000 0000')
            ->not->toContain(ICS_EMAIL)
            ->not->toContain(ICS_NOTES)
            ->not->toContain(ICS_REASON)
            ->not->toContain($token)
            ->not->toContain('LOCATION');
    }
    // The classes never read the code nor the other private fields.
    foreach (['Booking/CalendarEvent.php', 'Booking/IcsFile.php', 'Http/Controllers/ProviderBookingCalendarController.php'] as $path) {
        expect((string) file_get_contents(app_path($path)))
            ->not->toContain('->public_token')
            ->not->toContain('customer_phone')
            ->not->toContain('customer_email')
            ->not->toContain('->notes')
            ->not->toContain('->cancellation_reason')
            ->not->toContain('totalCents');
    }
});

test('every line ends with CRLF, no line is longer than 75 bytes, and no character is split', function () {
    // A long service with accents and emoji: the title is folded several times.
    $service = 'Trecce afro à l\'été, perline; nodi \\ doppi 💇🏾‍♀️ è ù ò ì — trecce lunghissime con perline dorate 🌟';
    $booking = icsBooking(service: $service);
    $file = (string) icsGet($booking)->getContent();

    expect($file)->toEndWith("\r\n")
        // No line break but CRLF.
        ->and(preg_match('/(?<!\r)\n|\r(?!\n)/', $file))->toBe(0);

    $physical = explode("\r\n", substr($file, 0, -2));
    expect(count($physical))->toBeGreaterThan(14, 'The title should be folded');
    foreach ($physical as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75, "Over 75 bytes: {$line}")
            ->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue("A character split: {$line}");
    }

    // Joined again and read back, the title is whole: comma, semicolon and backslash escaped.
    $summary = collect(icsLines($file))->first(fn (string $line) => str_starts_with($line, 'SUMMARY:'));
    expect($summary)->toContain('\\,')->toContain('\\;')->toContain('\\\\')
        ->and(icsUnescape(substr($summary, 8)))->toBe($service.' – '.ICS_NAME);
});

test('the escape of a text, and a fold that falls in the middle of an accent', function () {
    expect(IcsFile::text("a\\b,c;d\ne\r\nf"))->toBe('a\\\\b\\,c\\;d\\ne\\nf');

    // 74 bytes, then "é" (2 bytes): the 75th byte is half of it, so the first line stops at 74.
    $line = 'SUMMARY:'.str_repeat('a', 66).'é'.str_repeat('b', 80);
    $folded = IcsFile::fold($line);
    $parts = explode("\r\n", $folded);

    expect(strlen($parts[0]))->toBe(74)
        ->and($parts[1])->toStartWith(' é');
    foreach ($parts as $part) {
        expect(strlen($part))->toBeLessThanOrEqual(75)->and(mb_check_encoding($part, 'UTF-8'))->toBeTrue();
    }
    expect(str_replace("\r\n ", '', $folded))->toBe($line);
});

test('the headers: a calendar file to download, named by the id only, never cached nor indexed', function () {
    $booking = icsBooking();
    $response = icsGet($booking)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/calendar; charset=utf-8')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="prenotazione-'.$booking->id.'.ics"')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($response->headers->get('Content-Disposition'))->not->toContain('Cliente');
});

test('another provider gets the same 404 as for the booking\'s page; a guest goes to the login', function () {
    $booking = icsBooking();
    $other = Provider::factory()->published()->create();

    $this->actingAs($other->user)->get(route('provider.bookings.calendar', $booking->id))->assertNotFound();
    $this->actingAs($other->user)->get(route('provider.bookings.show', $booking->id))->assertNotFound();
    $this->actingAs($other->user)->get(route('provider.bookings.calendar', 999999))->assertNotFound();

    auth()->logout();
    $this->get(route('provider.bookings.calendar', $booking->id))->assertRedirect(route('login'));
});

test('a file only when the booking is confirmed, not anonymous and still to come, else 404', function (Closure $change, int $status) {
    $booking = icsBooking();
    $change($booking);

    icsGet($booking->refresh())->assertStatus($status);
})->with([
    'confirmed, still to come' => [fn (Booking $b) => null, 200],
    'written by the provider, born confirmed' => [fn (Booking $b) => $b->forceFill(['created_by_provider' => true])->save(), 200],
    'pending' => [fn (Booking $b) => $b->forceFill(['status' => BookingStatus::Pending])->save(), 404],
    'rejected' => [fn (Booking $b) => $b->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_by' => CancelledBy::ProviderReject])->save(), 404],
    'cancelled by the provider' => [fn (Booking $b) => $b->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_by' => CancelledBy::ProviderCancel])->save(), 404],
    'cancelled by the customer' => [fn (Booking $b) => $b->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_by' => CancelledBy::Customer])->save(), 404],
    'anonymous' => [fn (Booking $b) => $b->forceFill(['anonymized_at' => CarbonImmutable::now()])->save(), 404],
    'already started' => [fn (Booking $b) => $b->forceFill(['starts_at' => CarbonImmutable::now()->subMinute(), 'ends_at' => CarbonImmutable::now()->addHour()])->save(), 404],
    'starting right now' => [fn (Booking $b) => $b->forceFill(['starts_at' => CarbonImmutable::now(), 'ends_at' => CarbonImmutable::now()->addHour()])->save(), 404],
    'past' => [fn (Booking $b) => $b->forceFill(['starts_at' => CarbonImmutable::now()->subDay(), 'ends_at' => CarbonImmutable::now()->subDay()->addHour()])->save(), 404],
]);

test('the file and the Google link come from the same event', function () {
    $booking = icsBooking()->load(['service', 'options']);
    $event = CalendarEvent::for($booking);

    expect($event)->not->toBeNull()
        ->and(IcsFile::uid($event))->toStartWith("booking-{$booking->id}@");
    expect((string) file_get_contents(app_path('Booking/GoogleCalendarLink.php')))->toContain('CalendarEvent::for($booking)');
    expect((string) file_get_contents(app_path('Http/Controllers/ProviderBookingCalendarController.php')))
        ->toContain('CalendarEvent::for($booking)')
        ->toContain("Gate::authorize('manage', \$booking);")
        ->toContain('abort_if($event === null, 404);');
    // PRODID names Griot only.
    expect(IcsFile::PRODID)->toBe('-//Griot//Prenotazioni//IT');
});

test('the button: a plain link with href and download, Wayfinder\'s route, no aria-label; the texts in three languages', function () {
    $card = (string) file_get_contents(resource_path('js/components/provider-booking-card.tsx'));

    expect($card)
        ->toMatch('/<a\s+href=\{calendarFile\(booking\.id\)\.url\}\s+download\s*>\s*<Download aria-hidden="true" \/>\s*\{t\(\s*\'provider\.bookings\.calendar_file_button\',?\s*\)\}\s*<\/a>/')
        ->toMatch('/import \\{\\s*calendar as calendarFile,/')
        ->toContain("import { CalendarPlus, Download } from 'lucide-react';")
        ->not->toMatch('/aria-label(?!ledby)/');

    $texts = ['it' => 'Aggiungi al calendario (Apple, Outlook, altri)', 'fr' => "Ajouter à l'agenda (Apple, Outlook, autres)", 'en' => 'Add to calendar (Apple, Outlook, other)'];
    foreach ($texts as $locale => $text) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        expect($json['provider.bookings.calendar_file_button'])->toBe($text);
        if ($locale === 'fr') {
            expect(preg_match('/ [:?!;](?![a-z_])/', $json['provider.bookings.calendar_file_button']))->toBe(0);
        }
    }
});
