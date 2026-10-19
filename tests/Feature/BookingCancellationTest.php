<?php

use App\Booking\CancellationNotAllowed;
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
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// The reference booking starts on Thursday 2026-10-29 at 08:00 UTC (09:00 in Italy); with 1440
// minutes of notice the limit is 2026-10-28 08:00:00 UTC. "Now" is 2026-10-27 08:00 UTC.
beforeEach(function () {
    config()->set('booking.cancellation_notice_minutes', 1440);
    $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
    app()->setLocale('it');
});

/**
 * A booking of a published provider, 60 minutes from 2026-10-29 08:00 UTC, with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 */
function bclBooking(array $override = []): Booking
{
    $provider = Provider::factory()->published()->create();
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);

    return Booking::factory()->for($provider)->for($service)->create([
        'starts_at' => CarbonImmutable::parse('2026-10-29 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-29 09:00', 'UTC'),
        ...$override,
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function bclCancel(Booking $booking, array $data = []): TestResponse
{
    return test()->post(route('bookings.cancel', ['booking' => $booking->public_token]), $data);
}

function bclPage(Booking $booking): string
{
    return route('bookings.show', ['booking' => $booking->public_token]);
}

test('a pending or confirmed booking is cancelled and the page shows it', function (BookingStatus $status) {
    $booking = bclBooking(['status' => $status]);

    $response = bclCancel($booking)
        ->assertRedirect(bclPage($booking))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Prenotazione annullata.']);

    expect($response->headers->get('Location'))
        ->toEndWith('/bookings/'.$booking->public_token)
        ->not->toEndWith('/bookings/'.$booking->id);
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);

    $this->get($response->headers->get('Location'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking.status', 'cancelled')
            ->where('can_cancel', false));
})->with([
    'pending' => [BookingStatus::Pending],
    'confirmed' => [BookingStatus::Confirmed],
]);

test('the limit is exact to the second, in winter, in summer and across the change of time', function (string $start, string $lastMoment, string $tooLate) {
    $startsAt = CarbonImmutable::parse($start, 'UTC');

    $this->travelTo(CarbonImmutable::parse($lastMoment, 'UTC'));
    $onTime = bclBooking(['starts_at' => $startsAt, 'ends_at' => $startsAt->addHour()]);
    bclCancel($onTime)->assertSessionHasNoErrors();
    expect($onTime->fresh()->status)->toBe(BookingStatus::Cancelled);

    $this->travelTo(CarbonImmutable::parse($tooLate, 'UTC'));
    $late = bclBooking(['starts_at' => $startsAt, 'ends_at' => $startsAt->addHour()]);
    bclCancel($late)
        // The error comes back to the private page too, not to a "previous" address.
        ->assertRedirect(bclPage($late))
        ->assertSessionHasErrors(['cancel' => 'Non è più possibile annullare online: contatta direttamente il professionista.']);
    expect($late->fresh()->status)->toBe(BookingStatus::Pending);
})->with([
    // Winter time: the limit is exactly 1440 minutes before the start.
    'winter' => ['2026-10-29 08:00', '2026-10-28 08:00:00', '2026-10-28 08:00:01'],
    // Summer time, same rule.
    'summer' => ['2026-07-06 07:00', '2026-07-05 07:00:00', '2026-07-05 07:00:01'],
    // Time changes on 25 October at 01:00 UTC: the limit is still 1440 minutes before, 08:00 UTC
    // on the 24th, not "the same local time the day before" (that would be 07:00 UTC).
    'change of time' => ['2026-10-25 08:00', '2026-10-24 07:30:00', '2026-10-24 08:00:01'],
]);

test('after the start the booking cannot be cancelled', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 09:00', 'UTC'));
    $booking = bclBooking();

    bclCancel($booking)->assertSessionHasErrors('cancel');
    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

test('an already cancelled booking goes back to the page without a message or a write', function () {
    $booking = bclBooking(['status' => BookingStatus::Cancelled]);
    $updatedAt = $booking->updated_at->toIso8601String();
    $this->travel(1)->hour();

    bclCancel($booking)
        ->assertRedirect(bclPage($booking))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlashMissing('toast');

    expect($booking->fresh()->updated_at->toIso8601String())->toBe($updatedAt);
});

test('a GET cannot cancel', function () {
    $booking = bclBooking();

    $this->get(route('bookings.cancel', ['booking' => $booking->public_token]))->assertStatus(405);
    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

test('a wrong code gives a 404 and changes nothing', function (Closure $code) {
    $booking = bclBooking();

    $this->post('/bookings/'.$code($booking).'/cancel')->assertNotFound();
    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
})->with([
    'unknown, 48 valid characters' => [fn () => Str::random(48)],
    '47 characters' => [fn (Booking $booking) => substr($booking->public_token, 0, 47)],
    '49 characters' => [fn (Booking $booking) => $booking->public_token.'a'],
    'a character not allowed' => [fn (Booking $booking) => substr($booking->public_token, 0, 47).'-'],
    'the id 1' => [fn () => '1'],
    'the real id' => [fn (Booking $booking) => (string) $booking->id],
]);

test('the code of another booking cancels only that one', function () {
    $booking = bclBooking();
    $other = bclBooking();

    bclCancel($other)->assertSessionHasNoErrors();

    expect($other->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

test('the body of the request is ignored', function () {
    $booking = bclBooking();
    $otherProvider = Provider::factory()->published()->create();

    bclCancel($booking, [
        'status' => 'confirmed',
        'provider_id' => $otherProvider->id,
        'starts_at' => '2026-11-02T08:00:00Z',
        'ends_at' => '2026-11-02T12:00:00Z',
    ])->assertSessionHasNoErrors();

    $fresh = $booking->fresh();
    expect($fresh->status)->toBe(BookingStatus::Cancelled);
    expect($fresh->provider_id)->toBe($booking->provider_id);
    expect($fresh->starts_at->toIso8601String())->toBe('2026-10-29T08:00:00+00:00');
    expect($fresh->ends_at->toIso8601String())->toBe('2026-10-29T09:00:00+00:00');
});

test('the customer can cancel even if the provider is unpublished and the service deactivated', function () {
    $booking = bclBooking();
    $booking->provider->update(['is_published' => false]);
    $booking->service->update(['is_active' => false]);

    bclCancel($booking)->assertRedirect(bclPage($booking));
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

test('the redirect has the headers of the private page', function () {
    $response = bclCancel(bclBooking());

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('the token stays in the address and in private_url only', function () {
    $booking = bclBooking();
    $token = $booking->public_token;

    $response = bclCancel($booking);
    // The flash message, and the rest of the session, after the POST.
    expect(json_encode(session()->all()))->not->toContain($token);

    $page = $this->get($response->headers->get('Location'))->assertOk()->viewData('page');
    expect($page['props']['private_url'])->toEndWith('/bookings/'.$token);
    unset($page['url'], $page['props']['private_url']);
    expect(json_encode($page))->not->toContain($token);
    // The session without "_previous", where Laravel keeps the address of a full page GET
    // (see BookingConfirmationTest).
    expect(json_encode(Arr::except(session()->all(), ['_previous'])))->not->toContain($token);

    expect(CancellationNotAllowed::for($booking->fresh())->getMessage())->not->toContain($token);
});

test('private_url plus "/cancel" is the address of the cancel route', function () {
    $booking = bclBooking();

    $privateUrl = $this->get(bclPage($booking))->viewData('page')['props']['private_url'];

    expect($privateUrl.'/cancel')->toBe(route('bookings.cancel', ['booking' => $booking->public_token]));
});

test('can_cancel on the page follows status and limit', function (BookingStatus $status, string $now, bool $expected) {
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
    $booking = bclBooking(['status' => $status]);

    $this->get(bclPage($booking))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can_cancel', $expected));
})->with([
    'pending before the limit' => [BookingStatus::Pending, '2026-10-27 08:00', true],
    'confirmed before the limit' => [BookingStatus::Confirmed, '2026-10-27 08:00', true],
    'cancelled' => [BookingStatus::Cancelled, '2026-10-27 08:00', false],
    'pending after the limit' => [BookingStatus::Pending, '2026-10-28 08:00:01', false],
]);

test('after the cancellation the time is free again and can be booked by another customer', function () {
    $provider = Provider::factory()->published()->create();
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Thursday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);
    $start = CarbonImmutable::parse('2026-10-29 08:00', 'UTC');
    $booking = Booking::factory()->for($provider)->for($service)->create(['starts_at' => $start, 'ends_at' => $start->addHour()]);

    $starts = fn () => app(FreeSlots::class)
        ->slotsFor($provider, $service, [], CarbonImmutable::parse('2026-10-29', 'Europe/Rome'))
        ->map(fn (TimeWindow $slot) => $slot->start->getTimestamp())
        ->all();

    expect($starts())->not->toContain($start->getTimestamp());

    bclCancel($booking)->assertSessionHasNoErrors();

    expect($starts())->toContain($start->getTimestamp());
    $again = app(CreateBooking::class)->create($provider, $service, [], $start, new CustomerDetails('Altro Cliente', '+39 300 000 0001'));
    expect($again->starts_at->getTimestamp())->toBe($start->getTimestamp());
});

test('the Italian texts are exactly the expected ones', function () {
    expect(__('booking.cancel.button'))->toBe('Annulla prenotazione');
    expect(__('booking.cancel.confirm_title'))->toBe('Vuoi annullare la prenotazione?');
    expect(__('booking.cancel.confirm_description'))->toBe("L'orario tornerà libero per altri clienti. L'operazione non si può annullare.");
    expect(__('booking.cancel.confirm_yes'))->toBe('Sì, annulla');
    expect(__('booking.cancel.confirm_no'))->toBe('No, tienila');
    expect(__('booking.cancel.too_late'))->toBe('Non è più possibile annullare online: contatta direttamente il professionista.');
    expect(__('booking.cancel.cancelled'))->toBe('Prenotazione annullata.');
});
