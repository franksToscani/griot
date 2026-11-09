<?php

use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceOption;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The migration of the saved prices (issue #97): the bookings that exist already get the CURRENT
 * prices of their service and options, an approximation documented in docs/bookings.md.
 */

function spmMigration(): object
{
    return require database_path('migrations/2026_11_09_100000_add_saved_prices_to_bookings.php');
}

test('down removes the two columns, up fills the existing bookings with the current prices in cents', function () {
    $service = Service::factory()->create(['price' => '25.50']);
    $other = Service::factory()->for($service->provider)->create(['price' => '90.00']);
    $beads = ServiceOption::factory()->for($service)->create(['extra_price' => '7.50']);
    $free = ServiceOption::factory()->for($service)->create(['extra_price' => '0.00']);
    $cheap = ServiceOption::factory()->for($service)->create(['extra_price' => '0.29']);

    $migration = spmMigration();
    $migration->down();
    expect(Schema::hasColumn('bookings', 'service_price_cents'))->toBeFalse()
        ->and(Schema::hasColumn('booking_service_option', 'extra_price_cents'))->toBeFalse();

    // Rows as they were before the columns: the query builder, the models know the columns.
    $insert = fn (Service $for) => DB::table('bookings')->insertGetId([
        'provider_id' => $for->provider_id,
        'service_id' => $for->id,
        'customer_name' => 'Cliente',
        'customer_phone' => '+39 000 000 0000',
        'starts_at' => '2026-10-29 08:00:00',
        'ends_at' => '2026-10-29 09:00:00',
        'status' => 'pending',
        'public_token' => str_repeat('a', 47).$for->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $withOptions = $insert($service);
    $withoutOptions = $insert($other);
    DB::table('booking_service_option')->insert([
        ['booking_id' => $withOptions, 'service_option_id' => $beads->id],
        ['booking_id' => $withOptions, 'service_option_id' => $free->id],
        ['booking_id' => $withOptions, 'service_option_id' => $cheap->id],
    ]);

    $migration->up();

    expect(DB::table('bookings')->orderBy('id')->pluck('service_price_cents', 'id')->map(fn (mixed $cents) => (int) $cents)->all())
        ->toBe([$withOptions => 2550, $withoutOptions => 9000])
        ->and(DB::table('booking_service_option')->orderBy('service_option_id')->pluck('extra_price_cents', 'service_option_id')->map(fn (mixed $cents) => (int) $cents)->all())
        ->toBe([$beads->id => 750, $free->id => 0, $cheap->id => 29])
        // 25.50 + 7.50 + 0.00 + 0.29.
        ->and(Booking::query()->with('options')->findOrFail($withOptions)->totalCents())->toBe(3329)
        // The catalog itself is untouched.
        ->and(Service::query()->findOrFail($service->id)->price)->toBe('25.50');
});

test('the backfill reads more than one block of bookings', function () {
    $service = Service::factory()->create(['price' => '12.34']);
    Booking::factory()->count(201)->for($service->provider)->for($service)->create();

    $migration = spmMigration();
    $migration->down();
    $migration->up();

    expect(DB::table('bookings')->where('service_price_cents', 1234)->count())->toBe(201);
});

test('after the migration both prices are required', function () {
    $booking = Booking::factory()->create();
    $option = ServiceOption::factory()->for($booking->service)->create();

    // Each in its own transaction (a savepoint): on PostgreSQL a failed statement spoils the test's
    // transaction (docs/ci.md).
    expect(fn () => DB::transaction(fn () => DB::table('bookings')->where('id', $booking->id)->update(['service_price_cents' => null])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('booking_service_option')->insert(['booking_id' => $booking->id, 'service_option_id' => $option->id])))
        ->toThrow(QueryException::class);
});

test('down and up keep the options chosen, though SQLite rebuilds the bookings table', function () {
    $booking = Booking::factory()->create();
    $options = ServiceOption::factory()->count(2)->for($booking->service)->create(['extra_price' => '3.00']);
    attachBookingOptions($booking, $options);
    $links = fn () => DB::table('booking_service_option')->orderBy('service_option_id')->pluck('service_option_id')->all();

    $migration = spmMigration();
    $migration->down();
    expect($links())->toBe($options->modelKeys());

    $migration->up();
    expect($links())->toBe($options->modelKeys())
        ->and(DB::table('booking_service_option')->pluck('extra_price_cents')->map(fn (mixed $cents) => (int) $cents)->all())->toBe([300, 300]);
});
