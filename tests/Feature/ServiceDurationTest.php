<?php

use App\Booking\BookingDuration;
use App\Booking\LocalTime;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

// #53: the rule of the durations is confirmed, its presentation corrected (docs/bookings.md). A
// booking keeps the provider busy for the longest duration plus the minutes of the options; the
// form asks first for that "time to keep free", with a live example, and the shortest duration is
// optional and only for the customer, who sees until when the chosen time may last.

// "Now" is Sunday 18 October 2026, 08:00 UTC. Monday 19 October is summer time (UTC+2): the provider
// works there from 09:00 to 19:00 local, 07:00-17:00 UTC.
const SD_MONDAY = '2026-10-19';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    app()->setLocale('it');
});

/**
 * A published provider working on Mondays from 09:00 to 19:00.
 */
function sdProvider(): Provider
{
    $provider = Provider::factory()->published()->create();
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '19:00:00',
    ]);

    return $provider;
}

/**
 * The braids of docs/bookings.md: usually from 120 to 180 minutes, an option of 30 more.
 *
 * @return array{0: Service, 1: ServiceOption}
 */
function sdBraids(Provider $provider, int $min = 120): array
{
    $service = Service::factory()->for($provider)->create(['name' => 'Treccine', 'duration_min' => $min, 'duration_max' => 180]);

    return [$service, ServiceOption::factory()->for($service)->create(['name' => 'Perline', 'extra_duration' => 30])];
}

/**
 * The free slots of the braids with the option, on the Monday, as the form receives them.
 *
 * @return list<array{start: string, time: string, end_time: string}>
 */
function sdSlots(Provider $provider, Service $service, ServiceOption $option): array
{
    return test()->getJson(route('providers.slots', $provider->slug).'?'.http_build_query([
        'service' => $service->id,
        'date' => SD_MONDAY,
        'options' => [$option->id],
    ]))->assertOk()->json('slots');
}

/**
 * A valid service form, with the given fields replaced (null removes the field).
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function sdForm(array $override = []): array
{
    $form = [
        'name' => 'Box braids',
        'category_id' => Category::factory()->create()->id,
        'price' => '60',
        'duration_min' => 120,
        'duration_max' => 180,
        ...$override,
    ];

    return array_filter($form, fn (mixed $value) => $value !== null);
}

/**
 * Run resources/js/lib/format.ts with Node, with a t() that reads lang/{locale}.json the way the
 * use-translation hook does, and give back what the expression printed.
 */
