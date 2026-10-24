<?php

namespace App\Profile;

use App\Models\ServiceOption;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an option of a service, only if no booking chose it. This is the only place that knows
 * what blocks the deletion of an option (blockedBy()). It never touches the service or the
 * bookings.
 */
final class ManageOption
{
    /**
     * "bookings" if the option was chosen in at least one booking, in any status; null otherwise.
     * One exists() query, without reading the rows.
     *
     * @return 'bookings'|null
     */
    public function blockedBy(ServiceOption $option): ?string
    {
        return $this->bookedLinks([$option->id])->exists() ? 'bookings' : null;
    }

    /**
     * The ids, among the given ones, of the options chosen in at least one booking: the same rule
     * as blockedBy(), for a whole list in one query (the page of the options shows it per option).
     *
     * @param  list<int>  $optionIds
     * @return list<int>
     */
    public function bookedIds(array $optionIds): array
    {
        if ($optionIds === []) {
            return [];
        }

        return array_values($this->bookedLinks($optionIds)
            ->distinct()
            ->pluck('service_option_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * @throws CannotDeleteOption when the option was chosen in a booking
     */
    public function delete(ServiceOption $option): void
    {
        try {
            DB::transaction(function () use ($option): void {
                // This MUST be the first statement, and from here on only $locked is used, never
                // the model received: it may be old. A booking made at the same moment must not
                // let a booked option be deleted; the check is done after the lock, on the data
                // as it is now.
                $locked = ServiceOption::query()->whereKey($option->id)->lockForUpdate()->firstOrFail();

                $reason = $this->blockedBy($locked);

                if ($reason !== null) {
                    throw CannotDeleteOption::because($locked, $reason);
                }

                $locked->delete();
            });
        } catch (QueryException $exception) {
            // The safety net, outside the transaction, which is already rolled back: a booking can
            // still choose the option between the check and the delete, and
            // booking_service_option.service_option_id is restrictOnDelete. Same criteria as
            // ManageService; anything else goes on.
            if (ForeignKeyViolation::from($exception)) {
                throw CannotDeleteOption::because($option, 'bookings');
            }

            throw $exception;
        }
    }

    /**
     * The only rule: an option is booked when booking_service_option points to it.
     *
     * @param  list<int>  $optionIds
     */
    private function bookedLinks(array $optionIds): Builder
    {
        return DB::table('booking_service_option')->whereIn('service_option_id', $optionIds);
    }
}
