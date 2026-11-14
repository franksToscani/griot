<?php

use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use Carbon\CarbonImmutable;

// The language selector sends the address of its page ("return_to"), and the language change goes
// back there: the Referer can be missing (after the customer's private page, whose meta referrer
// "no-referrer" stays for the whole tab), and back() would then take the last URL of the session.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'UTC'));
    app()->setLocale('it');
});

/**
 * A published provider with a service: its page and its free slots.
 *
 * @return array{Provider, Service}
 */
function lrProvider(): array
{
    $provider = Provider::factory()->published()->create(['name' => 'Salone Awa']);

    return [$provider, Service::factory()->for($provider)->create()];
}

/**
 * The free slots of a service, as useFreeSlots() asks them: with or without X-Requested-With.
 */
function lrSlots(Provider $provider, Service $service, bool $ajax): void
{
    $headers = ['Accept' => 'application/json', ...($ajax ? ['X-Requested-With' => 'XMLHttpRequest'] : [])];

    test()->get(route('providers.slots', ['publishedProvider' => $provider->slug, 'service' => $service->id, 'date' => '2026-11-03']), $headers)->assertOk();
}

// ---------------------------------------------------------------------------------------------
// Back to the page of the selector
// ---------------------------------------------------------------------------------------------

test('without a Referer the choice goes back to the page sent, not to the slots', function () {
    [$provider, $service] = lrProvider();
    $page = route('providers.show', $provider->slug, absolute: false);

    // The fault: slots read without X-Requested-With were the session's previous URL, and with no
    // Referer back() sent the language change to their JSON.
    lrSlots($provider, $service, ajax: false);

    $this->post(route('locale.update'), ['locale' => 'fr', 'return_to' => $page])
        ->assertRedirect(url($page))
        ->assertSessionHasNoErrors()
        ->assertCookie('locale', 'fr');
});

test('the address sent wins over the Referer', function () {
    $this->from('/providers')
        ->post(route('locale.update'), ['locale' => 'en', 'return_to' => '/terms'])
        ->assertRedirect(url('/terms'))
        ->assertCookie('locale', 'en');
});

test('a path of the site is kept as it is, query included', function (string $path) {
    $this->post(route('locale.update'), ['locale' => 'fr', 'return_to' => $path])
        ->assertRedirect(url($path))
        ->assertCookie('locale', 'fr');
})->with([
    'the home page' => ['/'],
    'a page' => ['/providers/salone-awa'],
    'a query' => ['/providers?city=Roma&page=2'],
    'an encoded character' => ['/providers?q=caf%C3%A9'],
]);

test('any other address is ignored: back to the Referer, and the language changes anyway', function (mixed $returnTo) {
    $this->from('/providers')
        ->post(route('locale.update'), ['locale' => 'fr', 'return_to' => $returnTo])
        ->assertRedirect(url('/providers'))
        ->assertSessionHasNoErrors()
        ->assertCookie('locale', 'fr');
})->with([
    'another host' => ['//evil.test'],
    'another host with a backslash' => ['/\\evil.test'],
    'a backslash only' => ['\\\\evil.test'],
    'a backslash later' => ['/providers\\..\\evil'],
    'an absolute address' => ['https://evil.test'],
    'a scheme of this site' => ['http://localhost/providers'],
    'javascript' => ['javascript:alert(1)'],
    'no slash' => ['evil.test'],
    'empty' => [''],
    'an array' => [['/providers']],
    'a line break' => ["/providers\r\nSet-Cookie: x=1"],
    'a tab' => ["/\tevil.test"],
    'a null byte' => ["/providers\0"],
    'too long' => ['/'.str_repeat('a', 2048)],
]);

test('an address ignored without a Referer goes to the home page, as before', function () {
    $this->post(route('locale.update'), ['locale' => 'fr', 'return_to' => '//evil.test'])
        ->assertRedirect(route('home'))
        ->assertCookie('locale', 'fr');
});

test('the private page of a booking: back to it, with its code, in the new language', function () {
    $booking = Booking::factory()->create();
    $page = route('bookings.show', $booking->public_token, absolute: false);

    $response = $this->post(route('locale.update'), ['locale' => 'en', 'return_to' => $page]);

    $response->assertRedirect(url($page))->assertCookie('locale', 'en');
    $this->withCookie('locale', 'en')->get($page)->assertOk();
});

// ---------------------------------------------------------------------------------------------
// The slots are never the previous URL
// ---------------------------------------------------------------------------------------------

test('slots read with X-Requested-With are not the session\'s previous URL', function () {
    [$provider, $service] = lrProvider();
    $page = route('providers.show', $provider->slug);

    $this->get($page)->assertOk();
    lrSlots($provider, $service, ajax: true);

    // No Referer and no address sent: back() takes the last page, not the slots.
    $this->post(route('locale.update'), ['locale' => 'fr'])->assertRedirect($page);
});

// ---------------------------------------------------------------------------------------------
// The sources
// ---------------------------------------------------------------------------------------------

test('the selector sends the address of its page', function () {
    $source = (string) file_get_contents(resource_path('js/components/locale-switcher.tsx'));

    expect($source)->toContain('const { props, url } = usePage();')
        ->toContain('{ locale: value, return_to: url },');
});

test('the free slots are asked with X-Requested-With', function () {
    $source = (string) file_get_contents(resource_path('js/hooks/use-free-slots.ts'));

    expect($source)->toMatch("/fetch\\(url, \\{\\s*headers: \\{\\s*Accept: 'application\\/json',\\s*'X-Requested-With': 'XMLHttpRequest',\\s*\\},/");
});
