<?php

use App\Booking\BookingOutcome;
use App\Booking\CreateBooking;
use App\Booking\CustomerDetails;
use App\Booking\SlotRules;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Notifications\BookingStatusNotification;
use App\Notifications\CustomerCancelledNotification;
use App\Notifications\NewBookingNotification;
use App\Notifications\PendingReminderNotification;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;

/*
 * The price saved on the booking (issue #97, docs/bookings.md): the price of the service and of
 * each option when the booking is made, in whole cents, never the catalog of later.
 */

// "Now" is Sunday 18 October 2026, 08:00 UTC; the provider works on Monday 19 from 09:00 to 13:00.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
});

function bspProvider(): Provider
{
    $provider = Provider::factory()->published()->create();
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);

    return $provider;
}

function bspService(Provider $provider, string $price): Service
{
    return Service::factory()->for($provider)->create(['price' => $price, 'duration_min' => 60, 'duration_max' => 60]);
}

/**
 * @param  list<int>  $optionIds
 */
function bspBook(Provider $provider, Service $service, array $optionIds = [], bool $byProvider = false): Booking
{
    return app(CreateBooking::class)->create(
        $provider,
        $service,
        $optionIds,
        CarbonImmutable::parse('2026-10-19 09:00', 'Europe/Rome'),
        new CustomerDetails('Awa Diop', '+393331234567'),
        status: $byProvider ? BookingStatus::Confirmed : BookingStatus::Pending,
        rules: $byProvider ? SlotRules::forProvider() : null,
        createdByProvider: $byProvider,
    );
}

/**
 * The saved prices of a booking as they are in the database: the service, then each option by id.
 *
 * @return array{service: int, options: array<int, int>}
 */
function bspSaved(Booking $booking): array
{
    return [
        'service' => (int) DB::table('bookings')->where('id', $booking->id)->value('service_price_cents'),
        'options' => DB::table('booking_service_option')
            ->where('booking_id', $booking->id)
            ->orderBy('service_option_id')
            ->pluck('extra_price_cents', 'service_option_id')
            ->map(fn (mixed $cents) => (int) $cents)
            ->all(),
    ];
}

// ---------------------------------------------------------------------------------------------
// Money
// ---------------------------------------------------------------------------------------------

test('Money::cents() reads a price from its text, without floating point', function (string $price, int $cents) {
    expect(Money::cents($price))->toBe($cents);
})->with([
    'two decimals' => ['25.50', 2550],
    'one decimal' => ['7.5', 750],
    'no decimals' => ['90', 9000],
    'zero' => ['0.00', 0],
    'cents that float would round' => ['0.29', 29],
    'more decimals are cut' => ['1.239', 123],
    'the highest service price' => ['9999.99', 999999],
]);

test('Money::cents() refuses a text that is not a price', function (string $price) {
    expect(fn () => Money::cents($price))->toThrow(InvalidArgumentException::class);
})->with(['', '-1.00', '1,50', 'abc', '.50']);

test('Money::centsFromDatabase() reads strings, integers and the floats of SQLite', function (string|int|float $price, int $cents) {
    expect(Money::centsFromDatabase($price))->toBe($cents);
})->with([
    'string of MySQL and PostgreSQL' => ['25.50', 2550],
    'integer of SQLite' => [90, 9000],
    'float of SQLite' => [25.5, 2550],
    'float that times 100 is not whole' => [0.29, 29],
    'the highest' => [9999.99, 999999],
]);

