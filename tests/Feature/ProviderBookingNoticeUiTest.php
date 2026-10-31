<?php

// The pages have no JavaScript test runner: these tests read the source. They break on a rename or
// a different shape of the JSX even when the behaviour is right, and they do not prove rendering.

function pbnSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

test('the WhatsApp button is shown only with whatsapp_url, in a new tab without the opener', function () {
    $source = pbnSource('js/pages/provider/bookings/index.tsx');

    expect($source)->toMatch('/\{booking\.whatsapp_url !== null && \(\s*<div>\s*<Button\b[^>]*>\s*<a\s+href=\{booking\.whatsapp_url\}\s+target="_blank"\s+rel="noopener noreferrer"\s*>\s*\{t\(\'provider\.bookings\.whatsapp_button\'\)\}/');
    expect(substr_count($source, "'provider.bookings.whatsapp_button'"))->toBe(1);
});

test('the email line and the "no contact" line follow their exact conditions', function () {
    $source = pbnSource('js/pages/provider/bookings/index.tsx');

    // The outcome comes from the server: the page no longer works it out from the status.
    // A booking the provider wrote and that is still confirmed got no email (issue #49).
    expect($source)->toMatch('/\{booking\.outcome !== null &&\s*booking\.customer_email !== null &&\s*!\(\s*booking\.created_by_provider &&\s*booking\.outcome === \'confirmed\'\s*\) && \(\s*<p\b[^>]*>\s*\{t\(\'provider\.bookings\.email_notice\', \{\s*email: booking\.customer_email,?\s*\}\)\}/');
    expect($source)->toMatch('/\{booking\.outcome !== null &&\s*booking\.whatsapp_url === null &&\s*booking\.customer_email === null && \(\s*<p\b[^>]*>\s*\{t\(\'provider\.bookings\.no_contact\'\)\}/');
    // The notice block, from its comment to the end of the card, never reads the status.
    expect(preg_match('/How the customer hears of the answer.*?<\/CardContent>/s', $source, $block))->toBe(1);
    expect($block[0])->not->toContain('booking.status');
    expect(substr_count($source, "'provider.bookings.email_notice'"))->toBe(1);
    expect(substr_count($source, "'provider.bookings.no_contact'"))->toBe(1);
    expect($source)->not->toContain('mailto:')->not->toContain('tel:');
});

test('the phone field of the booking form has its help text, linked to the input', function () {
    // The fields live in booking-fields.tsx since issue #49; each form chooses its help text.
    $source = pbnSource('js/components/booking-fields.tsx');

    expect($source)->toMatch('/<Input\s+id="customer_phone"[^>]*aria-describedby="customer_phone-help"[^>]*\/>/');
    expect($source)->toMatch('/<p\s+id="customer_phone-help"[^>]*>\s*\{t\(phoneHelpKey\)\}/');
    expect(pbnSource('js/components/booking-sheet.tsx'))->toMatch('/<CustomerFields\s+nameLabelKey="booking\.form\.name"\s+phoneHelpKey="booking\.phone\.help"/');
});

test('the notice block reads created_by_provider only in the email line', function () {
    $source = pbnSource('js/pages/provider/bookings/index.tsx');

    // Once in the whole page: the WhatsApp button and the "no contact" line do not depend on it.
    expect(substr_count($source, 'created_by_provider'))->toBe(1);
    expect(preg_match('/How the customer hears of the answer.*?<\/CardContent>/s', $source, $block))->toBe(1);
    expect(preg_match('/\{booking\.outcome !== null &&\s*booking\.customer_email !== null &&\s*!\(\s*booking\.created_by_provider &&/', $block[0]))->toBe(1);
});

test('the Italian texts are exactly the expected ones', function () {
    $texts = [
        'booking.phone.help' => 'Per esempio +39 333 123 4567. Ti avviseremo su questo numero e per email, se la scrivi.',
        'provider.bookings.whatsapp_button' => 'Avvisa su WhatsApp',
        'provider.bookings.email_notice' => 'Avviso inviato per email a :email',
        'provider.bookings.no_contact' => 'Numero non valido: contatta il cliente in altro modo.',
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});
