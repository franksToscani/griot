<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Providers' Time Zone
    |--------------------------------------------------------------------------
    |
    | The weekly availabilities are local wall-clock times in this time zone
    | ("from 9 to 13" in Bologna). The application and the database stay in
    | UTC: the booking code converts from this zone, and only from here.
    |
    */

    'timezone' => 'Europe/Rome',

    /*
    |--------------------------------------------------------------------------
    | Slot Step
    |--------------------------------------------------------------------------
    |
    | Inside a time window, a bookable slot starts every this many minutes,
    | counting from the start of the window.
    |
    */

    'slot_step_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Minimum Notice
    |--------------------------------------------------------------------------
    |
    | A slot can be booked only if it starts at least this many minutes from
    | now, so that the provider has time to see the booking.
    |
    */

    'minimum_notice_minutes' => 120,

    /*
    |--------------------------------------------------------------------------
    | Booking Window
    |--------------------------------------------------------------------------
    |
    | Customers can book from today up to this many days ahead, both days
    | included. Days are the providers' local calendar days.
    |
    */

    'max_days_ahead' => 60,

];
