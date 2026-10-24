<?php

namespace App\Profile;

use App\Models\Availability;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;

/**
 * Creates, edits and deletes the weekly time slots of a provider. This is the only place that
 * knows when two slots overlap (overlaps()) and how many slots a day can have (isFull()). It
 * never touches the bookings: they keep their own start and end.
 *
 * Times are "HH:MM:SS" strings of the same length, so comparing them as strings compares the
 * times. A slot is [start, end): two slots that only touch (09:00-13:00 and 13:00-17:00) do not
 * overlap.
 */
final class ManageAvailability
{
    /**
     * At most this many time slots on the same day.
     */
    public const MAX_PER_DAY = 8;

    /**
     * Whether [start, end) overlaps another slot of the provider on that day: start1 < end2 and
     * start2 < end1. $ignoreId is the slot being edited, which never overlaps itself. One exists()
     * query.
     */
    public function overlaps(Provider $provider, int $weekday, string $start, string $end, ?int $ignoreId = null): bool
    {
        return $provider->availabilities()
            ->where('weekday', $weekday)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();
    }

    /**
     * Whether the day already has MAX_PER_DAY slots, without counting $ignoreId (the slot being
     * edited: moving it within its own day never fills the day).
     */
    public function isFull(Provider $provider, int $weekday, ?int $ignoreId = null): bool
    {
        return $provider->availabilities()
            ->where('weekday', $weekday)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->count() >= self::MAX_PER_DAY;
    }

    /**
     * @param  array{weekday: int, start_time: string, end_time: string}  $data
     *
     * @throws AvailabilityLimitReached when the day is full
     * @throws AvailabilityOverlaps when the slot overlaps another one of the same day
     */
    public function create(Provider $provider, array $data): Availability
    {
        return DB::transaction(function () use ($provider, $data): Availability {
            // This MUST be the first statement, and from here on only $locked is used: two requests
            // of the same provider wait for each other here, so the second one sees the slot of the
            // first. The request already checked, before the transaction; this is the real check.
            $locked = Provider::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();

            $this->ensureFits($locked, $data, null);

            // Through the relation: provider_id is set by the code, never by the input.
            return $locked->availabilities()->create($data);
        });
    }

    /**
     * Save the new day and times. Moving the slot to a full day is refused.
     *
     * @param  array{weekday: int, start_time: string, end_time: string}  $data
     *
     * @throws AvailabilityLimitReached when the new day is full
     * @throws AvailabilityOverlaps when the slot overlaps another one of the new day
     */
    public function update(Availability $availability, array $data): Availability
    {
        return DB::transaction(function () use ($availability, $data): Availability {
            // The provider's row first, as in create(): the same lock for every change of its slots.
            $locked = Provider::query()->whereKey($availability->provider_id)->lockForUpdate()->firstOrFail();
            // Read again after the lock: deleted in the meantime, it is a 404.
            $current = $locked->availabilities()->whereKey($availability->id)->firstOrFail();

            $this->ensureFits($locked, $data, $current->id);

            $current->fill($data);
            $current->save();

            return $current;
        });
    }

    /**
     * Delete the slot. Deleting the last one is allowed: the provider then offers no slots.
     */
    public function delete(Availability $availability): void
    {
        DB::transaction(function () use ($availability): void {
            $locked = Provider::query()->whereKey($availability->provider_id)->lockForUpdate()->firstOrFail();

            // Already deleted by another request: nothing to do.
            $locked->availabilities()->whereKey($availability->id)->delete();
        });
    }

    /**
     * The limit first, then the overlap: one exception, the first rule that fails.
     *
     * @param  array{weekday: int, start_time: string, end_time: string}  $data
     */
    private function ensureFits(Provider $provider, array $data, ?int $ignoreId): void
    {
        if ($this->isFull($provider, $data['weekday'], $ignoreId)) {
            throw AvailabilityLimitReached::on($provider, $data['weekday'], self::MAX_PER_DAY);
        }

        if ($this->overlaps($provider, $data['weekday'], $data['start_time'], $data['end_time'], $ignoreId)) {
            throw AvailabilityOverlaps::on($provider, $data['weekday']);
        }
    }
}
