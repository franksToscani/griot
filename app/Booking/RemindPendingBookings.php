<?php

namespace App\Booking;

use App\Models\Booking;
use App\Notifications\PendingReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reminds the provider, by email, of the requests still pending after a while
 * (booking.reminder_after_minutes): one reminder per booking, never repeated, and only for a
 * request that has not started yet. Which bookings qualify is written once, in the scope
 * Booking::awaitingReminder().
 */
final class RemindPendingBookings
{
    /**
     * Queue a reminder for every booking that qualifies now. A failure on one booking is reported
     * and the others go on; that booking stays not reminded, so the next run tries it again.
     *
     * @return array{queued: int, skipped: int}
     */
    public function run(): array
    {
        $createdBefore = CarbonImmutable::now()->subMinutes(config()->integer('booking.reminder_after_minutes'));

        // The ids only: each booking is read again, under its lock, inside its own transaction.
        $ids = Booking::query()->awaitingReminder($createdBefore)->orderBy('id')->pluck('id');

        $queued = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            try {
                $this->remind($id, $createdBefore) ? $queued++ : $skipped++;
            } catch (Throwable $exception) {
                report($exception);
                $skipped++;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Whether the reminder of this booking was queued now; false when it no longer qualifies.
     */
    private function remind(int $id, CarbonImmutable $createdBefore): bool
    {
        return DB::transaction(function () use ($id, $createdBefore): bool {
            // This lock MUST be the first statement, and it reads every condition again: another run
            // of the command, the provider's answer (ManageBooking) or the customer's cancellation
            // (CancelBooking) may have changed the row since the ids were read. They lock the same
            // row, so they wait for each other, and the decision is taken on the row as it is now.
            $booking = Booking::query()
                ->awaitingReminder($createdBefore)
                ->whereKey($id)
                ->lockForUpdate()
                ->with('provider.user')
                ->first();

            if ($booking === null) {
                return false;
            }

            // The query builder, not save(): updated_at stays as it was, since a reminder is not a
            // change made by a person.
            Booking::query()->whereKey($booking->id)->toBase()->update(['reminder_sent_at' => CarbonImmutable::now()->utc()]);

            // Queued inside the transaction, on purpose. With the "database" queue (the one in use)
            // the job is a row written in this same transaction: if queueing throws, everything is
            // rolled back and the booking stays not reminded; the worker cannot see the job before
            // the commit. LIMIT: with a queue outside the database (Redis, SQS) the job would leave
            // at once and a later rollback could not take it back; that case needs "mark, queue,
            // and clear the mark if queueing fails" instead.
            $booking->provider->user->notify(new PendingReminderNotification($booking));

            return true;
        });
    }
}
