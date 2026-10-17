<?php

namespace App\Enums;

/**
 * Days of the week, numbered as ISO-8601 (Carbon's dayOfWeekIso).
 */
enum Weekday: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;
}
