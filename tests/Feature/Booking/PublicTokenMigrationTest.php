<?php

use App\Models\Booking;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the migration fills the existing bookings with distinct tokens', function () {
    $migration = require database_path('migrations/2026_10_18_101140_add_public_token_to_bookings_table.php');
    $existing = Booking::factory()->count(3)->create();

    // Back to the schema before the migration, with bookings already in the table.
    $migration->down();
    expect(Schema::hasColumn('bookings', 'public_token'))->toBeFalse();

    $migration->up();

    $tokens = DB::table('bookings')->orderBy('id')->pluck('public_token');
    expect($tokens)->toHaveCount(3);
    expect($tokens->filter(fn ($token) => is_string($token) && strlen($token) === 48))->toHaveCount(3);
    expect($tokens->unique())->toHaveCount(3);
    // The bookings themselves are untouched.
    expect(DB::table('bookings')->orderBy('id')->pluck('id')->all())->toBe($existing->pluck('id')->all());
});

test('after the migration the token is required and unique', function () {
    $booking = Booking::factory()->create();
    $other = Booking::factory()->create();

    expect(fn () => DB::table('bookings')->where('id', $other->id)->update(['public_token' => null]))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('bookings')->where('id', $other->id)->update(['public_token' => $booking->public_token]))
        ->toThrow(UniqueConstraintViolationException::class);
});
