<?php

use App\Booking\BookingOutcome;
use App\Booking\CancelBooking;
use App\Booking\CancelledBy;
use App\Booking\ManageBooking;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// "Now" is Tuesday 27 October 2026, 08:00 UTC. The bookings are on Thursday 29 October from 08:00
// to 10:45 UTC: 09:00-11:45 in Italy, winter time.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
    config()->set('booking.cancellation_notice_minutes', 1440);
    app()->setLocale('it');
});

function pbwProvider(): Provider
{
    return Provider::factory()->create(['name' => 'Salone Awa', 'is_published' => true]);
}

/**
 * A booking of "Treccine" of the provider, with the given fields replaced. The status and
 * cancelled_by are not fillable: the factory sets them on the model.
 *
 * @param  array<string, mixed>  $override
 */
function pbwBooking(Provider $provider, BookingStatus $status, ?CancelledBy $cancelledBy = null, array $override = []): Booking
{
    $service = Service::factory()->for($provider)->create(['name' => 'Treccine']);

    return Booking::factory()->for($provider)->for($service)->create([
        'status' => $status,
        'cancelled_by' => $cancelledBy,
        'starts_at' => CarbonImmutable::parse('2026-10-29 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-29 10:45', 'UTC'),
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+393331234567',
        ...$override,
    ]);
}

function pbwGet(User $user): TestResponse
{
    return test()->actingAs($user)->get(route('provider.bookings.index'));
}

/**
 * The whatsapp_url of each booking in the list, by booking id.
 *
 * @return array<int, string|null>
 */
function pbwUrls(TestResponse $response): array
{
    $urls = [];
    $response->assertOk()->assertInertia(function (Assert $page) use (&$urls) {
        foreach ($page->toArray()['props']['bookings']['data'] as $booking) {
            $urls[$booking['id']] = $booking['whatsapp_url'];
        }
    });

    return $urls;
}

/**
 * The URL expected for the booking, with the given lines of the message.
 */
function pbwUrl(string $digits, string ...$lines): string
{
    return 'https://wa.me/'.$digits.'?text='.rawurlencode(implode("\n\n", $lines));
}

test('the provider\'s answer with the private address, for each outcome', function (BookingStatus $status, ?CancelledBy $cancelledBy, string $sentence) {
    $provider = pbwProvider();
    $booking = pbwBooking($provider, $status, $cancelledBy);

    expect(pbwUrls(pbwGet($provider->user)))->toBe([$booking->id => pbwUrl(
        '393331234567',
        "Ciao Awa Diop, Salone Awa {$sentence} di Treccine per giovedì 29 ottobre 2026, 09:00–11:45.",
        'Dettagli: '.route('bookings.show', ['booking' => $booking->public_token]),
    )]);
})->with([
    'confirmed' => [BookingStatus::Confirmed, null, 'ha confermato la tua prenotazione'],
    'rejected' => [BookingStatus::Cancelled, CancelledBy::ProviderReject, 'non può accettare la tua richiesta'],
    'cancelled' => [BookingStatus::Cancelled, CancelledBy::ProviderCancel, 'ha annullato la tua prenotazione'],
]);

test('the reason is in the message of a rejection, as written', function () {
    $provider = pbwProvider();
    $booking = pbwBooking($provider, BookingStatus::Cancelled, CancelledBy::ProviderReject, ['cancellation_reason' => "Sono malata & chiusa\n<b>scusa</b>"]);

    expect(pbwUrls(pbwGet($provider->user))[$booking->id])->toBe(pbwUrl(
        '393331234567',
        'Ciao Awa Diop, Salone Awa non può accettare la tua richiesta di Treccine per giovedì 29 ottobre 2026, 09:00–11:45.',
        "Motivo: Sono malata & chiusa\n<b>scusa</b>",
        'Dettagli: '.route('bookings.show', ['booking' => $booking->public_token]),
    ));
});

test('no link for a booking without the provider\'s answer', function (BookingStatus $status, ?CancelledBy $cancelledBy) {
    $provider = pbwProvider();
    $booking = pbwBooking($provider, $status, $cancelledBy);
    $response = pbwGet($provider->user);

    expect(pbwUrls($response))->toBe([$booking->id => null]);
    expect($response->getContent())->not->toContain($booking->public_token);
})->with([
    'pending' => [BookingStatus::Pending, null],
    'cancelled by the customer' => [BookingStatus::Cancelled, CancelledBy::Customer],
    'cancelled before cancelled_by existed' => [BookingStatus::Cancelled, null],
]);

test('the numbers saved before are read with the same rule, or get no link', function (string $phone, ?string $digits) {
    $provider = pbwProvider();
    $booking = pbwBooking($provider, BookingStatus::Confirmed, override: ['customer_phone' => $phone]);
    $response = pbwGet($provider->user);
    $url = pbwUrls($response)[$booking->id];

    if ($digits === null) {
        expect($url)->toBeNull();
        expect($response->getContent())->not->toContain($booking->public_token);
    } else {
        expect($url)->toStartWith("https://wa.me/{$digits}?text=");
    }
})->with([
    'with spaces' => ['+39 333 123 4567', '393331234567'],
    'Italian, without prefix' => ['333 123 4567', '393331234567'],
    'Cameroonian, without prefix' => ['677 12 34 56', '237677123456'],
    'Italian landline, without prefix' => ['06 1234567', null],
    'too short' => ['12345', null],
]);

