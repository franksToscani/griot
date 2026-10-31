<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The scheduler must run on the server ("schedule:run" every minute, deploy of Sprint 8); locally
// "composer dev" does not start it, so the command is run by hand. withoutOverlapping(): a run that
// lasts longer than 15 minutes is not doubled (RemindPendingBookings also locks each booking).
Schedule::command('bookings:remind-pending')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
