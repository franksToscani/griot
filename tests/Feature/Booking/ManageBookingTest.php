<?php

use App\Booking\ManageBooking;
use App\Booking\TransitionNotAllowed;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// "Now" is 2026-10-27 08:00 UTC; the reference booking starts on 2026-10-29 at 08:00 UTC.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
});

/**
 * @param  array<string, mixed>  $override
 */
function mbkBooking(BookingStatus $status = BookingStatus::Pending, array $override = []): Booking
{
    return Booking::factory()->create([
        'status' => $status,
        'starts_at' => CarbonImmutable::parse('2026-10-29 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-29 09:00', 'UTC'),
        'customer_name' => 'Awa Diop',
        ...$override,
    ]);
}

function mbkManager(): ManageBooking
{
    return app(ManageBooking::class);
}

/**
 * The columns that must not change when a change is refused.
 *
 * @return array<string, mixed>
 */
function mbkColumns(Booking $booking): array
{
    return (array) DB::table('bookings')->where('id', $booking->id)->first(['status', 'cancellation_reason', 'updated_at']);
}

/**
 * The reason of the refusal of $change, or null if it went through.
 */
function mbkRefusal(Closure $change): ?string
{
    try {
        $change();

        return null;
    } catch (TransitionNotAllowed $exception) {
        return $exception->reason();
    }
}

test('status is not fillable: fill() never sets it', function () {
    $booking = new Booking;
    $booking->fill(['status' => 'confirmed', 'customer_name' => 'Awa Diop']);

    expect($booking->isFillable('status'))->toBeFalse();
    expect($booking->isFillable('cancellation_reason'))->toBeFalse();
    expect($booking->customer_name)->toBe('Awa Diop');
    // The default of the model, not the value sent.
    expect($booking->status)->toBe(BookingStatus::Pending);
});

test('confirm makes a pending booking confirmed and does not touch the reason', function () {
    $booking = mbkBooking();
    // Never written by confirm: an old value stays as it is.
    DB::table('bookings')->where('id', $booking->id)->update(['cancellation_reason' => 'vecchio']);

    $confirmed = mbkManager()->confirm($booking);

    expect($confirmed->status)->toBe(BookingStatus::Confirmed);
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    expect($booking->fresh()->cancellation_reason)->toBe('vecchio');
});

test('reject and cancel save the reason trimmed, and no reason as null', function (string $method, BookingStatus $from, ?string $reason, ?string $saved) {
    $booking = mbkBooking($from);

    mbkManager()->{$method}($booking, $reason);

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($booking->fresh()->cancellation_reason)->toBe($saved);
})->with([
    'reject with a reason' => ['reject', BookingStatus::Pending, '  Sono malata, mi dispiace.  ', 'Sono malata, mi dispiace.'],
    'reject without' => ['reject', BookingStatus::Pending, null, null],
    'reject with spaces only' => ['reject', BookingStatus::Pending, "   \n ", null],
    'cancel with a reason' => ['cancel', BookingStatus::Confirmed, 'Chiuso per lutto', 'Chiuso per lutto'],
    'cancel with an empty one' => ['cancel', BookingStatus::Confirmed, '', null],
    'HTML and Markdown stay text' => ['reject', BookingStatus::Pending, '<b>no</b> **mai** [qui](https://example.com)', '<b>no</b> **mai** [qui](https://example.com)'],
]);

test('a refused change throws its reason and writes nothing', function (string $method, BookingStatus $status, string $start, string $reason) {
    $booking = mbkBooking($status, ['starts_at' => CarbonImmutable::parse($start, 'UTC')]);
    $before = mbkColumns($booking);

    $args = $method === 'confirm' ? [$booking] : [$booking, 'Un motivo'];
    expect(mbkRefusal(fn () => mbkManager()->{$method}(...$args)))->toBe($reason);
    expect(mbkColumns($booking))->toBe($before);
})->with([
    'confirm a past pending' => ['confirm', BookingStatus::Pending, '2026-10-27 08:00', 'already_started'],
    'confirm a confirmed' => ['confirm', BookingStatus::Confirmed, '2026-10-29 08:00', 'not_pending'],
    'confirm a cancelled' => ['confirm', BookingStatus::Cancelled, '2026-10-29 08:00', 'already_cancelled'],
    'reject a confirmed' => ['reject', BookingStatus::Confirmed, '2026-10-29 08:00', 'not_pending'],
    'reject a cancelled' => ['reject', BookingStatus::Cancelled, '2026-10-29 08:00', 'already_cancelled'],
    'cancel a pending' => ['cancel', BookingStatus::Pending, '2026-10-29 08:00', 'not_confirmed'],
    'cancel a cancelled' => ['cancel', BookingStatus::Cancelled, '2026-10-29 08:00', 'already_cancelled'],
]);

test('no idempotency: a second reject is refused as already cancelled, the first reason stays', function () {
    $booking = mbkBooking();

    mbkManager()->reject($booking, 'Primo');

    expect(mbkRefusal(fn () => mbkManager()->reject($booking, 'Secondo')))->toBe('already_cancelled');
    expect($booking->fresh()->cancellation_reason)->toBe('Primo');
});

test('the row already changed: the decision is taken on the row read again, not on the old model', function () {
    $booking = mbkBooking();
    // Cancelled by the customer in the meantime: the model in memory is still pending.
    DB::table('bookings')->where('id', $booking->id)->update(['status' => BookingStatus::Cancelled->value]);
    expect($booking->status)->toBe(BookingStatus::Pending);

    expect(mbkRefusal(fn () => mbkManager()->confirm($booking)))->toBe('already_cancelled');
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

test('the row already deleted: the locked read finds nothing', function () {
    $booking = mbkBooking();
    DB::table('bookings')->where('id', $booking->id)->delete();

    expect(fn () => mbkManager()->confirm($booking))->toThrow(ModelNotFoundException::class);
});

test('documents a limit: a change in the same transaction does not go through the lock', function () {
    // NOT a proof of the protection. ManageBooking reads the row again with lockForUpdate as its
    // first statement, which makes the provider and the customer (CancelBooking locks the same row)
    // wait for each other on MySQL. This listener cancels the booking with the query builder inside
    // the same transaction and connection, after the decision and before the save, so it never
    // meets the lock; SQLite, used by the tests, ignores lockForUpdate anyway. The save then
    // overwrites the customer's cancellation: the booking ends confirmed.
    $booking = mbkBooking();
    $changed = false;

    Booking::saving(function (Booking $saving) use (&$changed) {
        if (! $changed) {
            $changed = true;
            DB::table('bookings')->where('id', $saving->id)->update(['status' => BookingStatus::Cancelled->value]);
        }
    });

    try {
        mbkManager()->confirm($booking);
    } finally {
        // Booking has no listeners of its own: this removes only the one above.
        Booking::flushEventListeners();
    }

    expect($changed)->toBeTrue();
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

test('the listener of the test above is not registered any more', function () {
    expect(Event::hasListeners('eloquent.saving: '.Booking::class))->toBeFalse();
});

test('the exception has a technical message without the token or personal data', function () {
    $booking = mbkBooking(BookingStatus::Cancelled);

    try {
        mbkManager()->confirm($booking);
        $this->fail('TransitionNotAllowed was not thrown.');
    } catch (TransitionNotAllowed $exception) {
        expect($exception->getMessage())->toBe("Booking {$booking->id} (cancelled) cannot be changed by \"confirm\": already_cancelled.");
        expect($exception->getMessage())->not->toContain($booking->public_token)->not->toContain('Awa Diop');
        expect($exception)->toBeInstanceOf(RuntimeException::class);
    }
});