test('every price up to 9999.99, read as the float of SQLite, gives back its cents', function () {
    // The claim of centsFromDatabase(): printed with two decimals, the nearest float of every
    // price with two decimals is that price again. Checked on all of them.
    $wrong = [];

    for ($cents = 0; $cents <= 999999; $cents++) {
        if (Money::centsFromDatabase($cents / 100) !== $cents) {
            $wrong[] = $cents;
        }
    }

    expect($wrong)->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// CreateBooking
// ---------------------------------------------------------------------------------------------

test('a booking saves the price of its service and of each chosen option, and adds them up', function () {
    $provider = bspProvider();
    $service = bspService($provider, '25.00');
    $beads = ServiceOption::factory()->for($service)->create(['extra_price' => '7.50', 'extra_duration' => 0]);
    $free = ServiceOption::factory()->for($service)->create(['extra_price' => '0.00', 'extra_duration' => 0]);
    ServiceOption::factory()->for($service)->create(['extra_price' => '99.00', 'extra_duration' => 0]);

    // A repeated id counts once, as for the minutes.
    $booking = bspBook($provider, $service, [$free->id, $beads->id, $beads->id]);

    expect(bspSaved($booking))->toBe(['service' => 2500, 'options' => [$beads->id => 750, $free->id => 0]])
        ->and($booking->fresh()->totalCents())->toBe(3250);
});

test('a price changed between the form and the save is saved as it is at the save', function () {
    $provider = bspProvider();
    $service = bspService($provider, '25.00');
    $option = ServiceOption::factory()->for($service)->create(['extra_price' => '5.00', 'extra_duration' => 0]);
    // The model the request read, with the old prices in memory.
    $stale = Service::query()->with('options')->findOrFail($service->id);

    // The provider saves new prices before the booking is written.
    DB::table('services')->where('id', $service->id)->update(['price' => '30.00']);
    DB::table('service_options')->where('id', $option->id)->update(['extra_price' => '6.50']);

    $booking = bspBook($provider, $stale, [$option->id]);

    expect(bspSaved($booking))->toBe(['service' => 3000, 'options' => [$option->id => 650]]);
});

test('a booking written by the provider in the own agenda saves the prices too', function () {
    $provider = bspProvider();
    $service = bspService($provider, '9999.99');
    $first = ServiceOption::factory()->for($service)->create(['extra_price' => '999.99', 'extra_duration' => 0]);
    $second = ServiceOption::factory()->for($service)->create(['extra_price' => '999.99', 'extra_duration' => 0]);

    $booking = bspBook($provider, $service, [$first->id, $second->id], byProvider: true);

    expect($booking->created_by_provider)->toBeTrue()
        ->and(bspSaved($booking))->toBe(['service' => 999999, 'options' => [$first->id => 99999, $second->id => 99999]])
        // The highest total, still a whole number of cents.
        ->and($booking->fresh()->totalCents())->toBe(1199997);
});

test('the saved prices are not mass assignable', function () {
    $booking = new Booking(['service_price_cents' => 1]);

    expect($booking->service_price_cents)->toBeNull();
});

test('an option read outside the options of a booking has no saved price', function () {
    $option = ServiceOption::factory()->create();

    expect(fn () => Booking::optionPriceCents($option))->toThrow(LogicException::class);
});

// ---------------------------------------------------------------------------------------------
// Factory and seeder
// ---------------------------------------------------------------------------------------------

test('the factory saves the price of the service of the booking', function () {
    $service = Service::factory()->create(['price' => '42.30']);

    expect(Booking::factory()->for($service->provider)->for($service)->create()->service_price_cents)->toBe(4230)
        ->and(Booking::factory()->create()->service_price_cents)->toBeGreaterThan(0);
});

test('the seeder saves the prices of the catalog on its bookings and their options', function () {
    $this->seed();

    $bookings = Booking::query()->with(['service', 'options'])->get();
    expect($bookings)->not->toBeEmpty();

    foreach ($bookings as $booking) {
        expect($booking->service_price_cents)->toBe(Money::cents($booking->service->price));

        foreach ($booking->options as $option) {
            expect(Booking::optionPriceCents($option))->toBe(Money::cents($option->extra_price));
        }
    }
    expect(DB::table('booking_service_option')->whereNull('extra_price_cents')->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// The emails stay without a price
// ---------------------------------------------------------------------------------------------

test('the emails of a booking stay without a price', function (Closure $notification, bool $listsOptions) {
    $service = Service::factory()->create(['price' => '87.65']);
    $option = ServiceOption::factory()->for($service)->create(['name' => 'Perline', 'extra_price' => '4.35']);
    $booking = Booking::factory()->for($service->provider)->for($service)->create(['customer_email' => 'cliente@example.test']);
    attachBookingOptions($booking, $option);

    /** @var MailMessage $mail */
    $mail = $notification($booking)->toMail($booking->provider->user);
    $html = (string) $mail->render();
    $text = view($mail->view['text'], $mail->viewData)->render();

    // The service is there, and the option by name where the email lists the options; none of the
    // prices, nor the total (92,00 €), nor a euro sign.
    expect($html.$text)->toContain($service->name);
    if ($listsOptions) {
        expect($html.$text)->toContain('Perline');
    }
    foreach (['87,65', '87.65', '4,35', '4.35', '92,00', '92.00', '€', 'EUR'] as $price) {
        expect($html.$text)->not->toContain($price);
    }
})->with([
    'new booking' => [fn (Booking $booking) => new NewBookingNotification($booking), true],
    'reminder' => [fn (Booking $booking) => new PendingReminderNotification($booking), true],
    'cancelled by the customer' => [fn (Booking $booking) => new CustomerCancelledNotification($booking), true],
    // The answer to the customer names the service only.
    'confirmed' => [fn (Booking $booking) => new BookingStatusNotification($booking, BookingOutcome::Confirmed), false],
    'rejected' => [fn (Booking $booking) => new BookingStatusNotification($booking, BookingOutcome::Rejected), false],
]);
