<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    app()->setLocale('it');
});

/**
 * A booking of "Treccine" by "Salone Awa" in Bologna, Thursday 29 October 2026 from 08:00 to
 * 10:45 UTC (09:00-11:45 in Italy, winter time), with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 */
function bcBooking(array $override = []): Booking
{
    $provider = Provider::factory()->published()->create(['name' => 'Salone Awa', 'city' => 'Bologna']);
    $service = Service::factory()->for($provider)->create(['name' => 'Treccine', 'price' => '90.00']);

    return Booking::factory()->for($provider)->for($service)->create([
        'starts_at' => CarbonImmutable::parse('2026-10-29 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-29 10:45', 'UTC'),
        ...$override,
    ]);
}

function bcUrl(Booking $booking): string
{
    return route('bookings.show', ['booking' => $booking->public_token]);
}

/**
 * The Inertia page of the response without the two places that must hold the code: the page
 * address ("url") and the private_url prop.
 *
 * @return array<string, mixed>
 */
function bcPageWithoutAddress(TestResponse $response): array
{
    $page = $response->viewData('page');
    unset($page['url'], $page['props']['private_url']);

    return $page;
}

test('a valid link shows the booking', function () {
    $booking = bcBooking();
    $first = ServiceOption::factory()->for($booking->service)->create(['name' => 'Extension', 'extra_price' => '20.00']);
    $second = ServiceOption::factory()->for($booking->service)->create(['name' => 'Perline', 'extra_price' => '5.50']);
    $booking->options()->attach([$first->id, $second->id]);

    $this->get(bcUrl($booking))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bookings/show')
            ->where('private_url', bcUrl($booking))
            ->where('can_cancel', true)
            ->where('booking', [
                'status' => 'pending',
                'provider' => ['name' => 'Salone Awa', 'city' => 'Bologna', 'slug' => $booking->provider->slug],
                'service' => ['name' => 'Treccine', 'price' => '90.00'],
                'options' => [
                    ['name' => 'Extension', 'extra_price' => '20.00'],
                    ['name' => 'Perline', 'extra_price' => '5.50'],
                ],
                'local_date' => '2026-10-29',
                'local_start' => '09:00',
                'local_end' => '11:45',
                'cancellation_reason' => null,
            ]));
});

test('date and times are in the provider time zone, in winter and in summer', function (string $start, string $end, string $date) {
    $booking = bcBooking([
        'starts_at' => CarbonImmutable::parse($start, 'UTC'),
        'ends_at' => CarbonImmutable::parse($end, 'UTC'),
    ]);

    $this->get(bcUrl($booking))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking.local_date', $date)
            ->where('booking.local_start', '09:00')
            ->where('booking.local_end', '11:45'));
})->with([
    // Winter time, UTC+1.
    'winter' => ['2026-10-29 08:00', '2026-10-29 10:45', '2026-10-29'],
    // Summer time, UTC+2.
    'summer' => ['2026-07-06 07:00', '2026-07-06 09:45', '2026-07-06'],
]);

test('an unknown code, a code of the wrong shape or an id give the same 404', function (Closure $code) {
    $booking = bcBooking();

    $this->get('/bookings/'.$code($booking))->assertNotFound();
})->with([
    'unknown, 48 valid characters' => [fn () => Str::random(48)],
    '47 characters' => [fn (Booking $booking) => substr($booking->public_token, 0, 47)],
    '49 characters' => [fn (Booking $booking) => $booking->public_token.'a'],
    'a character not allowed' => [fn (Booking $booking) => substr($booking->public_token, 0, 47).'-'],
    'the id 1' => [fn () => '1'],
    'the real id' => [fn (Booking $booking) => (string) $booking->id],
]);

test('a code of the wrong shape does not query the bookings', function () {
    $booking = bcBooking();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->get('/bookings/'.$booking->id)->assertNotFound();
    $this->get('/bookings/'.substr($booking->public_token, 0, 47))->assertNotFound();

    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'bookings')))->toBeEmpty();
});

test('the code is only in private_url and in the page address', function () {
    $booking = bcBooking();
    $token = $booking->public_token;

    $response = $this->get(bcUrl($booking))->assertOk();

    expect($response->viewData('page')['props']['private_url'])->toEndWith('/bookings/'.$token);
    // Props, flash and every other key of the page, without its address and private_url.
    expect(json_encode(bcPageWithoutAddress($response)))->not->toContain($token);
    // The session without "_previous": on every full page GET Laravel stores the whole address
    // there as _previous.url (StartSession::storeCurrentUrl, for back()). It stays on the server
    // and never reaches the browser, which only gets the encrypted session id. Accepted on purpose.
    expect(json_encode(Arr::except(session()->all(), ['_previous'])))->not->toContain($token);
});

