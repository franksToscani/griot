<?php

use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Profile\CannotDeleteOption;
use App\Profile\ManageOption;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function mopOption(): ServiceOption
{
    $service = Service::factory()->for(Provider::factory())->create();

    return ServiceOption::factory()->for($service)->create(['name' => 'Perline']);
}

function mopManager(): ManageOption
{
    return app(ManageOption::class);
}

/**
 * Link the option to a new booking of its service, with the query builder.
 */
function mopBook(ServiceOption $option, string $status = 'pending'): void
{
    $service = $option->service()->firstOrFail();
    $booking = Booking::factory()->for($service->provider)->for($service)->create(['status' => $status]);
    DB::table('booking_service_option')->insert(['booking_id' => $booking->id, 'service_option_id' => $option->id]);
}

/**
 * Run $run with a "deleting" listener on ServiceOption, then remove it in any case. ServiceOption
 * has no listeners of its own, so flushEventListeners() removes only this one.
 */
function mopWhileDeleting(Closure $listener, Closure $run): mixed
{
    ServiceOption::deleting($listener);

    try {
        return $run();
    } finally {
        ServiceOption::flushEventListeners();
    }
}

test('an option never booked is deleted, its service does not change', function () {
    $option = mopOption();
    $service = $option->service()->firstOrFail();
    $before = $service->fresh()->toArray();

    expect(mopManager()->blockedBy($option))->toBeNull();
    mopManager()->delete($option);

    expect(ServiceOption::query()->whereKey($option->id)->exists())->toBeFalse();
    expect($service->fresh()->toArray())->toBe($before);
});

test('an option chosen in a booking in any status is not deleted', function (string $status) {
    $option = mopOption();
    mopBook($option, $status);

    expect(mopManager()->blockedBy($option))->toBe('bookings');

    try {
        mopManager()->delete($option);
        $this->fail('CannotDeleteOption was not thrown.');
    } catch (CannotDeleteOption $exception) {
        expect($exception->reason())->toBe('bookings');
    }

    expect(ServiceOption::query()->whereKey($option->id)->exists())->toBeTrue();
})->with(['pending', 'confirmed', 'cancelled']);

test('a booking choosing the option between the check and the delete is caught by the foreign key', function () {
    $option = mopOption();
    $linked = false;

    mopWhileDeleting(function (ServiceOption $deleting) use (&$linked) {
        // Only the first time: a booking chooses the option just before the row is deleted.
        if (! $linked) {
            $linked = true;
            mopBook($deleting);
        }
    }, function () use ($option) {
        try {
            mopManager()->delete($option);
            $this->fail('CannotDeleteOption was not thrown.');
        } catch (CannotDeleteOption $exception) {
            expect($exception->reason())->toBe('bookings');
        }
    });

    expect($linked)->toBeTrue();
    expect(ServiceOption::query()->whereKey($option->id)->exists())->toBeTrue();
});

test('the deleting listener above is not registered any more', function () {
    expect(Event::hasListeners('eloquent.deleting: '.ServiceOption::class))->toBeFalse();
});

test('an old model, while the database has the option in a booking: not deleted', function () {
    $option = mopOption();
    mopBook($option);

    // The link is always read from the database, so this blocks with or without the locked read:
    // the next test is the one that depends on it.
    expect(fn () => mopManager()->delete($option))->toThrow(CannotDeleteOption::class);
    expect(ServiceOption::query()->whereKey($option->id)->exists())->toBeTrue();
});

test('an old model whose row was already deleted: the locked read finds nothing', function () {
    $option = mopOption();
    // Deleted by another request: the model in memory still exists.
    DB::table('service_options')->where('id', $option->id)->delete();
    expect($option->exists)->toBeTrue();

    expect(fn () => mopManager()->delete($option))->toThrow(ModelNotFoundException::class);
});

test('CannotDeleteOption has a technical message without personal data', function () {
    $option = mopOption();

    $exception = CannotDeleteOption::because($option, 'bookings');

    expect($exception->getMessage())->toBe("Service option {$option->id} cannot be deleted: bookings.");
    expect($exception->getMessage())->not->toContain('Perline');
    expect($exception->reason())->toBe('bookings');
});