function sdFormat(string $expression, string $locale = 'it'): mixed
{
    $script = sys_get_temp_dir().'/griot-duration-'.bin2hex(random_bytes(6)).'.mts';
    $format = resource_path('js/lib/format.ts');
    $lang = lang_path("{$locale}.json");
    file_put_contents($script, <<<JS
        import * as f from '{$format}';
        import { readFileSync } from 'node:fs';
        const texts = JSON.parse(readFileSync('{$lang}', 'utf8'));
        const t = (key, replacements = {}) => {
            let text = texts[key] ?? key;
            for (const [name, value] of Object.entries(replacements)) {
                text = text.split(':' + name).join(String(value));
            }
            return text;
        };
        console.log(JSON.stringify({$expression}));
        JS);

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

function sdSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

// ---------------------------------------------------------------------------------------------
// The form of a service
// ---------------------------------------------------------------------------------------------

test('an empty shortest duration is saved equal to the time kept free, when adding and when editing', function (array $empty) {
    $provider = sdProvider();

    $this->actingAs($provider->user)->post(route('provider.services.store'), sdForm($empty))->assertSessionHasNoErrors();
    $service = Service::query()->sole();
    expect($service->only(['duration_min', 'duration_max']))->toBe(['duration_min' => 180, 'duration_max' => 180]);

    // Edit: from 120-180 to an empty minimum and 90 to keep free.
    $service->update(['duration_min' => 120]);
    $this->actingAs($provider->user)->put(route('provider.services.update', $service->id), sdForm([...$empty, 'duration_max' => 90]))->assertSessionHasNoErrors();
    expect($service->fresh()->only(['duration_min', 'duration_max']))->toBe(['duration_min' => 90, 'duration_max' => 90]);
})->with([
    'left out' => [['duration_min' => null]],
    'empty field' => [['duration_min' => '']],
]);

test('a shortest duration written is saved as written', function () {
    $provider = sdProvider();

    $this->actingAs($provider->user)->post(route('provider.services.store'), sdForm(['duration_min' => 150]))->assertSessionHasNoErrors();

    expect(Service::query()->sole()->only(['duration_min', 'duration_max']))->toBe(['duration_min' => 150, 'duration_max' => 180]);
});

test('a shortest duration longer than the time kept free is still refused, with the message of today', function () {
    $provider = sdProvider();
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);

    $this->actingAs($provider->user)->post(route('provider.services.store'), sdForm(['duration_min' => 200]))
        ->assertSessionHasErrors(['duration_max' => 'La durata massima non può essere minore della minima.']);
    $this->actingAs($provider->user)->put(route('provider.services.update', $service->id), sdForm(['duration_min' => 200]))
        ->assertSessionHasErrors(['duration_max' => 'La durata massima non può essere minore della minima.']);
    // An invalid shortest duration keeps its own message.
    $this->actingAs($provider->user)->post(route('provider.services.store'), sdForm(['duration_min' => 7]))
        ->assertSessionHasErrors(['duration_min' => 'Scrivi la durata in minuti, da 5 a 720, a passi di 5.']);

    expect(Service::query()->count())->toBe(1)
        ->and($service->fresh()->only(['duration_min', 'duration_max']))->toBe(['duration_min' => 60, 'duration_max' => 60]);
});