test('no personal data of the customer is in the response', function () {
    $booking = bcBooking([
        'customer_name' => 'Zuleika Riconoscibile',
        'customer_phone' => '+39 300 999 8877',
        'customer_email' => 'zuleika.privata@example.com',
        'notes' => 'Nota segretissima del cliente',
    ]);

    $response = $this->get(bcUrl($booking))->assertOk();

    foreach (['Zuleika', '300 999 8877', 'zuleika.privata', 'segretissima'] as $value) {
        expect($response->getContent())->not->toContain($value);
        expect(json_encode($response->viewData('page')))->not->toContain($value);
    }
});

test('the response is not cached, not sent as referrer and not indexed', function () {
    $response = $this->get(bcUrl(bcBooking()))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('every status is shown', function (BookingStatus $status, bool $canCancel) {
    $booking = bcBooking(['status' => $status]);

    $this->get(bcUrl($booking))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking.status', $status->value)
            ->where('can_cancel', $canCancel));
})->with([
    'pending' => [BookingStatus::Pending, true],
    'confirmed' => [BookingStatus::Confirmed, true],
    'cancelled' => [BookingStatus::Cancelled, false],
]);

test('the link keeps working if the provider is unpublished and the service deactivated', function () {
    $booking = bcBooking();
    $booking->provider->forceFill(['is_published' => false])->save();
    $booking->service->update(['is_active' => false]);

    $this->get(bcUrl($booking))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('booking.service.name', 'Treccine'));
});

test('the code of another booking shows the other booking', function () {
    $booking = bcBooking();
    $other = bcBooking();
    $other->service->update(['name' => 'Rasatura']);

    $this->get(bcUrl($other))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking.service.name', 'Rasatura')
            ->where('private_url', bcUrl($other)));
    expect($booking->public_token)->not->toBe($other->public_token);
});

test('only the chosen options are shown, ordered by id', function () {
    $booking = bcBooking();
    $first = ServiceOption::factory()->for($booking->service)->create(['name' => 'Prima', 'extra_price' => '1.00']);
    ServiceOption::factory()->for($booking->service)->create(['name' => 'Non scelta']);
    $third = ServiceOption::factory()->for($booking->service)->create(['name' => 'Terza', 'extra_price' => '3.00']);
    // Attached in the opposite order: the page still lists them by id.
    $booking->options()->attach([$third->id, $first->id]);

    $this->get(bcUrl($booking))
        ->assertInertia(fn (Assert $page) => $page->where('booking.options', [
            ['name' => 'Prima', 'extra_price' => '1.00'],
            ['name' => 'Terza', 'extra_price' => '3.00'],
        ]));
});

test('without options the list is empty', function () {
    $this->get(bcUrl(bcBooking()))
        ->assertInertia(fn (Assert $page) => $page->where('booking.options', []));
});

test('the number of queries does not change with 0 or 3 options', function () {
    $withoutOptions = bcBooking();
    $withOptions = bcBooking();
    $withOptions->options()->attach(ServiceOption::factory()->count(3)->for($withOptions->service)->create());

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->get(bcUrl($withoutOptions))->assertOk();
    $countWithout = $queries;

    $queries = 0;
    $this->get(bcUrl($withOptions))->assertOk();

    // Booking, provider, service and options: one query each, however many options there are.
    expect($countWithout)->toBe(4);
    expect($queries)->toBe($countWithout);
});

test('the model hides the token when serialized but still reads it', function () {
    $booking = bcBooking();
    $token = $booking->public_token;

    expect($token)->toHaveLength(48);
    expect($booking->toArray())->not->toHaveKey('public_token');
    expect($booking->toJson())->not->toContain($token);

    $this->get(bcUrl($booking))->assertOk();
});

test('the route is bookings.show with a 48 character code', function () {
    $booking = bcBooking();
    $path = route('bookings.show', ['booking' => $booking->public_token], false);

    expect($path)->toMatch('#^/bookings/[A-Za-z0-9]{48}$#');
    expect(Route::getRoutes()->match(Request::create($path))->getName())->toBe('bookings.show');
});
