<?php

use App\Booking\CancelBooking;
use App\Booking\ManageBooking;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

// Back and Forward ask the server again for the booking's props of three pages
// (resources/js/lib/live-pages.ts). There is no JavaScript runner: the server side is tested with
// the partial reload the listener sends (reloadOnly), the browser side by reading the sources.

// "Now" is Monday 2 November 2026, 08:00 UTC; the booking is ten days later.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'UTC'));
    app()->setLocale('it');
});

/**
 * A pending booking of a provider with a user, far enough to be confirmed, rejected or cancelled.
 */
function lpBooking(): Booking
{
    $provider = Provider::factory()->published()->create(['name' => 'Salone Awa']);
    $service = Service::factory()->for($provider)->create(['name' => 'Treccine']);

    return Booking::factory()->for($provider)->for($service)->create([
        'status' => BookingStatus::Pending,
        'starts_at' => CarbonImmutable::parse('2026-11-12 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-11-12 09:00', 'UTC'),
        'customer_name' => 'Awa Diop',
        'customer_email' => 'awa@example.test',
    ]);
}

/**
 * The map of lib/live-pages.ts, read from the source: component => props.
 *
 * @return array<string, list<string>>
 */
function lpMap(): array
{
    $source = (string) file_get_contents(resource_path('js/lib/live-pages.ts'));
    preg_match('/export const LIVE_PAGES: Record<string, string\[\]> = \{(.*?)\};/s', $source, $block);
    preg_match_all("/'([a-z\/]+)': \[([^\]]*)\]/", $block[1] ?? '', $entries, PREG_SET_ORDER);

    $map = [];
    foreach ($entries as [, $component, $props]) {
        preg_match_all("/'([a-z_]+)'/", $props, $names);
        $map[$component] = $names[1];
    }

    return $map;
}

// ---------------------------------------------------------------------------------------------
// The partial reloads give the state of now
// ---------------------------------------------------------------------------------------------

test('the provider\'s list: the reload of "bookings" gives the new state, and nothing else', function () {
    $booking = lpBooking();
    $response = $this->actingAs($booking->provider->user)->get(route('provider.bookings.index'));

    // Confirmed in another tab, after the page was shown.
    app(ManageBooking::class)->confirm($booking);

    $response->assertInertia(fn (Assert $page) => $page
        ->component('provider/bookings/index')
        ->where('bookings.data.0.status', 'pending')
        ->where('bookings.data.0.can_confirm', true)
        ->reloadOnly(lpMap()['provider/bookings/index'], fn (Assert $reload) => $reload
            ->where('bookings.data.0.id', $booking->id)
            ->where('bookings.data.0.status', 'confirmed')
            ->where('bookings.data.0.can_confirm', false)
            ->where('bookings.data.0.can_reject', false)
            ->where('bookings.data.0.can_cancel', true)
            ->missing('filters')
            ->missing('translations')
            ->missing('auth')));
});

test('the provider\'s booking page: the reload of "booking" gives the new state, and nothing else', function () {
    $booking = lpBooking();
    $response = $this->actingAs($booking->provider->user)->get(route('provider.bookings.show', $booking->id));

    app(ManageBooking::class)->reject($booking, 'Chiuso per ferie');

    $response->assertInertia(fn (Assert $page) => $page
        ->component('provider/bookings/show')
        ->where('booking.status', 'pending')
        ->reloadOnly(lpMap()['provider/bookings/show'], fn (Assert $reload) => $reload
            ->where('booking.status', 'cancelled')
            ->where('booking.outcome', 'rejected')
            ->where('booking.can_confirm', false)
            ->where('booking.can_reject', false)
            ->where('booking.can_cancel', false)
            ->missing('translations')
            ->missing('auth')));
});

test('the customer\'s private page: the reload of "booking" and "can_cancel" gives the new state, and nothing else', function () {
    $booking = lpBooking();
    $response = $this->get(route('bookings.show', $booking->public_token));

    // Cancelled from the phone, while the page stays open on the computer.
    expect(app(CancelBooking::class)->cancel($booking))->toBeTrue();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('bookings/show')
        ->where('booking.status', 'pending')
        ->where('can_cancel', true)
        ->reloadOnly(lpMap()['bookings/show'], fn (Assert $reload) => $reload
            ->where('booking.status', 'cancelled')
            ->where('can_cancel', false)
            ->missing('private_url')
            ->missing('translations')));
});

// ---------------------------------------------------------------------------------------------
// The listener, read from the sources
// ---------------------------------------------------------------------------------------------

test('the map names the components and the props of the controllers', function () {
    expect(lpMap())->toBe([
        'provider/bookings/index' => ['bookings'],
        'provider/bookings/show' => ['booking'],
        'bookings/show' => ['booking', 'can_cancel'],
    ]);

    // Each component and each prop is the one the controller renders.
    $controllers = [
        'provider/bookings/index' => 'ProviderBookingIndexController',
        'provider/bookings/show' => 'ProviderBookingShowController',
        'bookings/show' => 'BookingConfirmationController',
    ];
    foreach (lpMap() as $component => $props) {
        $source = (string) file_get_contents(app_path("Http/Controllers/{$controllers[$component]}.php"));
        expect($source)->toContain("Inertia::render('{$component}', [");
        foreach ($props as $prop) {
            expect($source)->toMatch("/^\s+'{$prop}' => /m");
        }
    }
});

test('the listener reloads on Back and Forward and on a page restored from the back/forward cache', function () {
    $source = (string) file_get_contents(resource_path('js/lib/live-pages.ts'));

    expect($source)
        ->toContain("router.on('navigate', (event) => {")
        ->toContain('currentComponent = event.detail.page.component;')
        ->toContain("if (event.detail.type === 'history') {")
        ->toContain("window.addEventListener('pageshow', (event) => {")
        ->toContain('if (event.persisted) {')
        ->toMatch('/router\.reload\(\{\s*only,\s*onHttpException: \(\) => false,\s*onNetworkError: \(\) => false,\s*\}\);/')
        // Only a reload of some props: never a whole page, never a polling.
        ->not->toContain('router.reload()')
        ->not->toContain('usePoll')
        ->not->toContain('setInterval');
});

test('the listener is registered in app.tsx before the app starts', function () {
    $source = (string) file_get_contents(resource_path('js/app.tsx'));

    expect($source)->toContain("import { refreshLivePagesOnHistory } from '@/lib/live-pages';")
        ->and(substr_count($source, 'refreshLivePagesOnHistory();'))->toBe(1)
        ->and(strpos($source, 'refreshLivePagesOnHistory();'))->toBeLessThan(strpos($source, 'void createInertiaApp('));
});
