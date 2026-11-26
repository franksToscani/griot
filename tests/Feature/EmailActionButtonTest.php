<?php

use App\Booking\BookingOutcome;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Notifications\BookingReceivedNotification;
use App\Notifications\BookingStatusNotification;
use App\Notifications\CustomerCancelledNotification;
use App\Notifications\NewBookingNotification;
use App\Notifications\PendingReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;

// Clearer emails (#53, docs/bookings.md « Bottone d'azione nelle email »): the action as a full
// button in the site's green right after the first sentence, the same address written out under the
// details, one partial for the four emails with a link; "label: value" in the text versions through
// a translation, so French has its no-break space before the colon. No email is sent: the views are
// rendered from the notifications' MailMessage.

function eabBooking(array $override = []): Booking
{
    $provider = Provider::factory()->published()->create(['name' => 'Salone Awa']);
    $service = Service::factory()->for($provider)->create(['name' => 'Treccine']);

    return Booking::factory()->for($provider)->for($service)->create([
        'starts_at' => CarbonImmutable::parse('2026-10-29 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-29 10:45', 'UTC'),
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+39 333 123 4567',
        'customer_email' => 'awa@example.test',
        'notes' => 'Capelli lunghi',
        ...$override,
    ]);
}

/**
 * The emails, by name: the MailMessage, the address of its action (null without one), the text of
 * the button in Italian.
 *
 * @return array<string, array{0: MailMessage, 1: ?string, 2: ?string}>
 */
function eabMails(): array
{
    $booking = eabBooking();
    $user = $booking->provider->user;
    $customer = new AnonymousNotifiable;
    $private = route('bookings.show', ['booking' => $booking->public_token]);

    return [
        'new booking' => [(new NewBookingNotification($booking))->toMail($user), route('provider.bookings.show', ['booking' => $booking->id]), 'Apri la richiesta'],
        'reminder' => [(new PendingReminderNotification($booking))->toMail($user), url('/provider/bookings?status=pending'), 'Vai alle richieste in attesa'],
        'received' => [(new BookingReceivedNotification($booking))->toMail($customer), $private, 'Vedi la tua prenotazione'],
        'status' => [(new BookingStatusNotification($booking, BookingOutcome::Confirmed))->toMail($customer), $private, 'Vedi la tua prenotazione'],
        'cancelled by the customer' => [(new CustomerCancelledNotification($booking))->toMail($user), null, null],
    ];
}

function eabText(MailMessage $mail): string
{
    return view($mail->view['text'], $mail->viewData)->render();
}

function eabPartial(string $name): string
{
    return (string) file_get_contents(resource_path("views/mail/partials/{$name}.blade.php"));
}

// ---------------------------------------------------------------------------------------------
// The button
// ---------------------------------------------------------------------------------------------

