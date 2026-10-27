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

    // The email: only for an answer of the provider (confirmed, or whatsapp_url present).
    expect($source)->toMatch('/\{booking\.customer_email !== null &&\s*\(booking\.status === \'confirmed\' \|\|\s*booking\.whatsapp_url !== null\) && \(\s*<p\b[^>]*>\s*\{t\(\'provider\.bookings\.email_notice\', \{\s*email: booking\.customer_email,?\s*\}\)\}/');
    // No contact: only a confirmed booking, where a missing whatsapp_url means a number that cannot be read.
    expect($source)->toMatch('/\{booking\.status === \'confirmed\' &&\s*booking\.whatsapp_url === null &&\s*booking\.customer_email === null && \(\s*<p\b[^>]*>\s*\{t\(\'provider\.bookings\.no_contact\'\)\}/');
    expect(substr_count($source, "'provider.bookings.email_notice'"))->toBe(1);
    expect(substr_count($source, "'provider.bookings.no_contact'"))->toBe(1);
    expect($source)->not->toContain('mailto:')->not->toContain('tel:');
});

test('the phone field of the booking form has its help text, linked to the input', function () {
    $source = pbnSource('js/components/booking-sheet.tsx');

    expect($source)->toMatch('/<Input\s+id="customer_phone"[^>]*aria-describedby="customer_phone-help"[^>]*\/>/');
    expect($source)->toMatch('/<p\s+id="customer_phone-help"[^>]*>\s*\{t\(\'booking\.phone\.help\'\)\}/');
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
