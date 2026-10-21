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

    /*
    |--------------------------------------------------------------------------
    | Cancellation Notice
    |--------------------------------------------------------------------------
    |
    | A customer can cancel a booking online up to this many minutes before
    | it starts, that moment included. Minutes are counted on the instant in
    | UTC, so a change of daylight saving time does not move the limit.
    |
    */

    'cancellation_notice_minutes' => 1440,

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | How many requests one IP address can send to the public booking routes:
    | the booking form (per minute and per hour), the customer's cancellation
    | and the free slots. Every request counts, invalid ones included.
    |
    */

    'rate_limit_store_per_minute' => 5,

    'rate_limit_store_per_hour' => 20,

    'rate_limit_cancel_per_minute' => 10,

    'rate_limit_slots_per_minute' => 60,

    // Photo uploads of a logged-in provider, per user (not per IP).
    'rate_limit_avatar_per_minute' => 10,

];
