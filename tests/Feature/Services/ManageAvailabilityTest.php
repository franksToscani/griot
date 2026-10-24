<?php

use App\Booking\FreeSlots;
use App\Booking\TimeWindow;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Provider;
use App\Models\Service;
use App\Profile\AvailabilityLimitReached;
use App\Profile\AvailabilityOverlaps;
use App\Profile\ManageAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

function mavManager(): ManageAvailability
{
    return app(ManageAvailability::class);
}

/**
 * A slot of the provider, with local times written as "HH:MM".
 */
function mavSlot(Provider $provider, Weekday $weekday, string $start, string $end): Availability
{
    return Availability::factory()->for($provider)->create([
        'weekday' => $weekday,
        'start_time' => "{$start}:00",
        'end_time' => "{$end}:00",
    ]);
}

/**
 * @return array{weekday: int, start_time: string, end_time: string}
 */
function mavData(int $weekday, string $start, string $end): array
{
    return ['weekday' => $weekday, 'start_time' => "{$start}:00", 'end_time' => "{$end}:00"];
}

test('overlap is start1 < end2 and start2 < end1, on the same day of the same provider', function (int $weekday, string $start, string $end, bool $overlaps) {
    $provider = Provider::factory()->create();
    mavSlot($provider, Weekday::Monday, '09:00', '13:00');
    mavSlot(Provider::factory()->create(), Weekday::Monday, '13:00', '17:00');

    expect(mavManager()->overlaps($provider, $weekday, "{$start}:00", "{$end}:00"))->toBe($overlaps);
})->with([
    'partial at the end' => [1, '12:55', '14:00', true],
    'partial at the start' => [1, '08:00', '09:05', true],
    'contained' => [1, '10:00', '11:00', true],
    'containing' => [1, '08:00', '14:00', true],
    'identical' => [1, '09:00', '13:00', true],
    'touching after' => [1, '13:00', '17:00', false],
    'touching before' => [1, '07:00', '09:00', false],
    'another day' => [2, '09:00', '13:00', false],
    'only another provider there' => [1, '14:00', '15:00', false],
]);

test('a slot being edited never overlaps itself', function () {
    $provider = Provider::factory()->create();
    $slot = mavSlot($provider, Weekday::Monday, '09:00', '13:00');

    expect(mavManager()->overlaps($provider, 1, '09:00:00', '13:00:00'))->toBeTrue();
    expect(mavManager()->overlaps($provider, 1, '09:00:00', '13:00:00', $slot->id))->toBeFalse();
});

test('a day is full with 8 slots; the slot being edited is not counted', function () {
    $provider = Provider::factory()->create();

    for ($hour = 0; $hour < 7; $hour++) {
        mavSlot($provider, Weekday::Monday, sprintf('%02d:00', $hour), sprintf('%02d:30', $hour));
    }

    expect(mavManager()->isFull($provider, 1))->toBeFalse();
    $eighth = mavSlot($provider, Weekday::Monday, '07:00', '07:30');
    expect(mavManager()->isFull($provider, 1))->toBeTrue();
    expect(mavManager()->isFull($provider, 1, $eighth->id))->toBeFalse();
    expect(mavManager()->isFull($provider, 2))->toBeFalse();
    expect(ManageAvailability::MAX_PER_DAY)->toBe(8);
});

