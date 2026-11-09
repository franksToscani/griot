<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Euro amounts in whole cents (issue #97, docs/bookings.md). Griot has one currency, the euro:
 * no column says which one.
 *
 * Never "price * 100" with floating point numbers: 0.1 + 0.2 is 0.30000000000000004 there. A price
 * is read from its text, "25.50", digit by digit.
 */
final class Money
{
    /**
     * "25.50" becomes 2550, "7.5" 750, "90" 9000. The text the decimal:2 cast gives, or a price
     * written by a provider after the request normalized it. More than two decimals are cut, never
     * rounded up: the casts and the validation never give more than two.
     *
     * @throws InvalidArgumentException when the text is not a positive decimal number
     */
    public static function cents(string $price): int
    {
        if (preg_match('/^(\d+)(?:\.(\d*))?$/', $price, $parts) !== 1) {
            throw new InvalidArgumentException('Not a price in euros: '.$price);
        }

        return (int) $parts[1] * 100 + (int) str_pad(substr($parts[2] ?? '', 0, 2), 2, '0');
    }

    /**
     * A price as the database returns it, read with the query builder (the backfill migration):
     * MySQL and PostgreSQL give a string ("25.50"), SQLite a number (25.5, or 90 when there are no
     * decimals). The number is written with two decimals first: up to 9999.99 the nearest float of
     * every price with two decimals is printed back as that price.
     */
    public static function centsFromDatabase(string|int|float $price): int
    {
        return self::cents(is_string($price) ? $price : sprintf('%.2f', $price));
    }
}
