<?php

use App\Booking\AnonymizeOldBookings;
use App\Booking\BookingTransitions;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

// The retention of the customers' data (issue #56, phase 2). "Now" is Friday 20 November 2026,
// 08:00 UTC; with 6 months the threshold is 20 May 2026, 08:00 UTC: a booking that ended before it
// is made anonymous, one that ends at it or later is never touched.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00', 'UTC'));
    config()->set('booking.anonymize_after_months', 6);
    app()->setLocale('it');
});

const DRT_THRESHOLD = '2026-05-20 08:00 UTC';
const DRT_CONFIRM = 'Rendere anonime :count prenotazioni finite prima del 2026-05-20 08:00 UTC?';

/**
 * A booking with recognizable customer's data, ending at the given UTC instant ("Y-m-d H:i:s"),
 * one hour long.
 *
 * @param  array<string, mixed>  $override
 */
function drtBooking(string $endsAtUtc, BookingStatus $status = BookingStatus::Confirmed, array $override = [], ?Provider $provider = null): Booking
{
    $provider ??= Provider::factory()->create(['name' => 'Salone Awa', 'is_published' => true]);
    $end = CarbonImmutable::parse($endsAtUtc, 'UTC');
    $service = Service::factory()->for($provider)->create(['name' => 'Treccine']);

    return Booking::factory()->for($provider)->for($service)->create([
        'status' => $status,
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+393331234567',
        'customer_email' => 'awa@example.com',
        'notes' => 'NOTA PRIVATA DEL CLIENTE',
        'cancellation_reason' => $status === BookingStatus::Cancelled ? 'MOTIVO PRIVATO' : null,
        'starts_at' => $end->subHour(),
        'ends_at' => $end,
        'created_at' => $end->subDays(10),
        'updated_at' => $end->subDays(10),
        ...$override,
    ]);
}

/**
 * Every row of the bookings table, as stored.
 *
 * @return list<array<string, mixed>>
 */
function drtRows(): array
{
    return DB::table('bookings')->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all();
}

/**
 * Run the command with --force and check its summary line.
 */
function drtRun(int $anonymized, int $skipped = 0): void
{
    test()->artisan('bookings:anonymize-old', ['--force' => true])
        ->expectsOutput("{$anonymized} prenotazioni rese anonime, {$skipped} saltate (finite prima del ".DRT_THRESHOLD.').')
        ->assertSuccessful();
}

/**
 * The output of the command, as a string.
 *
 * @param  array<string, mixed>  $options
 */
function drtOutput(array $options): string
{
    Artisan::call('bookings:anonymize-old', $options);

    return Artisan::output();
}

// ---------------------------------------------------------------------------------------------
// Which bookings
// ---------------------------------------------------------------------------------------------

test('a booking is made anonymous a second after the threshold, and not at it or a second before', function () {
    $older = drtBooking('2026-05-20 07:59:59');
    $atThreshold = drtBooking('2026-05-20 08:00:00');
    $newer = drtBooking('2026-05-20 08:00:01');

    drtRun(1);

    expect($older->fresh()->isAnonymized())->toBeTrue()
        ->and($atThreshold->fresh()->isAnonymized())->toBeFalse()
        ->and($newer->fresh()->isAnonymized())->toBeFalse()
        ->and($atThreshold->fresh()->customer_name)->toBe('Awa Diop');
});

test('the bookings in the future or more recent than the threshold are never touched', function (string $endsAt, BookingStatus $status) {
    drtBooking($endsAt, $status);
    $before = drtRows();

    $this->artisan('bookings:anonymize-old', ['--force' => true])
        ->expectsOutput('Nessuna prenotazione finita prima del '.DRT_THRESHOLD.' da rendere anonima.')
        ->assertSuccessful();

    expect(drtRows())->toBe($before);
})->with([
    'pending in the future' => ['2026-11-25 10:00:00', BookingStatus::Pending],
    'confirmed in the future' => ['2026-11-25 10:00:00', BookingStatus::Confirmed],
    'confirmed, started and not ended yet' => ['2026-11-20 08:30:00', BookingStatus::Confirmed],
    'confirmed, ended last month' => ['2026-10-20 10:00:00', BookingStatus::Confirmed],
    'cancelled in the future' => ['2026-11-25 10:00:00', BookingStatus::Cancelled],
    'cancelled recently' => ['2026-09-01 10:00:00', BookingStatus::Cancelled],
]);

