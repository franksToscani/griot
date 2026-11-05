<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

// Issue #141: the free times by part of the day. Issue #140: the provider on WhatsApp when no time
// suits, in the booking form and on the booking's page. The helpers are run with Node, as the
// browser would; the pages are read in their source (there is no JavaScript test runner).

function bcsSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * Run an expression with a helper of resources/js/lib and give back what it printed.
 */
function bcsRun(string $helper, string $expression): mixed
{
    $script = sys_get_temp_dir().'/griot-'.$helper.'-'.bin2hex(random_bytes(6)).'.mts';
    $path = resource_path("js/lib/{$helper}.ts");
    file_put_contents($script, "import * as h from '{$path}';\nconsole.log(JSON.stringify({$expression}));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The groups of some times, as period => times.
 *
 * @param  list<string>  $times
 * @return array<string, list<string>>
 */
function bcsGroups(array $times): array
{
    $slots = json_encode(array_map(fn (string $time) => ['start' => "x{$time}", 'time' => $time], $times));
    $groups = bcsRun('slot-groups', "h.groupSlotsByPeriod({$slots})");
    $out = [];

    foreach ($groups as $group) {
        $out[$group['period']] = array_column($group['slots'], 'time');
    }

    return $out;
}

// ---------------------------------------------------------------------------------------------
// #141: the times by part of the day
// ---------------------------------------------------------------------------------------------

test('the times are split into morning, afternoon and evening, at 12:00 and 18:00', function () {
    expect(bcsGroups(['09:00', '11:30', '12:00', '17:30', '18:00', '19:30']))->toBe([
        'morning' => ['09:00', '11:30'],
        'afternoon' => ['12:00', '17:30'],
        'evening' => ['18:00', '19:30'],
    ]);
    // The edges: 11:59 is still morning, 17:59 still afternoon.
    expect(bcsGroups(['11:59', '12:00', '17:59', '18:00']))->toBe([
        'morning' => ['11:59'],
        'afternoon' => ['12:00', '17:59'],
        'evening' => ['18:00'],
    ]);
});

test('an empty group is left out, one group stays one group, no time gives no group', function () {
    expect(bcsGroups(['14:00', '14:30']))->toBe(['afternoon' => ['14:00', '14:30']])
        ->and(bcsGroups(['08:00', '20:00']))->toBe(['morning' => ['08:00'], 'evening' => ['20:00']])
        ->and(bcsGroups([]))->toBe([]);
});

test('the order of the times is kept inside each group, and the slots are passed whole', function () {
    expect(bcsGroups(['09:00', '09:30', '10:00', '12:30', '13:00']))->toBe([
        'morning' => ['09:00', '09:30', '10:00'],
        'afternoon' => ['12:30', '13:00'],
    ]);
    $groups = bcsRun('slot-groups', 'h.groupSlotsByPeriod([{ start: "2026-11-05T08:00:00Z", time: "09:00" }])');
    expect($groups)->toBe([['period' => 'morning', 'slots' => [['start' => '2026-11-05T08:00:00Z', 'time' => '09:00']]]]);
});

test('the form shows a group per part of the day, named by a line of text, not by a heading', function () {
    $source = bcsSource('js/components/booking-fields.tsx');

    expect($source)
        ->toContain("import { groupSlotsByPeriod } from '@/lib/slot-groups';")
        // The group of all the times stays, with its name and the error of the day.
        ->toMatch('/<div\s+role="group"\s+aria-labelledby="booking-times"\s+aria-describedby=\{\s*timeError \? errorId\(\'booking-day\'\) : undefined\s*\}/')
        // One group per part of the day, named by its title.
        ->toMatch('/\{groupSlotsByPeriod\(slots\)\.map\(\(group\) => \(\s*<div key=\{group\.period\}[^>]*>\s*<p\s+id=\{`booking-times-\$\{group\.period\}`\}[^>]*>\s*\{t\(\s*`booking\.form\.slot_period\.\$\{group\.period\}`,?\s*\)\}\s*<\/p>\s*<div\s+role="group"\s+aria-labelledby=\{`booking-times-\$\{group\.period\}`\}/')
        // The same buttons as before.
        ->toMatch('/\{group\.slots\.map\(\(slot\) => \(\s*<Button\s+key=\{slot\.start\}\s+type="button"\s+size="sm"[\s\S]*?aria-pressed=\{\s*slot\.start === startsAt\s*\}\s+onClick=\{\(\) => onPick\(slot\.start\)\}\s*>\s*\{slot\.time\}/')
        // The count of all the times is still announced.
        ->toContain("t('booking.form.slots_found', { count: slots.length })");

    // No heading anywhere in the fields: the page keeps the order of its headings.
    expect(preg_match('/<h[1-6]\b/', $source))->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// #140: the address of the chat
// ---------------------------------------------------------------------------------------------

test('the WhatsApp text is encoded: accents, apostrophes, & ? and line breaks stay as they are', function () {
    $text = "Ciao Salone d'Awa & Co, è l'ora giusta?\nGrazie #1 + 2 / 100%";
    $url = bcsRun('whatsapp', 'h.whatsappLink("https://wa.me/393331234567", '.json_encode($text).')');

    expect($url)->toStartWith('https://wa.me/393331234567?text=')
        ->and(substr_count($url, '?'))->toBe(1)
        ->and($url)->not->toContain(' ')->not->toContain("\n")->not->toContain('&Co')->not->toContain('#');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect($query)->toBe(['text' => $text]);
});

test('the booking form offers WhatsApp only with a number, right under the times', function () {
    $source = bcsSource('js/components/booking-sheet.tsx');

    expect($source)
        ->toMatch('/<BookingDayAndTimes[\s\S]*?\/>\s*(\{\/\*[\s\S]*?\*\/\}\s*)?\{whatsappUrl !== null && \(/')
        ->toMatch("/<p className=\"text-muted-foreground\">\\s*\\{t\\('booking\\.form\\.contact_question'\\)\\}\\s*<\\/p>\\s*<a\\s+href=\\{whatsappLink\\(\\s*whatsappUrl,\\s*t\\('booking\\.form\\.contact_message', \\{\\s*provider: providerName,\\s*service: service\\.name,\\s*\\}\\),\\s*\\)\\}\\s+target=\"_blank\"\\s+rel=\"noopener noreferrer\"\\s+className=\\{cn\\(\\s*'w-fit underline underline-offset-4',\\s*touchTarget,\\s*\\)\\}\\s*>\\s*\\{t\\('booking\\.form\\.contact_link'\\)\\}\\s*<NewTabHint \\/>\\s*<\\/a>/")
        ->toContain('whatsappUrl: string | null;');

    // The page gives the number of the provider; in the preview the form is not mounted at all.
    $page = bcsSource('js/pages/providers/show.tsx');
    expect($page)
        ->toMatch('/<BookingSheet\s+providerSlug=\{providerSlug\}\s+providerName=\{providerName\}\s+service=\{service\}\s+bookingWindow=\{bookingWindow\}\s+whatsappUrl=\{whatsappUrl\}\s*\/>/')
        ->toMatch('/whatsappUrl=\{\s*whatsapp\?\.url \?\?\s*null\s*\}/')
        ->toMatch("/\\{preview \\? \\(\\s*<p[^>]*>\\s*\\{t\\('provider\\.preview\\.no_booking'\\)\\}\\s*<\\/p>\\s*\\) : \\(\\s*<BookingSheet\\b/");
});

test('the booking page offers WhatsApp for a question, with the service and the time only', function () {
    expect(bcsSource('js/pages/bookings/show.tsx'))
        ->toMatch("/\\{booking\\.provider\\.whatsapp_url !== null && \\(\\s*<div className=\"grid gap-1 text-sm\">\\s*<p className=\"text-muted-foreground\">\\s*\\{t\\('booking\\.confirmation\\.contact_question'\\)\\}\\s*<\\/p>\\s*<a\\s+href=\\{whatsappLink\\(\\s*booking\\.provider\\.whatsapp_url,\\s*t\\('booking\\.confirmation\\.contact_message', \\{\\s*service: booking\\.service\\.name,\\s*when: `\\\$\\{date\\}, \\\$\\{booking\\.local_start\\}`,\\s*\\}\\),\\s*\\)\\}\\s+target=\"_blank\"\\s+rel=\"noopener noreferrer\"[\\s\\S]*?\\{t\\('booking\\.confirmation\\.contact_link'\\)\\}\\s*<NewTabHint \\/>\\s*<\\/a>/");
});

test('the texts sent on WhatsApp never hold the customer\'s data or a private link', function () {
    // The code that builds them: the provider's name, the service, the time; nothing else.
    preg_match_all('/whatsappLink\(([\s\S]*?)\)\}\s+target=/', bcsSource('js/components/booking-sheet.tsx').bcsSource('js/pages/bookings/show.tsx'), $calls);
    expect($calls[1])->toHaveCount(2);

    foreach ($calls[1] as $call) {
        expect($call)->not->toMatch('/customer|phone|email|notes|token|private_url|location|window\.|document\./i');
    }

    // The texts: only the provider, the service, the time as placeholders.
    foreach (['it', 'fr', 'en'] as $locale) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

        foreach (['booking.form.contact_message' => [':provider', ':service'], 'booking.confirmation.contact_message' => [':service', ':when']] as $key => $placeholders) {
            preg_match_all('/:[a-z_]+/', $json[$key], $found);
            expect($found[0])->toBe($placeholders, "{$locale} {$key}");
        }
    }
});

// ---------------------------------------------------------------------------------------------
// #140: the server sends the chat only while the booking is on
// ---------------------------------------------------------------------------------------------

test('the booking page gets the provider\'s chat for a pending or confirmed booking, not a cancelled one', function (BookingStatus $status, bool $shown) {
    $provider = Provider::factory()->published()->create(['whatsapp' => '+39 333 123 4567']);
    $booking = Booking::factory()->for($provider)->create(['status' => $status, 'customer_name' => 'Mariam Kone', 'customer_phone' => '+393401112233']);

    $this->get(route('bookings.show', $booking->public_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('booking.provider.whatsapp_url', $shown ? 'https://wa.me/393331234567' : null));
})->with([
    'pending' => [BookingStatus::Pending, true],
    'confirmed' => [BookingStatus::Confirmed, true],
    'cancelled' => [BookingStatus::Cancelled, false],
]);

test('without a valid number there is no chat, and the address is the number only', function (?string $number, ?string $url) {
    $provider = Provider::factory()->published()->create(['whatsapp' => $number]);
    $booking = Booking::factory()->for($provider)->create(['status' => BookingStatus::Pending, 'customer_name' => 'Mariam Kone', 'customer_phone' => '+393401112233']);

    $this->get(route('bookings.show', $booking->public_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('booking.provider.whatsapp_url', $url));
})->with([
    'no number' => [null, null],
    'not international' => ['333 123 4567', null],
    'with 00' => ['0039 333 123 4567', 'https://wa.me/393331234567'],
]);

test('the address of the chat says nothing of the customer, even with a name full of accents', function () {
    $provider = Provider::factory()->published()->create(['name' => "Salone d'Awa & Co", 'whatsapp' => '+39 333 123 4567']);
    $booking = Booking::factory()->for($provider)->create([
        'status' => BookingStatus::Confirmed,
        'customer_name' => 'Mariam Kone',
        'customer_phone' => '+393401112233',
        'customer_email' => 'mariam@example.com',
        'notes' => 'Capelli lunghi',
    ]);

    $this->get(route('bookings.show', $booking->public_token))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($booking) {
            $url = $page->toArray()['props']['booking']['provider']['whatsapp_url'];

            expect($url)->toBe('https://wa.me/393331234567')
                ->not->toContain('Mariam')->not->toContain('3401112233')->not->toContain('mariam@')
                ->not->toContain('Capelli')->not->toContain($booking->public_token);
        });
});

// ---------------------------------------------------------------------------------------------
// Texts
// ---------------------------------------------------------------------------------------------

test('the new texts exist in Italian, French and English, with the same placeholders', function () {
    $keys = [
        'booking.form.slot_period.morning', 'booking.form.slot_period.afternoon', 'booking.form.slot_period.evening',
        'booking.form.contact_question', 'booking.form.contact_link', 'booking.form.contact_message',
        'booking.confirmation.contact_question', 'booking.confirmation.contact_link', 'booking.confirmation.contact_message',
    ];
    $json = fn (string $locale) => json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        sort($found[0]);

        return $found[0];
    };

    foreach ($keys as $key) {
        $italian = $json('it')[$key];

        foreach (['fr', 'en'] as $locale) {
            expect($json($locale))->toHaveKey($key)
                ->and($placeholders($json($locale)[$key]))->toBe($placeholders($italian), "{$locale} {$key}");
        }
    }

    // French: a non-breaking space before "?".
    expect(__('booking.form.contact_question', [], 'fr'))->toBe("Vous ne trouvez pas de créneau qui vous convient\u{00A0}?")
        ->and(__('booking.confirmation.contact_question', [], 'fr'))->toBe("Une question\u{00A0}?")
        ->and(__('booking.form.slot_period.morning', [], 'it'))->toBe('Mattina')
        ->and(__('booking.form.slot_period.afternoon', [], 'fr'))->toBe('Après-midi')
        ->and(__('booking.form.slot_period.evening', [], 'en'))->toBe('Evening');
});
