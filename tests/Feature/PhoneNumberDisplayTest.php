<?php

use App\Booking\AnonymizeOldBookings;
use App\Booking\PhoneNumber;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Notifications\CustomerCancelledNotification;
use App\Notifications\NewBookingNotification;
use App\Notifications\PendingReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Inertia\Testing\AssertableInertia as Assert;

// Issue #177: the phone numbers written to be read, by PhoneNumber::display(): Italian mobiles,
// Cameroon and France in their own format, groups separated by U+00A0; everything else as it was.
// Fake numbers only (docs: no real contact in the repository).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', 'UTC'));
    app()->setLocale('it');
});

/**
 * A number with "_" in place of U+00A0, to read the groups in the expectations.
 */
function pndShow(string $number): string
{
    return str_replace("\u{00A0}", '_', PhoneNumber::display($number));
}

/**
 * A booking of a published provider, with the given customer's phone.
 */
function pndBooking(?string $phone = '+393331234567'): Booking
{
    $provider = Provider::factory()->published()->create(['name' => 'Salone Awa']);

    return Booking::factory()->for($provider)->for(Service::factory()->for($provider)->create(['name' => 'Treccine']))->create([
        'starts_at' => CarbonImmutable::parse('2026-11-20 09:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-11-20 10:00', 'UTC'),
        'customer_name' => 'Awa Diop',
        'customer_phone' => $phone,
    ]);
}

/**
 * The HTML and the text version of a notification's email.
 *
 * @return array{string, string}
 */
function pndEmail(MailMessage $mail): array
{
    return [(string) $mail->render(), view($mail->view['text'], $mail->viewData)->render()];
}

// ---------------------------------------------------------------------------------------------
// The rule
// ---------------------------------------------------------------------------------------------

test('the three countries in their format, groups separated by U+00A0', function (string $number, string $shown) {
    expect(pndShow($number))->toBe($shown);
})->with([
    'Italy, mobile' => ['+393331234567', '+39_333_123_4567'],
    'Italy, mobile, with 00 and spaces' => ['0039 333 123 4567', '+39_333_123_4567'],
    'Italy, mobile, as a provider may write it' => ['+39 (333) 123-4567', '+39_333_123_4567'],
    'Cameroon, mobile' => ['+237671234567', '+237_6_71_23_45_67'],
    'Cameroon, fixed' => ['+237222123456', '+237_2_22_12_34_56'],
    'France, mobile' => ['+33612345678', '+33_6_12_34_56_78'],
    'France, fixed, already grouped' => ['+33 1 23 45 67 89', '+33_1_23_45_67_89'],
]);

test('everything else comes back as it was received', function (string $number) {
    expect(PhoneNumber::display($number))->toBe(trim($number));
})->with([
    'an Italian fixed number' => ['+390612345678'],
    'an Italian fixed number with spaces' => ['+39 06 1234 5678'],
    'an Italian mobile too short' => ['+39333123456'],
    'another country' => ['+4915112345678'],
    'another country with spaces' => ['+44 20 7946 0000'],
    'Cameroon with 8 digits (the old plan)' => ['+23771234567'],
    'Cameroon starting with 3' => ['+237312345678'],
    'France starting with 0' => ['+33012345678'],
    'not international' => ['3331234567'],
    'letters' => ['chiamami'],
    'empty' => [''],
    'spaces around' => ['  +4915112345678  '],
]);

test('the format follows the number, never the language of the page', function () {
    foreach (['it', 'fr', 'en'] as $locale) {
        app()->setLocale($locale);
        expect(pndShow('+33612345678'))->toBe('+33_6_12_34_56_78')
            ->and(pndShow('+393331234567'))->toBe('+39_333_123_4567');
    }
});

// ---------------------------------------------------------------------------------------------
// Where it is shown
// ---------------------------------------------------------------------------------------------

test('the booking card: customer_phone stays compact, customer_phone_display is written to be read', function () {
    $booking = pndBooking();

    $this->actingAs($booking->provider->user)
        ->get(route('provider.bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('bookings.data.0.customer_phone', '+393331234567')
            ->where('bookings.data.0.customer_phone_display', "+39\u{00A0}333\u{00A0}123\u{00A0}4567")
            // The link to WhatsApp keeps the compact number.
            ->where('bookings.data.0.whatsapp_url', fn (?string $url) => $url === null || str_starts_with($url, 'https://wa.me/393331234567')));

    $this->actingAs($booking->provider->user)
        ->get(route('provider.bookings.show', $booking->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking.customer_phone_display', "+39\u{00A0}333\u{00A0}123\u{00A0}4567"));
});

test('an anonymous booking has no phone to show', function () {
    $booking = pndBooking();
    $booking->update(['starts_at' => CarbonImmutable::parse('2026-01-10 09:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-01-10 10:00', 'UTC')]);
    app(AnonymizeOldBookings::class)->run(CarbonImmutable::now());

    $this->actingAs($booking->provider->user)
        ->get(route('provider.bookings.show', $booking->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking.customer_phone', null)
            ->where('booking.customer_phone_display', null));
});

test('the card shows customer_phone_display', function () {
    $card = (string) file_get_contents(resource_path('js/components/provider-booking-card.tsx'));

    // As a link when the server sends its address (#207), as text otherwise: the number is always
    // customer_phone_display.
    expect($card)->toMatch("/\\{booking\\.customer_phone_display !== null && \\([\\s\\S]*?\\{t\\('provider\\.bookings\\.phone'\\)\\}:\\{' '\\}[\\s\\S]*?\\{booking\\.customer_phone_display\\}[\\s\\S]*?booking\\.customer_phone_display\\s*\\)\\}/")
        ->not->toMatch('/\{booking\.customer_phone\}/');
});

test('the public page: the provider\'s WhatsApp written to be read when recognised, as written otherwise', function (string $written, string $shown) {
    $provider = Provider::factory()->published()->create(['whatsapp' => $written]);
    Service::factory()->for($provider)->create();

    $this->get(route('providers.show', $provider->slug))
        ->assertInertia(fn (Assert $page) => $page
            ->where('provider.contacts.whatsapp.number', $shown)
            // The link keeps the digits only.
            ->where('provider.contacts.whatsapp.url', fn (?string $url) => $url !== null && preg_match('#^https://wa\.me/[0-9]+$#', $url) === 1));
})->with([
    'Italy' => ['0039 333-123-4567', "+39\u{00A0}333\u{00A0}123\u{00A0}4567"],
    'Cameroon' => ['+237 671234567', "+237\u{00A0}6\u{00A0}71\u{00A0}23\u{00A0}45\u{00A0}67"],
    'another country, as written' => ['+44 20 7946 0000', '+44 20 7946 0000'],
]);

test('the three emails to the provider: the number written to be read, in HTML and in text', function (string $notification) {
    $booking = pndBooking();
    $mail = (new $notification($booking))->toMail($booking->provider->user);
    [$html, $text] = pndEmail($mail);

    foreach ([$html, $text] as $part) {
        expect($part)->toContain("+39\u{00A0}333\u{00A0}123\u{00A0}4567")
            ->not->toContain('+393331234567')
            ->not->toContain('tel:');
    }
})->with([NewBookingNotification::class, PendingReminderNotification::class, CustomerCancelledNotification::class]);
