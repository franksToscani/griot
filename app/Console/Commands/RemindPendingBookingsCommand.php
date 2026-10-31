<?php

namespace App\Console\Commands;

use App\Booking\RemindPendingBookings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled every 15 minutes in routes/console.php; in local development it is run by hand, since
 * "composer dev" does not start the scheduler. The work is RemindPendingBookings'.
 */
#[Signature('bookings:remind-pending')]
#[Description('Queue an email reminder to the provider for each request still pending after the threshold')]
class RemindPendingBookingsCommand extends Command
{
    /**
     * Execute the console command. It always succeeds: a failure on one booking is reported by
     * RemindPendingBookings and counted as skipped.
     */
    public function handle(RemindPendingBookings $remindPendingBookings): int
    {
        $result = $remindPendingBookings->run();

        $this->info(__('booking.reminder.command_summary', $result));

        return self::SUCCESS;
    }
}