test('the time kept free is required, when adding and when editing', function () {
    $provider = sdProvider();
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);

    $this->actingAs($provider->user)->post(route('provider.services.store'), sdForm(['duration_max' => null]))
        ->assertSessionHasErrors(['duration_max' => 'Scrivi la durata massima.']);
    $this->actingAs($provider->user)->put(route('provider.services.update', $service->id), sdForm(['duration_max' => '']))
        ->assertSessionHasErrors(['duration_max' => 'Scrivi la durata massima.']);

    expect(Service::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// The rule did not change
// ---------------------------------------------------------------------------------------------

test('the rule is the same: braids of 180 minutes with a 30-minute option at 14:00 keep the provider until 17:30', function () {
    $provider = sdProvider();
    [$service, $option] = sdBraids($provider);

    expect(app(BookingDuration::class)->minutesFor($service, [$option->id]))->toBe(210);

    $slots = sdSlots($provider, $service, $option);
    // Every 30 minutes from 09:00; the last one ends at 19:00 exactly.
    expect(array_column($slots, 'time'))->toBe(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30'])
        ->and(collect($slots)->firstWhere('time', '14:00'))->toBe(['start' => '2026-10-19T12:00:00Z', 'time' => '14:00', 'end_time' => '17:30']);

    $this->post(route('providers.bookings.store', $provider->slug), [
        'service' => $service->id,
        'options' => [$option->id],
        'starts_at' => '2026-10-19T12:00:00Z',
        'customer_name' => 'Cliente Prova',
        'customer_phone' => '+39 000 000 0000',
    ])->assertSessionHasNoErrors();

    $booking = Booking::query()->sole();
    expect($booking->ends_at->toIso8601String())->toBe('2026-10-19T15:30:00+00:00')
        ->and(LocalTime::fromUtc($booking->ends_at)->format('H:i'))->toBe('17:30');

    // Right before it, 10:30 still fits (it ends at 14:00 sharp); from 11:00 to 15:30 nothing does.
    expect(array_column(sdSlots($provider, $service, $option), 'time'))->toBe(['09:00', '09:30', '10:00', '10:30']);
});

test('the shortest duration never changes the free times nor the end', function () {
    $provider = sdProvider();
    [$long] = sdBraids($provider, 120);
    [$short, $shortOption] = sdBraids($provider, 5);
    $longOption = $long->options()->sole();

    expect(sdSlots($provider, $short, $shortOption))->toBe(sdSlots($provider, $long, $longOption));
});

test('the end shown before booking is the end saved, on the confirmation page and in the emails', function () {
    $provider = sdProvider();
    [$service, $option] = sdBraids($provider);
    $slot = collect(sdSlots($provider, $service, $option))->firstWhere('time', '14:00');

    $response = $this->post(route('providers.bookings.store', $provider->slug), [
        'service' => $service->id,
        'options' => [$option->id],
        'starts_at' => $slot['start'],
        'customer_name' => 'Cliente Prova',
        'customer_phone' => '+39 000 000 0000',
    ]);
    $booking = Booking::query()->sole();

    $this->get($response->headers->get('Location'))->assertInertia(fn (Assert $page) => $page
        ->where('booking.local_start', $slot['time'])
        ->where('booking.local_end', $slot['end_time']));
    expect(LocalTime::timeRange($booking->starts_at, $booking->ends_at))->toContain($slot['end_time']);
    // One computation: the slot gives its end with LocalTime, from the end FreeSlots computed.
    expect((string) file_get_contents(app_path('Http/Resources/SlotResource.php')))
        ->toContain("'end_time' => LocalTime::fromUtc(\$this->end)->format('H:i'),");
});

// ---------------------------------------------------------------------------------------------
// The pages
// ---------------------------------------------------------------------------------------------

test('both service forms use the shared fields: the time kept free first and required, the shortest optional', function () {
    $fields = sdSource('js/components/service-duration-fields.tsx');
    preg_match('/<Input[^>]*?name="duration_max"[\s\S]*?\/>/', $fields, $max);
    preg_match('/<Input[^>]*?name="duration_min"[\s\S]*?\/>/', $fields, $min);

    expect(strpos($fields, 'name="duration_max"'))->toBeLessThan(strpos($fields, 'name="duration_min"'))
        ->and($max[0] ?? '')->toMatch('/\brequired\b/')
        ->toContain('aria-describedby="service-duration-max-help service-duration-preview"')
        ->and($min[0] ?? '')->not->toMatch('/\brequired\b/')
        ->toContain('aria-describedby="service-duration-min-help"')
        ->and($fields)
        ->toContain("{t('provider.services.form.duration_max_help')}")
        ->toContain("{t('provider.services.form.duration_min_help')}")
        ->toMatch("/t\\('provider\\.services\\.form\\.duration_preview', \\{\\s*start: clockTime\\(EXAMPLE_START\\),\\s*end: clockTime\\(EXAMPLE_START \\+ minutes\\),\\s*duration: formatMinutes\\(t, minutes\\),/")
        // The example: a booking at 09:00, never past midnight with the 12 hours allowed.
        ->toContain('const EXAMPLE_START = 9 * 60;')
        // The example is plain text, not a live region read at every key.
        ->toContain('<p id="service-duration-preview" className="text-sm">')
        ->not->toContain('aria-live');

    foreach (['js/pages/provider/services/index.tsx', 'js/pages/provider/services/edit.tsx'] as $page) {
        expect(sdSource($page))
            ->toContain("import { ServiceDurationFields } from '@/components/service-duration-fields';")
            ->toContain('<ServiceDurationFields')
            ->not->toContain('name="duration_min"')
            ->not->toContain('name="duration_max"');
    }
    // On the edit page an equal shortest duration is shown empty.
    expect(sdSource('js/pages/provider/services/edit.tsx'))->toMatch('/defaultMin=\{\s*service\.duration_min === service\.duration_max\s*\?\s*undefined\s*:\s*service\.duration_min\s*\}/');
});

test('the example of the form: 09:00 plus the minutes, the same day even with 12 hours', function () {
    expect(sdFormat('[f.clockTime(540), f.clockTime(540 + 180), f.clockTime(540 + 210), f.clockTime(540 + 5), f.clockTime(540 + 720)]'))
        ->toBe(['09:00', '12:00', '12:30', '09:05', '21:00']);
    expect(sdFormat("t('provider.services.form.duration_preview', { start: f.clockTime(540), end: f.clockTime(540 + 210), duration: f.formatMinutes(t, 210) })"))
        ->toBe("Una prenotazione alle 09:00 ti occupa fino alle 12:30 (3\u{00A0}h\u{00A0}30).");
});

test('the customer sees until when the chosen time may last, from the end the server sends', function () {
    $fields = sdSource('js/components/booking-fields.tsx');

    expect($fields)
        ->toContain('const chosen = slots.find((slot) => slot.start === startsAt);')
        ->toMatch("/<p role=\"status\" className=\"text-sm\">\\s*\\{chosen &&\\s*t\\('booking\\.form\\.chosen_time', \\{\\s*start: chosen\\.time,\\s*end: chosen\\.end_time,\\s*\\}\\)\\}\\s*<\\/p>/")
        // The summary: "di solito da ... a ...", the options included.
        ->toMatch('/formatDuration\(\s*t,\s*service\.duration_min \+ extraMinutes,\s*service\.duration_max \+ extraMinutes,\s*\)/');
    expect(sdSource('js/types/provider.ts'))->toMatch('/export type FreeSlot = \{\s*start: string;\s*time: string;\s*end_time: string;\s*\};/');
});

test('no hand-written aria-label in the touched components', function () {
    foreach (['js/components/service-duration-fields.tsx', 'js/components/booking-fields.tsx', 'js/pages/provider/services/index.tsx', 'js/pages/provider/services/edit.tsx', 'js/pages/provider/services/options.tsx'] as $file) {
        expect(sdSource($file))->not->toMatch('/aria-label(?!ledby)/', $file);
    }
});

// ---------------------------------------------------------------------------------------------
// Texts and durations
// ---------------------------------------------------------------------------------------------

test('the new texts in the three languages, with the French spaces, and never the word calendar in the helps', function () {
    $expected = [
        'it' => [
            'provider.services.form.duration_max' => 'Tempo da tenere libero (minuti)',
            'provider.services.form.duration_max_help' => 'È il tempo che Griot ti riserva per questo servizio: in quel periodo nessun altro cliente può prenotare.',
            'provider.services.form.duration_preview' => 'Una prenotazione alle :start ti occupa fino alle :end (:duration).',
            'provider.services.form.duration_min' => 'Può finire prima? Durata minima (facoltativa, minuti)',
            'provider.services.form.duration_min_help' => 'La vede solo il cliente, per sapere più o meno quanto dura. Non cambia il tempo che ti viene riservato.',
            'provider.options.form.duration_help' => 'Quanto allunga il servizio. Questi minuti si aggiungono al tempo che ti viene riservato.',
            'providers.show.duration_range' => 'di solito da :from a :to',
            'booking.form.chosen_time' => 'Dalle :start fino alle :end al massimo.',
        ],
        'fr' => [
            'provider.services.form.duration_max' => 'Temps à garder libre (minutes)',
            'provider.services.form.duration_max_help' => "C'est le temps que Griot vous réserve pour cette prestation\u{00A0}: pendant cette période, aucun autre client ne peut réserver.",
            'provider.services.form.duration_preview' => "Une réservation à :start vous occupe jusqu'à :end (:duration).",
            'provider.services.form.duration_min' => "Peut finir plus tôt\u{00A0}? Durée minimale (facultative, minutes)",
            'provider.services.form.duration_min_help' => 'Seul le client la voit, pour savoir à peu près combien de temps cela dure. Elle ne change pas le temps qui vous est réservé.',
            'provider.options.form.duration_help' => "Le temps qu'elle ajoute à la prestation. Ces minutes s'ajoutent au temps qui vous est réservé.",
            'providers.show.duration_range' => 'en général de :from à :to',
            'booking.form.chosen_time' => "De :start jusqu'à :end au plus tard.",
        ],
        'en' => [
            'provider.services.form.duration_max' => 'Time to keep free (minutes)',
            'provider.services.form.duration_max_help' => 'This is the time Griot keeps for you for this service: during it, no other customer can book.',
            'provider.services.form.duration_preview' => 'A booking at :start keeps you busy until :end (:duration).',
            'provider.services.form.duration_min' => 'Can it finish sooner? Minimum duration (optional, minutes)',
            'provider.services.form.duration_min_help' => 'Only the customer sees it, to know roughly how long it takes. It does not change the time kept for you.',
            'provider.options.form.duration_help' => 'How much longer the service takes. These minutes are added to the time kept for you.',
            'providers.show.duration_range' => 'usually :from to :to',
            'booking.form.chosen_time' => 'From :start until :end at the latest.',
        ],
    ];

    foreach ($expected as $locale => $texts) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

        foreach ($texts as $key => $text) {
            expect($json[$key] ?? null)->toBe($text, "{$locale} {$key}");
            if ($locale === 'fr') {
                expect(preg_match('/ [:?!;](?![a-z_])/', $json[$key]))->toBe(0, "fr {$key}");
            }
        }
        foreach (['provider.services.form.duration_max', 'provider.services.form.duration_max_help', 'provider.services.form.duration_preview', 'provider.services.form.duration_min', 'provider.services.form.duration_min_help', 'provider.options.form.duration_help'] as $key) {
            expect(mb_strtolower($json[$key]))->not->toMatch('/calendari|calendrier|calendar|agenda/', "{$locale} {$key}");
        }
        // The old keys are gone.
        expect($json)->not->toHaveKey('provider.services.form.duration_help')
            ->not->toHaveKey('providers.show.duration_fixed');
    }

    $code = sdSource('js/components/service-duration-fields.tsx').sdSource('js/lib/format.ts').sdSource('js/pages/provider/services/edit.tsx').sdSource('js/pages/provider/services/index.tsx');
    expect($code)->not->toContain('provider.services.form.duration_help')->not->toContain('providers.show.duration_fixed');
});

test('durations are written in hours and minutes, the same way in the three languages', function (string $locale) {
    $nb = "\u{00A0}";

    expect(sdFormat('[210, 180, 45, 5, 720, 65, 60, 90].map((m) => f.formatMinutes(t, m))', $locale))->toBe([
        "3{$nb}h{$nb}30", "3{$nb}h", "45{$nb}min", "5{$nb}min", "12{$nb}h", "1{$nb}h{$nb}05", "1{$nb}h", "1{$nb}h{$nb}30",
    ]);
})->with(['it', 'fr', 'en']);

test('a range says "usually", one duration stands alone, the options add hours and minutes', function () {
    $nb = "\u{00A0}";

    expect(sdFormat('[f.formatDuration(t, 150, 210), f.formatDuration(t, 60, 60), f.formatDuration(t, 30, 45)]'))->toBe([
        "di solito da 2{$nb}h{$nb}30 a 3{$nb}h{$nb}30", "1{$nb}h", "di solito da 30{$nb}min a 45{$nb}min",
    ]);
    expect(sdFormat('f.formatDuration(t, 150, 210)', 'fr'))->toBe("en général de 2{$nb}h{$nb}30 à 3{$nb}h{$nb}30")
        ->and(sdFormat('f.formatDuration(t, 150, 210)', 'en'))->toBe("usually 2{$nb}h{$nb}30 to 3{$nb}h{$nb}30");
    expect(sdFormat("f.formatOptionExtras(t, 'it', { extra_price: '0.00', extra_duration: 90 })"))->toBe(["+ 1{$nb}h{$nb}30"])
        ->and(sdFormat("f.formatOptionExtras(t, 'it', { extra_price: '0.00', extra_duration: 15 })"))->toBe(["+ 15{$nb}min"]);
});
