<?php

namespace App\Booking;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The retention of the customers' data (issue #56): a booking that ended more than
 * booking.anonymize_after_months months ago loses the customer's name, phone, email and notes, and
 * the provider's reason for a cancellation; its private link stops working (new public_token). The
 * row stays, for the provider's history: provider, service, options, date, status, cancelled_by,
 * locale, created_by_provider. Which bookings qualify is written once, in the scope
 * Booking::dueForAnonymization().
 */
final class AnonymizeOldBookings
{
    /**
     * The bookings that ended before this instant qualify: now, minus the months of the config.
     * Without overflow: on 31 August six months back is 28 February, never 3 March.
     */
    public function threshold(): CarbonImmutable
    {
        return CarbonImmutable::now()->utc()->subMonthsNoOverflow(config()->integer('booking.anonymize_after_months'));
    }

    /**
     * How many bookings qualify now, without changing anything (the dry run).
     */
    public function count(CarbonImmutable $before): int
    {
        return Booking::query()->dueForAnonymization($before)->count();
    }

    /**
     * Make every booking that qualifies anonymous. A failure on one booking is reported and the
     * others go on; that booking stays as it was, so the next run tries it again.
     *
     * @return array{anonymized: int, skipped: int}
     */
    public function run(CarbonImmutable $before): array
    {
        // The ids only: each booking is read again, under its lock, inside its own transaction.
        $ids = Booking::query()->dueForAnonymization($before)->orderBy('id')->pluck('id');

        $anonymized = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            try {
                $this->anonymize($id, $before) ? $anonymized++ : $skipped++;
            } catch (Throwable $exception) {
                report($exception);
                $skipped++;
            }
        }

        return ['anonymized' => $anonymized, 'skipped' => $skipped];
    }

    /**
     * Whether this booking was made anonymous now; false when it no longer qualifies.
     */
    private function anonymize(int $id, CarbonImmutable $before): bool
    {
        return DB::transaction(function () use ($id, $before): bool {
            // This lock MUST be the first statement, and it reads every condition again: another run
            // of the command may have changed the row since the ids were read. Both lock the same
            // row, so the second one finds it anonymous already and skips it.
            $locked = Booking::query()
                ->dueForAnonymization($before)
                ->whereKey($id)
                ->lockForUpdate()
                ->exists();

            if (! $locked) {
                return false;
            }

            // The query builder, not save(): updated_at stays as it was, since nobody changed the
            // booking. The token is made as CreateBooking makes it: the old private link gives 404.
            Booking::query()->whereKey($id)->toBase()->update([
                'customer_name' => null,
                'customer_phone' => null,
                'customer_email' => null,
                'notes' => null,
                'cancellation_reason' => null,
                'public_token' => Str::random(48),
                'anonymized_at' => CarbonImmutable::now()->utc(),
            ]);

            return true;
        });
    }
}
