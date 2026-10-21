<?php

namespace App\Profile;

use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Edits, deactivates, activates and deletes a service of a provider. This is the only place that
 * knows what blocks a deletion (blockedBy()). It never changes provider_id.
 */
final class ManageService
{
    /**
     * Save the service fields. Neither provider_id nor is_active: they are not in the data the
     * request builds, and they are removed here as well.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Service $service, array $data): Service
    {
        $service->fill(Arr::except($data, ['provider_id', 'is_active']));
        $service->save();

        return $service;
    }

    /**
     * Hide the service from the public page and from the bookable slots. The bookings stay.
     */
    public function deactivate(Service $service): void
    {
        $this->setActive($service, false);
    }

    public function activate(Service $service): void
    {
        $this->setActive($service, true);
    }

    /**
     * What blocks the deletion: "bookings" if the service has bookings in any status,
     * "booked_options" if one of its options was chosen in a booking, null if nothing does.
     * Two light queries with exists(), whatever the number of bookings.
     *
     * @return 'bookings'|'booked_options'|null
     */
    public function blockedBy(Service $service): ?string
    {
        if ($service->bookings()->exists()) {
            return 'bookings';
        }

        $bookedOptions = DB::table('booking_service_option')
            ->whereIn('service_option_id', $service->options()->select('id'))
            ->exists();

        return $bookedOptions ? 'booked_options' : null;
    }

    /**
     * Delete the service and, by cascade, its options.
     *
     * @throws CannotDeleteService when it has bookings or booked options
     */
    public function delete(Service $service): void
    {
        try {
            DB::transaction(function () use ($service): void {
                // This MUST be the first statement, and from here on only $locked is used, never
                // the model received: it may be old. A booking made at the same moment must not let
                // a service with bookings be deleted; the check is done after the lock, on the data
                // as it is now.
                $locked = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

                $reason = $this->blockedBy($locked);

                if ($reason !== null) {
                    throw CannotDeleteService::because($locked, $reason);
                }

                $locked->delete();
            });
        } catch (QueryException $exception) {
            // The safety net, outside the transaction, which is already rolled back: a booking can
            // still arrive between the check and the delete, and bookings.service_id is
            // restrictOnDelete. Only that error is translated; anything else, unique violations
            // included, goes on.
            if ($this->isForeignKeyViolation($exception)) {
                throw CannotDeleteService::because($service, 'bookings');
            }

            throw $exception;
        }
    }

    private function setActive(Service $service, bool $active): void
    {
        DB::transaction(function () use ($service, $active): void {
            // Read again like delete(), so that the decision is taken on the current row.
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_active === $active) {
                return;
            }

            // Assigned on its own, not through fill().
            $locked->is_active = $active;
            $locked->save();
        });
    }

    /**
     * A row still referenced by a foreign key. SQLSTATE 23000 is shared by every integrity
     * violation, so the driver tells them apart: MySQL code 1451 ("Cannot delete or update a
     * parent row"); SQLite has one code (19) for all of them, so its message is read.
     */
    private function isForeignKeyViolation(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];

        if (($errorInfo[0] ?? null) !== '23000') {
            return false;
        }

        return ($errorInfo[1] ?? null) === 1451
            || str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed');
    }
}