test('in the whole list the token is only inside whatsapp_url, and only for the answered bookings', function () {
    $provider = pbwProvider();
    $answered = [
        pbwBooking($provider, BookingStatus::Confirmed),
        pbwBooking($provider, BookingStatus::Cancelled, CancelledBy::ProviderReject),
        pbwBooking($provider, BookingStatus::Cancelled, CancelledBy::ProviderCancel),
    ];
    $others = [
        pbwBooking($provider, BookingStatus::Pending),
        pbwBooking($provider, BookingStatus::Cancelled, CancelledBy::Customer),
        pbwBooking($provider, BookingStatus::Confirmed, override: ['customer_phone' => '06 1234567']),
    ];
    $response = pbwGet($provider->user);
    $html = (string) $response->getContent();
    $props = [];
    $response->assertInertia(function (Assert $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    foreach ($answered as $booking) {
        expect(substr_count($html, $booking->public_token))->toBe(1);
    }
    foreach ($others as $booking) {
        expect($html)->not->toContain($booking->public_token);
    }

    // Without whatsapp_url, no token is left anywhere in the props.
    foreach ($props['bookings']['data'] as $index => $booking) {
        expect(array_keys($booking))->toContain('whatsapp_url');
        $props['bookings']['data'][$index]['whatsapp_url'] = null;
    }
    foreach ([...$answered, ...$others] as $booking) {
        expect(json_encode($props))->not->toContain($booking->public_token);
    }
    expect($html)->not->toContain('public_token');
});

test('the number of queries does not grow with the answered bookings', function () {
    $count = function (int $bookings) {
        $provider = pbwProvider();
        for ($i = 0; $i < $bookings; $i++) {
            pbwBooking($provider, BookingStatus::Confirmed);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        pbwGet($provider->user)->assertOk();

        return $queries;
    };

    $base = $count(1);

    expect($count(5))->toBe($base);
});

test('each way of cancelling writes who cancelled; confirming writes nothing', function () {
    $provider = pbwProvider();
    $toConfirm = pbwBooking($provider, BookingStatus::Pending);
    $toReject = pbwBooking($provider, BookingStatus::Pending);
    $toCancel = pbwBooking($provider, BookingStatus::Confirmed);
    $byCustomer = pbwBooking($provider, BookingStatus::Confirmed);

    app(ManageBooking::class)->confirm($toConfirm);
    app(ManageBooking::class)->reject($toReject, null);
    app(ManageBooking::class)->cancel($toCancel, 'Motivo');
    app(CancelBooking::class)->cancel($byCustomer);

    expect($toConfirm->fresh()?->cancelled_by)->toBeNull();
    expect($toReject->fresh()?->cancelled_by)->toBe(CancelledBy::ProviderReject);
    expect($toCancel->fresh()?->cancelled_by)->toBe(CancelledBy::ProviderCancel);
    expect($byCustomer->fresh()?->cancelled_by)->toBe(CancelledBy::Customer);
    expect(DB::table('bookings')->where('id', $toReject->id)->value('cancelled_by'))->toBe('provider_reject');
});

test('cancelled_by is not fillable: only the code sets it', function () {
    $booking = new Booking;

    expect($booking->isFillable('cancelled_by'))->toBeFalse();
    $booking->fill(['cancelled_by' => 'customer']);
    expect($booking->cancelled_by)->toBeNull();
});

test('the outcome of each status and of each way of cancelling', function (BookingStatus $status, ?CancelledBy $cancelledBy, ?BookingOutcome $outcome) {
    $booking = new Booking;
    $booking->status = $status;
    $booking->cancelled_by = $cancelledBy;

    expect(BookingOutcome::of($booking))->toBe($outcome);
})->with([
    [BookingStatus::Pending, null, null],
    [BookingStatus::Confirmed, null, BookingOutcome::Confirmed],
    [BookingStatus::Cancelled, CancelledBy::ProviderReject, BookingOutcome::Rejected],
    [BookingStatus::Cancelled, CancelledBy::ProviderCancel, BookingOutcome::Cancelled],
    [BookingStatus::Cancelled, CancelledBy::Customer, null],
    [BookingStatus::Cancelled, null, null],
]);

test('the Italian texts are exactly the expected ones', function () {
    $texts = [
        'provider.bookings.whatsapp_text_confirmed' => 'Ciao :name, :provider ha confermato la tua prenotazione di :service per :when.',
        'provider.bookings.whatsapp_text_rejected' => 'Ciao :name, :provider non può accettare la tua richiesta di :service per :when.',
        'provider.bookings.whatsapp_text_cancelled' => 'Ciao :name, :provider ha annullato la tua prenotazione di :service per :when.',
        'provider.bookings.whatsapp_reason' => 'Motivo: :reason',
        'provider.bookings.whatsapp_link' => 'Dettagli: :url',
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});
