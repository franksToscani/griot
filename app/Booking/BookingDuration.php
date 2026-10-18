<?php

namespace App\Booking;

use App\Models\Service;
use App\Models\ServiceOption;
use Illuminate\Database\Eloquent\Collection;

/**
 * How many minutes a booking of a service, with the chosen options, keeps the provider busy.
 *
 * It does not check whether the service is active or its provider published: the slot
 * search (issue #24) and the booking form do that before asking for a duration.
 */
final class BookingDuration
{
    /**
     * The minutes the booking takes. Repeated option ids count once; no options is valid.
     *
     * If the options are already loaded on the service, no query runs; otherwise one query
     * reads all the chosen options at once.
     *
     * @param  list<int>  $optionIds
     *
     * @throws InvalidBookingOptions when any id is not an option of this service
     */
    public function minutesFor(Service $service, array $optionIds = []): int
    {
        $options = $this->chosenOptions($service, array_values(array_unique($optionIds)));

        return $this->blockedMinutes($service, $options);
    }

    /**
     * Starting assumption, to be confirmed with the providers in Sprint 7 (see issue #24):
     * the booking blocks the longest duration of the service plus the extra minutes of the
     * chosen options. It avoids overlaps but offers fewer slots. This is the only place
     * where the rule lives, so changing it means changing this method only.
     *
     * @param  Collection<int, ServiceOption>  $options
     */
    private function blockedMinutes(Service $service, Collection $options): int
    {
        $minutes = $service->duration_max;

        foreach ($options as $option) {
            $minutes += $option->extra_duration;
        }

        return $minutes;
    }

    /**
     * The chosen options of the service, or an exception if any id does not belong to it.
     *
     * @param  list<int>  $optionIds
     * @return Collection<int, ServiceOption>
     *
     * @throws InvalidBookingOptions
     */
    private function chosenOptions(Service $service, array $optionIds): Collection
    {
        if ($optionIds === []) {
            return new Collection;
        }

        // An option of another service or provider is not found here, exactly like a missing id.
        $options = $service->relationLoaded('options')
            ? $service->options->whereIn('id', $optionIds)
            : $service->options()->whereKey($optionIds)->get();

        $invalidIds = array_values(array_diff($optionIds, $options->modelKeys()));

        if ($invalidIds !== []) {
            throw InvalidBookingOptions::forService($service, $invalidIds);
        }

        return $options;
    }
}