test('the button: a table, the green on the cell, a block link 44px high, cream bold text, no image', function () {
    $button = eabPartial('action-button');

    expect($button)
        ->toContain('<table role="presentation" cellpadding="0" cellspacing="0"')
        ->toContain('<td bgcolor="#005c48" style="background-color: #005c48;')
        ->toMatch('/<a href="\{\{ \$url \}\}" style="display: block; padding: 12px 20px; line-height: 20px; font-size: 16px; color: #fefcf7; font-weight: bold; text-decoration: none;">\{\{ \$label \}\}<\/a>/')
        ->not->toContain('<img');

    // 12 + 20 + 12: the whole cell is the link, at least 44px high.
    preg_match('/padding: (\d+)px \d+px; line-height: (\d+)px;/', $button, $box);
    expect(2 * (int) $box[1] + (int) $box[2])->toBeGreaterThanOrEqual(44);

    // Cream on the green: at least 4.5:1 (7.8:1).
    $luminance = function (string $hex): float {
        $channels = array_map(fn (string $pair) => hexdec($pair) / 255, str_split(ltrim($hex, '#'), 2));
        [$r, $g, $b] = array_map(fn (float $c) => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    };
    $ratio = ($luminance('#fefcf7') + 0.05) / ($luminance('#005c48') + 0.05);
    expect(round($ratio, 1))->toBe(7.8);
});

test('the four emails with a link: the button right after the first sentence, the same address written out under the details', function () {
    foreach (eabMails() as $name => [$mail, $url, $label]) {
        $html = (string) $mail->render();

        if ($url === null) {
            // The cancellation by the customer has no link, and gets none.
            expect(str_contains($html, '<a ') || str_contains($html, '#005c48'))->toBeFalse($name);

            continue;
        }

        $escaped = e($url);
        $button = strpos($html, '<td bgcolor="#005c48"');
        $details = strpos($html, '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse: collapse;">');
        $fallback = strpos($html, e(__('booking.mail.fallback')));

        expect($button)->not->toBeFalse($name)
            // After the first two paragraphs (greeting and first sentence), before the details.
            ->and(substr_count(substr($html, 0, $button), '<p>'))->toBe(2, $name)
            ->and($button)->toBeLessThan($details)
            ->and($fallback)->toBeGreaterThan($details)
            ->and($html)->toContain('>'.e($label).'</a>')
            // The address written out is the button's, as text and as link.
            ->and(substr_count($html, '<a href="'.$escaped.'"'))->toBe(2, $name)
            ->and($html)->toContain('>'.$escaped.'</a>')
            ->and(substr_count($html, 'bgcolor="#005c48"'))->toBe(1, $name);
    }
});

test('the text versions: the action on a line, the address on the next one, in the four emails', function () {
    foreach (eabMails() as $name => [$mail, $url, $label]) {
        $text = eabText($mail);

        if ($url === null) {
            expect(str_contains($text, 'http'))->toBeFalse($name);

            continue;
        }

        expect(str_contains($text, $label."\n".$url."\n"))->toBeTrue($name)
            ->and(substr_count($text, $url))->toBe(1, $name);
    }
});

test('the four views use the two partials, once each; the old link sentence is gone', function () {
    foreach (['new-booking', 'pending-reminder', 'booking-received', 'booking-status'] as $view) {
        $source = (string) file_get_contents(resource_path("views/mail/{$view}.blade.php"));

        expect(substr_count($source, "@include('mail.partials.action-button'"))->toBe(1, $view)
            ->and(substr_count($source, "@include('mail.partials.fallback-link'"))->toBe(1, $view)
            ->and($source)->not->toContain('booking.notice.link')
            ->not->toContain('style="color: #18181b;">{{ __(');
    }
    expect((string) file_get_contents(resource_path('views/mail/customer-cancelled.blade.php')))->not->toContain('@include');
    expect(eabPartial('fallback-link'))
        ->toContain("{{ __('booking.mail.fallback') }}<br><a href=\"{{ \$url }}\" style=\"color: #18181b; text-decoration: underline; word-break: break-all;\">{{ \$url }}</a>");
});

// ---------------------------------------------------------------------------------------------
// "Label: value" in the text versions
// ---------------------------------------------------------------------------------------------

test('in French every "label: value" of the text versions has the no-break space before the colon', function () {
    app()->setLocale('fr');

    foreach (eabMails() as $name => [$mail]) {
        $text = eabText($mail);
        // No colon after a letter or a space that is not U+00A0 (an address has "://", not ": ").
        expect(preg_match('/[^\x{00A0}]:(?: |\n)/u', $text))->toBe(0, "{$name}: {$text}")
            ->and(preg_match('/\x{00A0}:/u', $text))->toBe(1, $name);
    }
});

test('in Italian and English "label: value" is as before', function () {
    $mails = eabMails();
    expect(eabText($mails['new booking'][0]))
        ->toContain("Servizio: Treccine\n")
        ->toContain("Quando: giovedì 29 ottobre 2026, 09:00–11:45\n")
        ->toContain("Note:\nCapelli lunghi\n");
    expect(eabText($mails['cancelled by the customer'][0]))->toContain('Cliente: Awa Diop');

    app()->setLocale('en');
    expect(eabText(eabMails()['received'][0]))->toContain("Service: Treccine\n");

    // Every "label: value" of the five text views goes through the translation: none is written by hand.
    foreach (glob(resource_path('views/mail/*-text.blade.php')) ?: [] as $view) {
        expect((string) file_get_contents($view))->not->toMatch('/(!!\}|\}\}): /')->not->toMatch('/!!\}:$/m', basename($view));
    }
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts of the emails in the three languages, with the French spaces; the old link sentence is gone', function () {
    $nb = "\u{00A0}";
    $expected = [
        'it' => ['booking.notification.action' => 'Apri la richiesta', 'booking.reminder.action' => 'Vai alle richieste in attesa', 'booking.notice.action' => 'Vedi la tua prenotazione', 'booking.mail.fallback' => 'Se il pulsante non funziona, copia questo indirizzo:', 'booking.mail.label_value' => ':label: :value', 'booking.mail.label' => ':label:'],
        'fr' => ['booking.notification.action' => 'Ouvrir la demande', 'booking.reminder.action' => 'Voir les demandes en attente', 'booking.notice.action' => 'Voir votre réservation', 'booking.mail.fallback' => "Si le bouton ne fonctionne pas, copiez cette adresse{$nb}:", 'booking.mail.label_value' => ":label{$nb}: :value", 'booking.mail.label' => ":label{$nb}:"],
        // "booking request", the term of the English texts (EnglishTest).
        'en' => ['booking.notification.action' => 'Open the request', 'booking.reminder.action' => 'Go to your pending booking requests', 'booking.notice.action' => 'View your booking', 'booking.mail.fallback' => 'If the button does not work, copy this address:', 'booking.mail.label_value' => ':label: :value', 'booking.mail.label' => ':label:'],
    ];

    foreach ($expected as $locale => $texts) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        expect($json)->not->toHaveKey('booking.notice.link');
        foreach ($texts as $key => $text) {
            expect($json[$key] ?? null)->toBe($text, "{$locale} {$key}");
        }
    }
});

// ---------------------------------------------------------------------------------------------
// The subtitle of the settings
// ---------------------------------------------------------------------------------------------

test('the settings say "email" only to a provider, "name and email" to the others', function () {
    expect((string) file_get_contents(resource_path('js/pages/settings/profile.tsx')))
        ->toMatch("/description=\\{t\\(\\s*provider_name !== null\\s*\\? 'settings\\.profile\\.description_provider'\\s*: 'settings\\.profile\\.description',?\\s*\\)\\}/");

    $texts = [
        'it' => ["Aggiorna l'indirizzo email", "Aggiorna il nome e l'indirizzo email"],
        'fr' => ['Modifiez votre adresse e-mail', 'Modifiez votre nom et votre adresse e-mail'],
        'en' => ['Update your email address', 'Update your name and email address'],
    ];
    foreach ($texts as $locale => [$provider, $others]) {
        expect(__('settings.profile.description_provider', [], $locale))->toBe($provider)
            ->and(__('settings.profile.description', [], $locale))->toBe($others);
    }
});
