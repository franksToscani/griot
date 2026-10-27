<?php

use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Models\User;
use Database\Seeders\ProviderSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * @return array<string, int>
 */
function seededTableCounts(): array
{
    return [
        'users' => User::count(),
        'categories' => Category::count(),
        'providers' => Provider::count(),
        'services' => Service::count(),
        'service_options' => ServiceOption::count(),
        'availabilities' => Availability::count(),
        'bookings' => Booking::count(),
        'booking_service_option' => DB::table('booking_service_option')->count(),
    ];
}

test('the seeder creates the development data', function () {
    $this->seed();

    expect(seededTableCounts())->toBe([
        'users' => 5,
        'categories' => 7,
        'providers' => 4,
        'services' => 10,
        'service_options' => 14,
        'availabilities' => 22,
        'bookings' => 5,
        'booking_service_option' => 7,
    ]);
});

test('the seeder can run more than once', function () {
    $this->seed();
    $countsAfterFirstRun = seededTableCounts();

    $this->seed();

    expect(seededTableCounts())->toBe($countsAfterFirstRun);
});

test('published providers have at least one service and one availability', function () {
    $this->seed();

    $published = Provider::query()
        ->where('is_published', true)
        ->withCount(['services', 'availabilities'])
        ->get();

    expect($published)->toHaveCount(3);
    foreach ($published as $provider) {
        expect($provider->services_count)->toBeGreaterThan(0);
        expect($provider->availabilities_count)->toBeGreaterThan(0);
    }
});

test('there is an unpublished provider', function () {
    $this->seed();

    expect(Provider::query()->where('is_published', false)->exists())->toBeTrue();
});

test('the example bookings keep the statuses of the seeder', function () {
    $this->seed();

    // status is not fillable: the seeder sets it on its own. Without that line all five would be
    // pending, the default.
    $statuses = Booking::query()->get()->countBy(fn (Booking $booking) => $booking->status->value)->sortKeys()->all();

    expect($statuses)->toBe(['cancelled' => 1, 'confirmed' => 2, 'pending' => 2]);
});

test('active bookings of the same provider do not overlap', function () {
    $this->seed();

    Booking::query()
        ->where('status', '!=', BookingStatus::Cancelled)
        ->orderBy('starts_at')
        ->get()
        ->groupBy('provider_id')
        ->each(function ($bookings) {
            $bookings->sliding(2)->each(function ($pair) {
                [$previous, $next] = $pair->values()->all();

                expect($next->starts_at->gte($previous->ends_at))->toBeTrue();
            });
        });
});

test('provider users can log in with the development password', function () {
    $this->seed();

    $user = User::query()->where('email', 'trecce.sahel@example.com')->firstOrFail();

    expect(Hash::check(ProviderSeeder::DEV_PASSWORD, $user->password))->toBeTrue();
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->provider)->not->toBeNull();
});
