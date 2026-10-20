<?php

use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

// "Now" is Sunday 18 October 2026, 08:00 UTC. The form books Monday 19 October at 09:00 local
// (07:00 UTC). The limits are the ones of config/booking.php: 5 per minute and 20 per hour for
// the form, 10 per minute for the cancellation, 60 per minute for the slots. In the tests the
// cache is the array store: a new one for every test, so every test starts from zero.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    app()->setLocale('it');
});

const ASP_TOO_MANY = 'Hai fatto troppe richieste in poco tempo. Riprova tra qualche minuto.';

function aspProvider(): Provider
{
    $provider = Provider::factory()->published()->create();
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);

    return $provider;
}

/**
 * A valid form for a new 60 minute service of the provider, with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function aspForm(Provider $provider, array $override = []): array
{
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);

    return [
        'service' => $service->id,
        'starts_at' => '2026-10-19T07:00:00Z',
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+39 333 123 4567',
        ...$override,
    ];
}

/**
 * The form sent as JSON, without the X-Inertia header: invalid data gives a 422.
 *
 * @param  array<string, mixed>  $data
 */
function aspPostJson(Provider|string $provider, array $data = []): TestResponse
{
    $slug = $provider instanceof Provider ? $provider->slug : $provider;

    return test()->postJson(route('providers.bookings.store', $slug), $data);
}

/**
 * Send $count JSON requests with no fields and check that each gets the given status.
 */
function aspSendJson(Provider $provider, int $count, int $status): void
{
    for ($i = 1; $i <= $count; $i++) {
        aspPostJson($provider)->assertStatus($status);
    }
}

function aspSlotsUrl(Provider $provider): string
{
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);

    return route('providers.slots', ['publishedProvider' => $provider->slug, 'service' => $service->id, 'date' => '2026-10-19']);
}

function aspBooking(): Booking
{
    return Booking::factory()->for(aspProvider())->create([
        'starts_at' => CarbonImmutable::parse('2026-10-29 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-29 09:00', 'UTC'),
    ]);
}

test('the first 5 form requests in a minute pass the limit, the 6th gets a 429 with Retry-After', function () {
    $provider = aspProvider();

    aspSendJson($provider, 5, 422);

    aspPostJson($provider)->assertStatus(429)->assertHeader('Retry-After', '60');
});

test('every test starts with empty counters', function () {
    // The previous test used up the limit of the same IP: here the counter is back to zero.
    expect(RateLimiter::attempts(md5('booking-store'.'minute|127.0.0.1')))->toBe(0);

    aspSendJson(aspProvider(), 5, 422);
    expect(RateLimiter::attempts(md5('booking-store'.'minute|127.0.0.1')))->toBe(5);
});

test('after 61 seconds the minute window starts again', function () {
    $provider = aspProvider();
    aspSendJson($provider, 5, 422);
    aspPostJson($provider)->assertStatus(429);

    $this->travel(61)->seconds();

    aspPostJson($provider)->assertStatus(422);
});

test('the hour limit blocks the 21st request even with a free minute window, until the hour passes', function () {
    $provider = aspProvider();

    for ($group = 1; $group <= 4; $group++) {
        aspSendJson($provider, 5, 422);
        $this->travel(61)->seconds();
    }

    // 20 requests in about four minutes: the minute window is free, the hour one is not.
    aspPostJson($provider)->assertStatus(429)->assertHeader('Retry-After');

    $this->travel(1)->hour();

    aspPostJson($provider)->assertStatus(422);
});

test('another IP is not limited', function () {
    $provider = aspProvider();
    aspSendJson($provider, 5, 422);
    aspPostJson($provider)->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2']);

    aspPostJson($provider)->assertStatus(422);
});

test('the Inertia form over the limit goes back with an error on throttle, not a 429', function () {
    $provider = aspProvider();
    $page = route('providers.show', $provider->slug);

    for ($i = 1; $i <= 5; $i++) {
        $this->from($page)->post(route('providers.bookings.store', $provider->slug), [], ['X-Inertia' => 'true'])
            ->assertRedirect($page);
    }

    // Valid data this time: the limit comes first, so nothing is booked.
    $this->from($page)->post(route('providers.bookings.store', $provider->slug), aspForm($provider), ['X-Inertia' => 'true'])
        ->assertRedirect($page)
        ->assertSessionHasErrors(['throttle' => ASP_TOO_MANY]);

    expect(Booking::query()->count())->toBe(0);
});

