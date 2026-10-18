<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

test('a booking can be created with the factory', function () {
    $booking = Booking::factory()->create();

    expect($booking->exists)->toBeTrue();
    expect($booking->service->provider_id)->toBe($booking->provider_id);
    expect($booking->ends_at->isAfter($booking->starts_at))->toBeTrue();
    expect($booking->starts_at->timezoneName)->toBe('UTC');
    $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
});

test('a booking is pending by default', function () {
    $service = Service::factory()->create();

    $booking = $service->provider->bookings()->make([
        'service_id' => $service->id,
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+393331234567',
        'starts_at' => '2026-11-03 09:00:00',
        'ends_at' => '2026-11-03 12:00:00',
    ]);
    // The secret token is not fillable: the code sets it before saving.
    $booking->public_token = Str::random(48);
    $booking->save();

    expect($booking->status)->toBe(BookingStatus::Pending);
    expect($booking->refresh()->status)->toBe(BookingStatus::Pending);
});

test('the status is stored as a string and read back as an enum', function () {
    $booking = Booking::factory()->confirmed()->create();

    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

test('the provider cannot be mass assigned', function () {
    $provider = Provider::factory()->create();

    $booking = new Booking([
        'provider_id' => $provider->id,
        'customer_name' => 'Awa Diop',
    ]);

    expect($booking->isFillable('provider_id'))->toBeFalse();
    expect($booking->provider_id)->toBeNull();
    expect($booking->customer_name)->toBe('Awa Diop');
});

test('a booking belongs to a provider', function () {
    $provider = Provider::factory()->create();
    $booking = Booking::factory()->for($provider)->create();

    expect($booking->provider->is($provider))->toBeTrue();
});

test('a booking belongs to a service', function () {
    $service = Service::factory()->create();
    $booking = Booking::factory()->for($service->provider)->for($service)->create();

    expect($booking->service->is($service))->toBeTrue();
});

test('a provider has many bookings', function () {
    $provider = Provider::factory()->create();
    Booking::factory()->count(2)->for($provider)->create();

    expect($provider->bookings)->toHaveCount(2);
});

test('a service has many bookings', function () {
    $service = Service::factory()->create();
    Booking::factory()->count(2)->for($service->provider)->for($service)->create();

    expect($service->bookings)->toHaveCount(2);
});

test('a provider with bookings cannot be deleted', function () {
    $booking = Booking::factory()->create();

    expect(fn () => $booking->provider->delete())
        ->toThrow(QueryException::class);

    $this->assertModelExists($booking);
});

test('a service with bookings cannot be deleted', function () {
    $booking = Booking::factory()->create();

    expect(fn () => $booking->service->delete())
        ->toThrow(QueryException::class);

    $this->assertModelExists($booking);
});