test('an old booking is made anonymous in any status: confirmed and over, cancelled, pending never answered', function (BookingStatus $status) {
    $booking = drtBooking('2026-03-01 10:00:00', $status);

    drtRun(1);

    expect($booking->fresh()->isAnonymized())->toBeTrue()
        ->and($booking->fresh()->status)->toBe($status);
})->with([
    'confirmed and over' => BookingStatus::Confirmed,
    'cancelled' => BookingStatus::Cancelled,
    'pending, never answered' => BookingStatus::Pending,
]);

test('a booking written by the provider is made anonymous too, and stays the provider\'s', function () {
    $booking = drtBooking('2026-03-01 10:00:00', override: ['created_by_provider' => true, 'customer_email' => null]);

    drtRun(1);

    $fresh = $booking->fresh();
    expect($fresh->isAnonymized())->toBeTrue()
        ->and($fresh->created_by_provider)->toBeTrue()
        ->and($fresh->customer_name)->toBeNull()
        ->and($fresh->customer_phone)->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// What changes and what stays
// ---------------------------------------------------------------------------------------------

test('only the customer\'s data, the token and anonymized_at change: updated_at and the rest stay', function () {
    $booking = drtBooking('2026-03-01 10:00:00', BookingStatus::Cancelled, [
        'cancelled_by' => 'provider_cancel',
        'locale' => 'fr',
        'reminder_sent_at' => CarbonImmutable::parse('2026-02-20 10:00', 'UTC'),
    ]);
    $before = (array) DB::table('bookings')->where('id', $booking->id)->first();

    drtRun(1);

    $after = (array) DB::table('bookings')->where('id', $booking->id)->first();
    $changed = ['customer_name', 'customer_phone', 'customer_email', 'notes', 'cancellation_reason', 'public_token', 'anonymized_at'];
    expect(array_diff_key($after, array_flip($changed)))->toBe(array_diff_key($before, array_flip($changed)))
        ->and($after['updated_at'])->toBe($before['updated_at'])
        ->and($after['cancelled_by'])->toBe('provider_cancel')
        ->and($after['locale'])->toBe('fr');
    expect([$after['customer_name'], $after['customer_phone'], $after['customer_email'], $after['notes'], $after['cancellation_reason']])
        ->toBe([null, null, null, null, null])
        ->and($after['anonymized_at'])->toBe('2026-11-20 08:00:00');
});

test('the options of the booking stay', function () {
    $booking = drtBooking('2026-03-01 10:00:00');
    attachBookingOptions($booking, ServiceOption::factory()->count(2)->for($booking->service)->create()->modelKeys());
    // The pivot has no id: its key is the pair (booking_id, service_option_id).
    $pivot = DB::table('booking_service_option')->orderBy('booking_id')->orderBy('service_option_id')->get()->all();

    drtRun(1);

    expect($pivot)->toHaveCount(2)
        ->and(DB::table('booking_service_option')->orderBy('booking_id')->orderBy('service_option_id')->get()->all())->toEqual($pivot)
        ->and($booking->fresh()->options)->toHaveCount(2);
});

test('the token is new: the old private link gives 404, the new one is different and valid', function () {
    $booking = drtBooking('2026-03-01 10:00:00');
    $old = $booking->public_token;

    drtRun(1);

    $new = $booking->fresh()->public_token;
    expect($new)->not->toBe($old)
        ->toMatch('/^[A-Za-z0-9]{48}$/');
    $this->get(route('bookings.show', ['booking' => $old]))->assertNotFound();
    // Nobody has the new link: it exists only so that the column stays unique and not null.
    $this->get(route('bookings.show', ['booking' => $new]))->assertOk();
});

test('running the command again changes nothing, updated_at included', function () {
    drtBooking('2026-03-01 10:00:00');
    drtBooking('2026-04-01 10:00:00', BookingStatus::Pending);

    drtRun(2);
    $after = drtRows();

    $this->travel(1)->day();
    $this->artisan('bookings:anonymize-old', ['--force' => true])->assertSuccessful();

    expect(drtRows())->toBe($after);
});

test('anonymized_at is not fillable', function () {
    $booking = new Booking(['anonymized_at' => CarbonImmutable::now()]);

    expect($booking->isFillable('anonymized_at'))->toBeFalse()
        ->and($booking->anonymized_at)->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// The command: dry run, confirmation, output
// ---------------------------------------------------------------------------------------------

test('--dry-run counts and changes nothing', function () {
    drtBooking('2026-03-01 10:00:00');
    drtBooking('2026-04-01 10:00:00', BookingStatus::Cancelled);
    drtBooking('2026-11-25 10:00:00');
    $before = drtRows();

    $this->artisan('bookings:anonymize-old', ['--dry-run' => true])
        ->expectsOutput('Prova a vuoto: 2 prenotazioni finite prima del '.DRT_THRESHOLD.' sarebbero rese anonime. Nessuna modifica.')
        ->assertSuccessful();

    expect(drtRows())->toBe($before);
});

test('without --force the command asks first, and a "no" changes nothing', function () {
    drtBooking('2026-03-01 10:00:00');
    $before = drtRows();

    $this->artisan('bookings:anonymize-old')
        ->expectsConfirmation(str_replace(':count', '1', DRT_CONFIRM), 'no')
        ->expectsOutput('Nessuna modifica.')
        ->assertSuccessful();

    expect(drtRows())->toBe($before);
});

test('without --force a "yes" makes the bookings anonymous', function () {
    $booking = drtBooking('2026-03-01 10:00:00');

    $this->artisan('bookings:anonymize-old')
        ->expectsConfirmation(str_replace(':count', '1', DRT_CONFIRM), 'yes')
        ->expectsOutput('1 prenotazioni rese anonime, 0 saltate (finite prima del '.DRT_THRESHOLD.').')
        ->assertSuccessful();

    expect($booking->fresh()->isAnonymized())->toBeTrue();
});

test('without a terminal and without --force nothing changes: the schedule must pass --force', function () {
    drtBooking('2026-03-01 10:00:00');
    $before = drtRows();

    // Artisan::call() and not artisan(): the mocked output of the tests answers every question.
    expect(Artisan::call('bookings:anonymize-old', ['--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Nessuna modifica.');

    expect(drtRows())->toBe($before);
});

test('the output of the command is in the language of the application', function (string $locale, string $dryRun, string $summary) {
    drtBooking('2026-03-01 10:00:00');
    app()->setLocale($locale);

    $this->artisan('bookings:anonymize-old', ['--dry-run' => true])->expectsOutput($dryRun)->assertSuccessful();
    $this->artisan('bookings:anonymize-old', ['--force' => true])->expectsOutput($summary)->assertSuccessful();
})->with([
    'Italian' => ['it', 'Prova a vuoto: 1 prenotazioni finite prima del 2026-05-20 08:00 UTC sarebbero rese anonime. Nessuna modifica.', '1 prenotazioni rese anonime, 0 saltate (finite prima del 2026-05-20 08:00 UTC).'],
    'French' => ['fr', "Essai à blanc\u{00A0}: 1 réservations terminées avant le 2026-05-20 08:00 UTC seraient rendues anonymes. Aucune modification.", '1 réservations rendues anonymes, 0 ignorées (terminées avant le 2026-05-20 08:00 UTC).'],
    'English' => ['en', 'Dry run: 1 bookings that ended before 2026-05-20 08:00 UTC would be made anonymous. Nothing changed.', '1 bookings made anonymous, 0 skipped (ended before 2026-05-20 08:00 UTC).'],
]);

test('the questions and answers of the command are translated in every language', function () {
    expect(__('booking.retention.confirm', ['count' => 2, 'before' => DRT_THRESHOLD], 'fr'))->toBe("Rendre anonymes 2 réservations terminées avant le 2026-05-20 08:00 UTC\u{00A0}?")
        ->and(__('booking.retention.confirm', ['count' => 2, 'before' => DRT_THRESHOLD], 'en'))->toBe('Make 2 bookings that ended before 2026-05-20 08:00 UTC anonymous?')
        ->and(__('booking.retention.aborted', [], 'fr'))->toBe('Aucune modification.')
        ->and(__('booking.retention.aborted', [], 'en'))->toBe('Nothing changed.');
});

test('the output never shows a name, a phone or anything else of a customer', function () {
    drtBooking('2026-03-01 10:00:00', BookingStatus::Cancelled);
    $private = ['Awa Diop', '+393331234567', '3331234567', 'awa@example.com', 'NOTA PRIVATA', 'MOTIVO PRIVATO'];
    $token = Booking::query()->value('public_token');

    $output = drtOutput(['--dry-run' => true]).drtOutput(['--force' => true]).drtOutput(['--force' => true]);

    foreach ([...$private, $token] as $text) {
        expect(str_contains($output, (string) $text))->toBeFalse("A customer's datum in the output: {$text}");
    }
});

// ---------------------------------------------------------------------------------------------
// Threshold, lock and schedule
// ---------------------------------------------------------------------------------------------

test('the threshold is the months of the config before now, without overflow', function () {
    expect(app(AnonymizeOldBookings::class)->threshold()->toIso8601ZuluString())->toBe('2026-05-20T08:00:00Z');

    // 31 August minus six months is the end of February, never 3 March.
    $this->travelTo(CarbonImmutable::parse('2026-08-31 12:00', 'UTC'));
    expect(app(AnonymizeOldBookings::class)->threshold()->toIso8601ZuluString())->toBe('2026-02-28T12:00:00Z');

    config()->set('booking.anonymize_after_months', 12);
    expect(app(AnonymizeOldBookings::class)->threshold()->toIso8601ZuluString())->toBe('2025-08-31T12:00:00Z');
});

test('a booking made anonymous by another run after the ids were read is skipped', function () {
    $booking = drtBooking('2026-03-01 10:00:00');
    $done = false;
    DB::listen(function (QueryExecuted $query) use ($booking, &$done) {
        if (! $done && str_starts_with($query->sql, 'select "id" from "bookings"')) {
            $done = true;
            DB::table('bookings')->where('id', $booking->id)->update(['anonymized_at' => '2026-11-20 07:59:00', 'customer_name' => null]);
        }
    });

    drtRun(0, 1);

    expect(DB::table('bookings')->where('id', $booking->id)->value('anonymized_at'))->toBe('2026-11-20 07:59:00');
});

test('the rule is written once, in the scope, and the command reads it there', function () {
    $source = (string) file_get_contents(app_path('Booking/AnonymizeOldBookings.php'));

    expect(substr_count($source, '->dueForAnonymization($before)'))->toBe(3)
        ->and($source)->not->toContain("'ends_at'")
        ->and($source)->toContain('lockForUpdate()');
});

test('the command is scheduled every day, with --force, without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, 'bookings:anonymize-old'))
        ->values();

    expect($events)->toHaveCount(1);
    expect($events[0]->expression)->toBe('0 0 * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and((string) $events[0]->command)->toContain('--force');
});

// ---------------------------------------------------------------------------------------------
// The provider's list
// ---------------------------------------------------------------------------------------------

test('the provider\'s list shows an anonymous customer: no name, phone, email, notes or WhatsApp link', function () {
    // Pending and never answered: the list shows it without filters.
    $booking = drtBooking('2026-03-01 10:00:00', BookingStatus::Pending);
    drtRun(1);

    $props = [];
    $this->actingAs($booking->provider->user)->get(route('provider.bookings.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $page->component('provider/bookings/index');
            $props = $page->toArray()['props'];
        })
        ->assertDontSee('Awa Diop')
        ->assertDontSee('+393331234567');

    expect($props['bookings']['data'])->toHaveCount(1);
    expect($props['bookings']['data'][0])->toMatchArray([
        'customer_name' => null,
        'customer_phone' => null,
        'customer_email' => null,
        'notes' => null,
        'whatsapp_url' => null,
        'can_confirm' => false,
        'can_reject' => false,
        'can_cancel' => false,
        'anonymized' => true,
    ]);
});

test('a cancelled anonymous booking found with the filters has no WhatsApp link either', function () {
    $booking = drtBooking('2026-03-01 10:00:00', BookingStatus::Cancelled, ['cancelled_by' => 'provider_cancel']);
    drtRun(1);

    $props = [];
    $this->actingAs($booking->provider->user)->get(route('provider.bookings.index', ['from' => '2026-03-01', 'to' => '2026-03-01']))
        ->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    expect($props['bookings']['data'][0])->toMatchArray(['outcome' => 'cancelled', 'whatsapp_url' => null, 'anonymized' => true]);
});

test('the provider cannot reject, cancel or confirm an anonymous booking, and nothing is written', function (BookingStatus $status, string $action) {
    $booking = drtBooking('2026-03-01 10:00:00', $status);
    drtRun(1);
    $before = drtRows();

    $this->actingAs($booking->provider->user)
        ->from(route('provider.bookings.index'))
        ->post(route("provider.bookings.{$action}", $booking), ['reason' => 'UN NUOVO MOTIVO'])
        ->assertRedirect(route('provider.bookings.index'))
        ->assertSessionHasErrors(['booking' => 'I dati del cliente di questa prenotazione sono stati resi anonimi: non si può più cambiare.']);

    expect(drtRows())->toBe($before);
})->with([
    'reject a pending one' => [BookingStatus::Pending, 'reject'],
    'cancel a confirmed one' => [BookingStatus::Confirmed, 'cancel'],
]);

test('the rules refuse every change of the provider on an anonymous booking, before the other reasons but a cancellation', function () {
    $rules = app(BookingTransitions::class);
    $pending = drtBooking('2026-03-01 10:00:00', BookingStatus::Pending);
    $confirmed = drtBooking('2026-03-01 10:00:00');
    $cancelled = drtBooking('2026-03-01 10:00:00', BookingStatus::Cancelled);
    drtRun(3);

    expect($rules->confirmRefusal($pending->fresh()))->toBe('anonymized')
        ->and($rules->rejectRefusal($pending->fresh()))->toBe('anonymized')
        ->and($rules->providerCancelRefusal($confirmed->fresh()))->toBe('anonymized')
        ->and($rules->providerCancelRefusal($cancelled->fresh()))->toBe('already_cancelled');
    expect(__('provider.bookings.transition.anonymized', [], 'fr'))->toBe("Les données du client de cette réservation ont été rendues anonymes\u{00A0}: elle ne peut plus être modifiée.")
        ->and(__('provider.bookings.transition.anonymized', [], 'en'))->toBe("The customer's details of this booking were made anonymous: it can no longer be changed.");
});

test('the page shows the translated label, and no phone or contact line for an anonymous booking', function () {
    $source = (string) file_get_contents(resource_path('js/pages/provider/bookings/index.tsx'));

    expect($source)
        ->toMatch("/\{booking\.anonymized \? \(\s*<p[^>]*>\s*\{t\('provider\.bookings\.anonymous_customer'\)\}/")
        ->toMatch('/\{booking\.customer_phone !== null && \(/')
        ->toMatch("/\{!booking\.anonymized &&\s*booking\.outcome !== null &&\s*booking\.whatsapp_url === null &&\s*booking\.customer_email === null && \(/");
    expect(__('provider.bookings.anonymous_customer', [], 'it'))->toBe('Cliente anonimo')
        ->and(__('provider.bookings.anonymous_customer', [], 'fr'))->toBe('Client anonyme')
        ->and(__('provider.bookings.anonymous_customer', [], 'en'))->toBe('Anonymous customer');
});

// ---------------------------------------------------------------------------------------------
// Migration
// ---------------------------------------------------------------------------------------------

test('the migration adds the column and its index, and its rollback works with anonymous rows', function () {
    $migration = require database_path('migrations/2026_11_02_100000_add_anonymized_at_to_bookings_table.php');
    $anonymous = drtBooking('2026-03-01 10:00:00');
    $kept = drtBooking('2026-11-25 10:00:00');
    drtRun(1);

    expect(Schema::hasColumn('bookings', 'anonymized_at'))->toBeTrue()
        ->and(Schema::hasIndex('bookings', 'bookings_anonymize_index'))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('bookings', 'anonymized_at'))->toBeFalse()
        ->and(Schema::hasIndex('bookings', 'bookings_anonymize_index'))->toBeFalse();
    // The anonymous row stays, with an empty name and phone; the other one as it was.
    expect((array) DB::table('bookings')->where('id', $anonymous->id)->first(['customer_name', 'customer_phone']))
        ->toBe(['customer_name' => '', 'customer_phone' => ''])
        ->and(DB::table('bookings')->where('id', $kept->id)->value('customer_name'))->toBe('Awa Diop');
    $columns = collect(Schema::getColumns('bookings'))->keyBy('name');
    expect($columns['customer_name']['nullable'])->toBeFalse()
        ->and($columns['customer_phone']['nullable'])->toBeFalse();

    $migration->up();

    $columns = collect(Schema::getColumns('bookings'))->keyBy('name');
    expect($columns['customer_name']['nullable'])->toBeTrue()
        ->and($columns['customer_phone']['nullable'])->toBeTrue()
        ->and(Schema::hasIndex('bookings', 'bookings_anonymize_index'))->toBeTrue()
        ->and(DB::table('bookings')->whereNotNull('anonymized_at')->count())->toBe(0);
});
