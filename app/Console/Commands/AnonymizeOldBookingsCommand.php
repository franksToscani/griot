<?php

namespace App\Console\Commands;

use App\Booking\AnonymizeOldBookings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled every day in routes/console.php, with --force; in local development it is run by hand.
 * The work is AnonymizeOldBookings'. The output says how many bookings and up to when: never a
 * name, a phone or anything else of a customer.
 */
#[Signature('bookings:anonymize-old
    {--dry-run : Only count the bookings that qualify, without changing them}
    {--force : Do not ask for confirmation}')]
#[Description('Make anonymous the customer data of the bookings that ended more than booking.anonymize_after_months months ago')]
class AnonymizeOldBookingsCommand extends Command
{
    /**
     * Execute the console command. It always succeeds: a failure on one booking is reported by
     * AnonymizeOldBookings and counted as skipped. Without --force it asks first, and a "no" (or no
     * answer, outside a terminal) changes nothing.
     */
    public function handle(AnonymizeOldBookings $anonymizeOldBookings): int
    {
        $before = $anonymizeOldBookings->threshold();
        $count = $anonymizeOldBookings->count($before);
        $replace = ['count' => $count, 'before' => $before->format('Y-m-d H:i').' UTC'];

        if ($this->option('dry-run')) {
            $this->info(__('booking.retention.dry_run', $replace));

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info(__('booking.retention.nothing', $replace));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(__('booking.retention.confirm', $replace))) {
            $this->info(__('booking.retention.aborted'));

            return self::SUCCESS;
        }

        $result = $anonymizeOldBookings->run($before);

        $this->info(__('booking.retention.summary', [...$result, 'before' => $replace['before']]));

        return self::SUCCESS;
    }
}