test('create saves the slot through the provider, and refuses overlap and full day', function () {
    $provider = Provider::factory()->create();

    $slot = mavManager()->create($provider, mavData(1, '09:00', '13:00'));
    expect($slot->provider_id)->toBe($provider->id);
    expect($slot->fresh()->only(['weekday', 'start_time', 'end_time']))->toBe(['weekday' => Weekday::Monday, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);

    expect(fn () => mavManager()->create($provider, mavData(1, '12:00', '14:00')))->toThrow(AvailabilityOverlaps::class);

    for ($hour = 14; $hour < 21; $hour++) {
        mavManager()->create($provider, mavData(1, sprintf('%02d:00', $hour), sprintf('%02d:30', $hour)));
    }

    // Full AND overlapping: the limit is the one reported.
    expect(fn () => mavManager()->create($provider, mavData(1, '10:00', '11:00')))->toThrow(AvailabilityLimitReached::class);
    expect($provider->availabilities()->count())->toBe(8);
});

test('update changes day and times; moving into a full day or onto another slot is refused', function () {
    $provider = Provider::factory()->create();
    $slot = mavSlot($provider, Weekday::Monday, '09:00', '13:00');
    mavSlot($provider, Weekday::Monday, '15:00', '19:00');

    mavManager()->update($slot, mavData(1, '09:00', '15:00'));
    expect($slot->fresh()->end_time)->toBe('15:00:00');

    expect(fn () => mavManager()->update($slot, mavData(1, '09:00', '16:00')))->toThrow(AvailabilityOverlaps::class);

    for ($hour = 0; $hour < 8; $hour++) {
        mavSlot($provider, Weekday::Tuesday, sprintf('%02d:00', $hour), sprintf('%02d:30', $hour));
    }

    expect(fn () => mavManager()->update($slot, mavData(2, '20:00', '21:00')))->toThrow(AvailabilityLimitReached::class);
    expect($slot->fresh()->only(['weekday', 'start_time', 'end_time']))->toBe(['weekday' => Weekday::Monday, 'start_time' => '09:00:00', 'end_time' => '15:00:00']);
});

test('an old model whose row was already deleted: update finds nothing, delete does nothing', function () {
    $provider = Provider::factory()->create();
    $slot = mavSlot($provider, Weekday::Monday, '09:00', '13:00');
    $other = mavSlot($provider, Weekday::Tuesday, '09:00', '13:00');
    // Deleted by another request: the model in memory still exists.
    DB::table('availabilities')->where('id', $slot->id)->delete();
    expect($slot->exists)->toBeTrue();

    expect(fn () => mavManager()->update($slot, mavData(1, '10:00', '11:00')))->toThrow(ModelNotFoundException::class);
    expect(Availability::query()->whereKey($slot->id)->exists())->toBeFalse();

    mavManager()->delete($slot);
    expect(Availability::query()->pluck('id')->all())->toBe([$other->id]);
});

test('the exceptions have a technical message without personal data', function () {
    $provider = Provider::factory()->create(['name' => 'Salone Awa']);

    $overlap = AvailabilityOverlaps::on($provider, 3);
    $limit = AvailabilityLimitReached::on($provider, 3, 8);

    expect($overlap->getMessage())->toBe("The availability overlaps another one of provider {$provider->id} on weekday 3.");
    expect($limit->getMessage())->toBe("Provider {$provider->id} already has 8 availabilities on weekday 3.");
    expect($overlap->getMessage().$limit->getMessage())->not->toContain('Salone Awa');
    expect($overlap)->toBeInstanceOf(RuntimeException::class);
    expect($limit)->toBeInstanceOf(RuntimeException::class);
});

test('documents the current behaviour: slots that touch give no slot across the boundary', function () {
    // Sunday 18 October 2026, 08:00 UTC; Monday 19 October is summer time (UTC+2).
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    $provider = Provider::factory()->create(['is_published' => true]);
    mavSlot($provider, Weekday::Monday, '09:00', '13:00');
    mavSlot($provider, Weekday::Monday, '13:00', '17:00');
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);

    $starts = app(FreeSlots::class)
        ->slotsFor($provider->fresh(), $service, [], CarbonImmutable::parse('2026-10-19'))
        ->map(fn (TimeWindow $slot) => $slot->start->format('H:i'))
        ->all();

    // Two separate windows, 07:00-11:00 and 11:00-15:00 UTC: 12:30 local (10:30 UTC) would cross
    // 13:00, so it is not offered, as with a pause between the two.
    expect($starts)->toBe(['07:00', '07:30', '08:00', '08:30', '09:00', '09:30', '10:00', '11:00', '11:30', '12:00', '12:30', '13:00', '13:30', '14:00']);
    expect($starts)->not->toContain('10:30');
});