test('the 11th cancellation in a minute does not cancel and goes back to the private page', function () {
    $booking = aspBooking();

    // Ten requests with unknown codes: each gets a 404, and each one counts.
    for ($i = 1; $i <= 10; $i++) {
        $this->post('/bookings/'.Str::random(48).'/cancel')->assertNotFound();
    }

    $response = $this->post(route('bookings.cancel', ['booking' => $booking->public_token]))
        ->assertRedirect(route('bookings.show', ['booking' => $booking->public_token]))
        ->assertSessionHasErrors(['cancel' => ASP_TOO_MANY]);

    expect($response->headers->get('Location'))->toEndWith('/bookings/'.$booking->public_token);
    expect(ASP_TOO_MANY)->not->toContain($booking->public_token);
    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

test('the slots allow 60 requests a minute, then a JSON 429 with Retry-After', function () {
    $url = aspSlotsUrl(aspProvider());

    for ($i = 1; $i <= 60; $i++) {
        $this->getJson($url)->assertOk();
    }

    $this->getJson($url)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJson(['message' => 'Too Many Attempts.']);
});

test('the limits have separate counters', function () {
    $provider = aspProvider();
    $booking = aspBooking();
    aspSendJson($provider, 5, 422);
    aspPostJson($provider)->assertStatus(429);

    // booking-store is used up: booking-slots and booking-cancel still answer for the same IP.
    $this->getJson(aspSlotsUrl($provider))->assertOk();
    $this->post(route('bookings.cancel', ['booking' => $booking->public_token]))->assertSessionHasNoErrors();
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

test('the limit comes from the configuration', function () {
    config()->set('booking.rate_limit_store_per_minute', 2);
    $provider = aspProvider();

    aspSendJson($provider, 2, 422);

    aspPostJson($provider)->assertStatus(429);
});

test('over the limit, an unknown slug gets a 429 and not a 404', function () {
    $provider = aspProvider();
    aspSendJson($provider, 5, 422);

    // The limit runs before the route binding (middleware priority) and is per IP, not per
    // provider: the response does not reveal whether the slug exists.
    aspPostJson('non-esiste')->assertStatus(429);
});

test('a filled honeypot with valid data fakes a success and books nothing', function () {
    Notification::fake();
    Log::spy();
    $provider = aspProvider();

    $response = $this->post(route('providers.bookings.store', $provider->slug), aspForm($provider, [
        'confirm_reference' => 'bot',
        'customer_name' => 'Nome Del Bot',
        'customer_phone' => '+39 300 111 2233',
    ]));

    $response
        ->assertRedirect(route('providers.show', $provider->slug))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Richiesta inviata. Il professionista deve ancora confermarla.',
        ]);
    expect(Booking::query()->count())->toBe(0);
    Notification::assertNothingSent();
    // Fixed message and the provider id only: no name, no phone.
    Log::shouldHaveReceived('info')->once()->with('Booking request discarded: honeypot field filled.', ['provider_id' => $provider->id]);
});

test('a filled honeypot with invalid data gets the normal validation errors', function () {
    Log::spy();
    $provider = aspProvider();

    $this->post(route('providers.bookings.store', $provider->slug), aspForm($provider, [
        'confirm_reference' => 'bot',
        'customer_name' => '',
    ]))
        ->assertSessionHasErrors(['customer_name' => 'Scrivi il tuo nome.'])
        ->assertInertiaFlashMissing('toast');

    expect(Booking::query()->count())->toBe(0);
    Log::shouldNotHaveReceived('info');
});

test('an empty, missing or blank honeypot does not stop a real booking', function (array $honeypot) {
    $provider = aspProvider();

    $response = $this->post(route('providers.bookings.store', $provider->slug), aspForm($provider, $honeypot));

    $booking = Booking::query()->sole();
    $response->assertRedirect(route('bookings.show', ['booking' => $booking->public_token]));
})->with([
    'empty, as the browser sends it' => [['confirm_reference' => '']],
    'missing' => [[]],
    'only spaces' => [['confirm_reference' => '   ']],
]);

test('a honeypot sent as an array counts as filled', function () {
    $provider = aspProvider();

    $this->post(route('providers.bookings.store', $provider->slug), aspForm($provider, ['confirm_reference' => ['x']]))
        ->assertRedirect(route('providers.show', $provider->slug));

    expect(Booking::query()->count())->toBe(0);
});

test('the fake success has no private address', function () {
    $provider = aspProvider();

    $response = $this->post(route('providers.bookings.store', $provider->slug), aspForm($provider, ['confirm_reference' => 'bot']));

    // No booking exists, so there is no code: neither the address nor the session point to one.
    expect($response->headers->get('Location'))->not->toContain('/bookings/');
    expect(json_encode(session()->all()))->not->toContain('/bookings/');
});

test('the Italian text of the limit is exactly the expected one', function () {
    expect(__('booking.throttle.too_many'))->toBe(ASP_TOO_MANY);
});
