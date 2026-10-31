<?php

use App\Booking\CancelledBy;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// "Now" is Tuesday 27 October 2026, 08:00 UTC. The bookings are on Thursday 29 October.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
    app()->setLocale('it');
});

function pboProvider(): Provider
{
    return Provider::factory()->create(['name' => 'Salone Awa', 'is_published' => true]);
}

/**
 * A booking of the provider. The status and cancelled_by are not fillable: the factory sets them
 * on the model.
 */
function pboBooking(Provider $provider, BookingStatus $status, ?CancelledBy $cancelledBy = null): Booking
{
    return Booking::factory()->for($provider)->for(Service::factory()->for($provider))->create([
        'status' => $status,
        'cancelled_by' => $cancelledBy,
        'starts_at' => CarbonImmutable::parse('2026-10-29 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-29 09:00', 'UTC'),
        'customer_phone' => '+393331234567',
    ]);
}

function pboGet(User $user): TestResponse
{
    return test()->actingAs($user)->get(route('provider.bookings.index'));
}

/**
 * The props of the bookings page.
 *
 * @return array<string, mixed>
 */
function pboProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

test('the outcome of each kind of booking, in the list', function (BookingStatus $status, ?CancelledBy $cancelledBy, ?string $outcome) {
    $provider = pboProvider();
    pboBooking($provider, $status, $cancelledBy);

    expect(pboProps(pboGet($provider->user))['bookings']['data'][0]['outcome'])->toBe($outcome);
})->with([
    'pending' => [BookingStatus::Pending, null, null],
    'confirmed' => [BookingStatus::Confirmed, null, 'confirmed'],
    'rejected by the provider' => [BookingStatus::Cancelled, CancelledBy::ProviderReject, 'rejected'],
    'cancelled by the provider' => [BookingStatus::Cancelled, CancelledBy::ProviderCancel, 'cancelled'],
    'cancelled by the customer' => [BookingStatus::Cancelled, CancelledBy::Customer, null],
    'old cancelled, cancelled_by null' => [BookingStatus::Cancelled, null, null],
]);

test('cancelled_by stays on the server', function () {
    $provider = pboProvider();
    pboBooking($provider, BookingStatus::Cancelled, CancelledBy::ProviderReject);
    pboBooking($provider, BookingStatus::Cancelled, CancelledBy::ProviderCancel);
    pboBooking($provider, BookingStatus::Cancelled, CancelledBy::Customer);
    $response = pboGet($provider->user);
    $props = pboProps($response);
    $html = (string) $response->getContent();

    // The values, in the whole response. "customer" is also part of customer_name and of other
    // keys: it is searched as a whole JSON string, as it would be written in the page props.
    expect($html)->not->toContain('provider_reject')->not->toContain('provider_cancel');
    expect(json_encode($response->viewData('page')))->not->toContain('"customer"');
    expect($html)->not->toContain('&quot;customer&quot;');
    // The word, in the bookings only: the shared translations have the key
    // provider.bookings.cancelled_by_provider (the text of a toast).
    expect(json_encode($props['bookings']))->not->toContain('cancelled_by');
    foreach ($props['bookings']['data'] as $booking) {
        expect($booking)->not->toHaveKey('cancelled_by');
    }
});

test('the number of queries is the same with 1 or 5 bookings', function () {
    $count = function (int $bookings) {
        $provider = pboProvider();
        $kinds = [
            [BookingStatus::Confirmed, null],
            [BookingStatus::Cancelled, CancelledBy::ProviderReject],
            [BookingStatus::Cancelled, CancelledBy::ProviderCancel],
            [BookingStatus::Cancelled, CancelledBy::Customer],
            [BookingStatus::Pending, null],
        ];
        for ($i = 0; $i < $bookings; $i++) {
            pboBooking($provider, ...$kinds[$i]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        pboGet($provider->user)->assertOk();

        return $queries;
    };

    expect($count(5))->toBe($count(1));
});
