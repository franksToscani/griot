<?php

use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use Illuminate\Support\Facades\Route;

// The pages with the state of a booking ask the server again by themselves, with Inertia's polling
// (resources/js/hooks/use-live-props.ts). There is no JavaScript runner: the partial reloads the
// polling sends are tested in LivePagesTest (the same props as Back and Forward); here the server's
// headers and limits, and the sources.

function lbsSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

// ---------------------------------------------------------------------------------------------
// The server
// ---------------------------------------------------------------------------------------------

test('the provider\'s pages with the customers\' data are never stored', function (string $route) {
    $provider = Provider::factory()->create();
    $booking = Booking::factory()->for($provider)->for(Service::factory()->for($provider))->create();

    $response = $this->actingAs($provider->user)->get(route($route, $route === 'provider.bookings.show' ? $booking->id : []));

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
})->with(['provider.bookings.index', 'provider.bookings.show']);

test('the other pages of the provider keep the default cache header', function (string $route) {
    $provider = Provider::factory()->create();

    $response = $this->actingAs($provider->user)->get(route($route));

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
})->with(['dashboard', 'provider.bookings.create', 'provider.services.index']);

test('the pages the polling asks have no request limit', function (string $name) {
    $route = Route::getRoutes()->getByName($name);
    assert($route !== null);

    $limits = array_filter($route->gatherMiddleware(), fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle'));

    expect($limits)->toBe([]);
})->with(['dashboard', 'provider.bookings.index', 'provider.bookings.show', 'bookings.show']);

// ---------------------------------------------------------------------------------------------
// The hook
// ---------------------------------------------------------------------------------------------

test('the hook polls with Inertia, paused in a hidden tab, only the props asked', function () {
    $hook = lbsSource('hooks/use-live-props.ts');

    expect($hook)
        ->toContain('export const PROVIDER_INTERVAL = 20_000;')
        ->toContain('export const CUSTOMER_INTERVAL = 30_000;')
        ->toMatch("/usePoll\\(interval, requestOptions, \\{\\s*background: 'pause',\\s*autoStart: false,\\s*\\}\\);/")
        ->toContain("only: onlyKey.split(','),")
        ->toContain('const running = enabled && !paused && !idle && !failed;');
});

test('the hook stops at the first error, without the toast', function () {
    $hook = lbsSource('hooks/use-live-props.ts');

    expect($hook)
        ->toMatch('/onHttpException: \(\) => \{\s*setFailed\(true\);\s*return false;\s*\}/')
        ->toMatch('/onNetworkError: \(\) => \{\s*setFailed\(true\);\s*return false;\s*\}/');
});

test('the hook stops after 30 minutes without interaction, and starts again with a reload', function () {
    $hook = lbsSource('hooks/use-live-props.ts');

    expect($hook)
        ->toContain('export const IDLE_AFTER = 30 * 60_000;')
        ->toMatch("/const INTERACTIONS = \\[\\s*'pointerdown',\\s*'pointermove',\\s*'keydown',\\s*'touchstart',\\s*\\] as const;/")
        ->toMatch('/if \(!isIdle && Date\.now\(\) - last >= IDLE_AFTER\) \{\s*isIdle = true;\s*setIdle\(true\);/')
        ->toMatch('/if \(isIdle\) \{\s*isIdle = false;\s*setIdle\(false\);\s*if \(canReload\.current\) \{\s*router\.reload\(requestOptions\(\)\);/');
});

test('the hook holds while a window is open or a form is being sent', function () {
    $card = lbsSource('components/provider-booking-card.tsx');
    $customer = lbsSource('pages/bookings/show.tsx');

    expect(lbsSource('hooks/use-live-props.ts'))->toContain('export function PauseLiveProps({ when }: { when: boolean }): null {')
        // The reject and cancel window, and both forms of the card.
        ->and($card)->toContain('<PauseLiveProps when={open} />')
        ->and(substr_count($card, '<PauseLiveProps when={processing} />'))->toBe(2)
        // The customer's cancel window, now controlled to know when it is open, and its form.
        ->and($customer)->toContain('<Dialog open={open} onOpenChange={setOpen}>')
        ->and($customer)->toContain('<PauseLiveProps when={open} />')
        ->and($customer)->toContain('<PauseLiveProps when={processing} />');
});

// ---------------------------------------------------------------------------------------------
// The pages
// ---------------------------------------------------------------------------------------------

test('each page polls the props of the Back and Forward reload, at its interval, while its state can change', function (string $page, string $only, string $interval, string $enabled) {
    $source = lbsSource("pages/{$page}.tsx");

    expect($source)->toMatch('/useLiveProps\('.preg_quote($only, '/').', \{\s*interval: '.$interval.',\s*enabled:\s*'.$enabled.',/');

    // The same props as lib/live-pages.ts, whose reloads LivePagesTest checks on the server.
    $map = lbsSource('lib/live-pages.ts');
    expect($map)->toContain("'{$page}': {$only},");
})->with([
    'the provider\'s list' => ['provider/bookings/index', "['bookings']", 'PROVIDER_INTERVAL', 'true'],
    'the provider\'s booking' => ['provider/bookings/show', "['booking']", 'PROVIDER_INTERVAL', 'booking\.can_confirm \|\|\s*booking\.can_reject \|\|\s*booking\.can_cancel'],
    'the customer\'s page' => ['bookings/show', "['booking', 'can_cancel']", 'CUSTOMER_INTERVAL', "booking\.status !== 'cancelled' &&\s*!hasStarted\(booking\.local_date, booking\.local_start\)"],
]);

test('a status region, empty at first, in each page; the hook never moves the focus', function (string $page) {
    expect(lbsSource("pages/{$page}.tsx"))->toContain('<LiveStatus message={liveMessage} />');
})->with(['provider/bookings/index', 'provider/bookings/show', 'bookings/show']);

test('the status region is role="status", and only the list moves the focus, from a card that left it', function () {
    $region = lbsSource('components/live-status.tsx');
    $hook = lbsSource('hooks/use-live-props.ts');
    $list = lbsSource('pages/provider/bookings/index.tsx');

    expect($region)->toContain('<div role="status" className="sr-only">')
        ->not->toContain('aria-label')
        ->and($hook)->not->toContain('.focus(')
        // The text is written by the polling only (onBeforeUpdate), and emptied first.
        ->and($hook)->toContain("const [message, setMessage] = useState('');")
        ->and($hook)->toMatch('/onBeforeUpdate: \(page\) => \{\s*const text = describeRef\.current\(page\.props\);/')
        ->and(lbsSource('pages/provider/bookings/show.tsx'))->not->toContain('.focus(')
        ->and(lbsSource('pages/bookings/show.tsx'))->not->toContain('.focus(')
        // The list: one summary, and the focus to the page title only from a card that left.
        ->and($list)->toContain('<li key={booking.id} data-booking-id={booking.id}>')
        ->and($list)->toContain("document.activeElement?.closest('[data-booking-id]')")
        ->and($list)->toContain('document.getElementById(PAGE_TITLE_ID)?.focus()')
        ->and(substr_count($list, "t('provider.bookings.live.updated')"))->toBe(1);
});

test('a reload after Back or Forward never shows the error toast', function () {
    expect(lbsSource('lib/live-pages.ts'))
        ->toMatch('/router\.reload\(\{\s*only,\s*onHttpException: \(\) => false,\s*onNetworkError: \(\) => false,\s*\}\);/');
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts in the three languages', function () {
    expect(__('booking.live.status_changed', ['status' => 'confermata'], 'it'))->toBe('Lo stato della prenotazione è cambiato: confermata')
        ->and(__('booking.live.status_changed', ['status' => 'confirmée'], 'fr'))->toBe("Le statut de la réservation a changé\u{00A0}: confirmée")
        ->and(__('booking.live.status_changed', ['status' => 'confirmed'], 'en'))->toBe('The status of the booking has changed: confirmed')
        ->and(__('provider.bookings.live.updated', [], 'it'))->toBe('Le prenotazioni sono state aggiornate.')
        ->and(__('provider.bookings.live.updated', [], 'fr'))->toBe('Les réservations ont été mises à jour.')
        ->and(__('provider.bookings.live.updated', [], 'en'))->toBe('The bookings have been updated.');
});
