<?php

use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Profile\CannotDeleteService;
use App\Profile\ManageService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00', 'UTC'));
});

function msvService(): Service
{
    return Service::factory()->for(Provider::factory())->create(['name' => 'Box braids', 'price' => '25.00']);
}

function msvManager(): ManageService
{
    return app(ManageService::class);
}

/**
 * A booking inserted with the query builder, bypassing the model and its events.
 */
function msvInsertBooking(Service $service): void
{
    DB::table('bookings')->insert([
        'provider_id' => $service->provider_id,
        'service_id' => $service->id,
        'customer_name' => 'Cliente',
        'customer_phone' => '+39 333 000 0000',
        'starts_at' => '2026-10-29 08:00:00',
        'ends_at' => '2026-10-29 09:00:00',
        'status' => 'pending',
        'public_token' => Str::random(48),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Run $run with a "deleting" listener on Service, then remove it in any case. Service has no
 * listeners of its own, so flushEventListeners() removes only this one.
 */
function msvWhileDeleting(Closure $listener, Closure $run): mixed
{
    Service::deleting($listener);

    try {
        return $run();
    } finally {
        Service::flushEventListeners();
    }
}

test('update saves the fields, never provider_id nor is_active', function () {
    $service = msvService();
    $providerId = $service->provider_id;

    msvManager()->update($service, ['name' => 'Cornrows', 'price' => '30.00', 'provider_id' => 999, 'is_active' => false]);

    $fresh = $service->fresh();
    expect($fresh->name)->toBe('Cornrows');
    expect($fresh->price)->toBe('30.00');
    expect($fresh->provider_id)->toBe($providerId);
    expect($fresh->is_active)->toBeTrue();
});

test('deactivate and activate are idempotent', function () {
    $service = msvService();

    msvManager()->deactivate($service);
    expect($service->fresh()->is_active)->toBeFalse();

    $updatedAt = $service->fresh()->updated_at->toIso8601String();
    $this->travel(1)->hour();
    msvManager()->deactivate($service);
    expect($service->fresh()->updated_at->toIso8601String())->toBe($updatedAt);

    msvManager()->activate($service);
    expect($service->fresh()->is_active)->toBeTrue();
});

test('a service without bookings is deleted with its options', function () {
    $service = msvService();
    $option = ServiceOption::factory()->for($service)->create();

    expect(msvManager()->blockedBy($service))->toBeNull();
    msvManager()->delete($service);

    expect(Service::query()->whereKey($service->id)->exists())->toBeFalse();
    expect(ServiceOption::query()->whereKey($option->id)->exists())->toBeFalse();
});

test('a booking in any status blocks the deletion', function (string $status) {
    $service = msvService();
    Booking::factory()->for($service->provider)->for($service)->create(['status' => $status]);

    expect(msvManager()->blockedBy($service))->toBe('bookings');

    try {
        msvManager()->delete($service);
        $this->fail('CannotDeleteService was not thrown.');
    } catch (CannotDeleteService $exception) {
        expect($exception->reason())->toBe('bookings');
    }

    expect(Service::query()->whereKey($service->id)->exists())->toBeTrue();
})->with(['pending', 'confirmed', 'cancelled']);

test('an option chosen in a booking of another service blocks the deletion', function () {
    // Rare: the booking belongs to another service, but points to an option of this one.
    $service = msvService();
    $option = ServiceOption::factory()->for($service)->create();
    $other = Service::factory()->for($service->provider)->create();
    $booking = Booking::factory()->for($service->provider)->for($other)->create();
    DB::table('booking_service_option')->insert(['booking_id' => $booking->id, 'service_option_id' => $option->id]);

    expect(msvManager()->blockedBy($service))->toBe('booked_options');
    expect(fn () => msvManager()->delete($service))->toThrow(CannotDeleteService::class);
    expect(Service::query()->whereKey($service->id)->exists())->toBeTrue();
});

test('a booking arriving between the check and the delete is caught by the foreign key', function () {
    $service = msvService();
    $inserted = false;

    msvWhileDeleting(function (Service $deleting) use (&$inserted) {
        // Only the first time: a booking is made just before the row is deleted.
        if (! $inserted) {
            $inserted = true;
            msvInsertBooking($deleting);
        }
    }, function () use ($service) {
        try {
            msvManager()->delete($service);
            $this->fail('CannotDeleteService was not thrown.');
        } catch (CannotDeleteService $exception) {
            expect($exception->reason())->toBe('bookings');
        }
    });

    expect($inserted)->toBeTrue();
    expect(Service::query()->whereKey($service->id)->exists())->toBeTrue();
});

test('the deleting listener above is not registered any more', function () {
    expect(Event::hasListeners('eloquent.deleting: '.Service::class))->toBeFalse();
});

test('an old model without bookings, while the database has one: not deleted', function () {
    $service = msvService();
    msvInsertBooking($service);

    // The bookings are always read from the database, so this blocks with or without the locked
    // read: the next test is the one that depends on it.
    expect(fn () => msvManager()->delete($service))->toThrow(CannotDeleteService::class);
    expect(Service::query()->whereKey($service->id)->exists())->toBeTrue();
});

test('an old model whose row was already deleted: the locked read finds nothing', function () {
    $service = msvService();
    // Deleted by another request: the model in memory still exists.
    DB::table('services')->where('id', $service->id)->delete();
    expect($service->exists)->toBeTrue();

    expect(fn () => msvManager()->delete($service))->toThrow(ModelNotFoundException::class);
});

test('CannotDeleteService has a technical message without personal data', function () {
    $service = msvService();

    $exception = CannotDeleteService::because($service, 'bookings');

    expect($exception->getMessage())->toBe("Service {$service->id} cannot be deleted: bookings.");
    expect($exception->getMessage())->not->toContain('Box braids');
    expect($exception->reason())->toBe('bookings');
});
