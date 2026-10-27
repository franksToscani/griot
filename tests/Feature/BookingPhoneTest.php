<?php

use App\Booking\PhoneNumber;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

// "Now" is Sunday 18 October 2026, 08:00 UTC. The form books Monday 19 October at 09:00 local
// (07:00 UTC), inside the 09:00-13:00 window of the provider.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    app()->setLocale('it');
});

const BPH_INVALID = 'Scrivi il numero con il prefisso (per esempio +39 333 123 4567 oppure +237 6 77 12 34 56). Per i numeri italiani e camerunesi va bene anche senza prefisso.';

/**
 * Send a valid booking form with the given phone, to a new provider.
 */
function bphPost(mixed $phone): TestResponse
{
    $provider = Provider::factory()->published()->create();
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);

    return test()->post(route('providers.bookings.store', $provider->slug), [
        'service' => $service->id,
        'starts_at' => '2026-10-19T07:00:00Z',
        'customer_name' => 'Awa Diop',
        'customer_phone' => $phone,
    ]);
}

test('the accepted formats become "+" and the digits', function (string $written, string $normalized) {
    expect(PhoneNumber::normalize($written))->toBe($normalized);
})->with([
    'Italy with +39 and spaces' => ['+39 333 123 4567', '+393331234567'],
    'Italy with 0039' => ['0039 333 1234567', '+393331234567'],
    'Italy without prefix' => ['3331234567', '+393331234567'],
    'Italy without prefix, with spaces' => ['333 123 4567', '+393331234567'],
    'Italy with dashes, dots and brackets' => ['(333) 123-45.67', '+393331234567'],
    'Italy with +39 in brackets' => ['+39 (333) 123-4567', '+393331234567'],
    'Cameroon mobile without prefix' => ['677123456', '+237677123456'],
    'Cameroon mobile with spaces' => ['6 77 12 34 56', '+237677123456'],
    'Cameroon fixed without prefix' => ['222 12 34 56', '+237222123456'],
    'Cameroon with +237' => ['+237 6 77 12 34 56', '+237677123456'],
    'Cameroon with 00237' => ['00237677123456', '+237677123456'],
    'France with +33' => ['+33 6 12 34 56 78', '+33612345678'],
    'Italian landline with +39' => ['+39 06 1234567', '+39061234567'],
    'spaces around' => ['  +39 333 123 4567  ', '+393331234567'],
    '8 digits after the prefix, the least' => ['+12345678', '+12345678'],
    '15 digits after the prefix, the most' => ['+123456789012345', '+123456789012345'],
]);

test('the refused formats give null', function (string $written) {
    expect(PhoneNumber::normalize($written))->toBeNull();
})->with([
    'Italian landline without prefix' => ['06 1234567'],
    'too short' => ['123'],
    'empty' => [''],
    'only spaces' => ['   '],
    'letters' => ['333 abc 4567'],
    'only letters' => ['telefono'],
    'Italy with 9 digits' => ['333123456'],
    'Italy with 11 digits' => ['33312345678'],
    'Cameroon starting with 7' => ['777123456'],
    '16 digits after the prefix' => ['+1234567890123456'],
    '7 digits after the prefix' => ['+1234567'],
    'country code starting with 0' => ['+0393331234567'],
    'only the prefix' => ['+'],
    'two plus signs' => ['++39 333 123 4567'],
    'plus in the middle' => ['39+3331234567'],
    'other symbols' => ['+39/333/1234567'],
    '16 digits without prefix' => ['1234567890123456'],
]);

test('a number already normalized comes back the same', function (string $written) {
    $once = PhoneNumber::normalize($written);

    expect($once)->not->toBeNull();
    expect(PhoneNumber::normalize((string) $once))->toBe($once);
})->with(['+39 333 123 4567', '3331234567', '0039 333 1234567', '677123456', '+237 6 77 12 34 56', '+123456789012345']);

test('the booking form saves the number in international format', function (string $written, string $saved) {
    bphPost($written)->assertSessionHasNoErrors();

    expect(Booking::query()->sole()->customer_phone)->toBe($saved);
})->with([
    ['+39 333 123 4567', '+393331234567'],
    ['3331234567', '+393331234567'],
    ['0039 333-123-4567', '+393331234567'],
    ['677123456', '+237677123456'],
]);

test('the booking form refuses the other formats with the clear message', function (mixed $written) {
    bphPost($written)->assertSessionHasErrors(['customer_phone' => BPH_INVALID]);

    expect(Booking::query()->count())->toBe(0);
})->with([
    'Italian landline without prefix' => ['06 1234567'],
    'too short' => ['123'],
    'letters' => ['333 abc 4567'],
    '16 digits after the prefix' => ['+1234567890123456'],
    'an array' => [['+39 333 123 4567']],
]);

test('an empty number, or only spaces, is the missing number', function (string $written) {
    bphPost($written)->assertSessionHasErrors(['customer_phone' => 'Scrivi un numero di telefono.']);
})->with(['', '   ']);

test('the numbers already saved do not change', function () {
    $booking = Booking::factory()->create(['customer_phone' => '+39 333 123 4567']);

    expect($booking->fresh()?->customer_phone)->toBe('+39 333 123 4567');
});

test('the Italian texts are exactly the expected ones', function () {
    expect(__('booking.phone.invalid'))->toBe(BPH_INVALID);
});
