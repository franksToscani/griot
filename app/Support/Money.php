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

    /**
     * Cents written as a price in euros for an email: the same text as formatCents() of the
     * frontend (Intl.NumberFormat), written by hand because the production image has no intl
     * extension (Dockerfile). 9200 becomes "92,00 €" in Italian and French, "€92.00" in English.
     * - Italian groups the thousands only from five digits: "1234,50 €", "12.345,67 €".
     * - French groups them with a narrow no-break space (U+202F): "1 234,50 €".
     * - Before "€" Italian and French have a no-break space (U+00A0).
     * Any other language is written as Italian, the language of the site by default.
     */
    public static function format(int $cents, string $locale): string
    {
        $euros = intdiv($cents, 100);
        $decimals = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return match ($locale) {
            'en' => '€'.number_format($euros, 0, '', ',').'.'.$decimals,
            'fr' => number_format($euros, 0, '', "\u{202F}").','.$decimals."\u{00A0}€",
            default => ($euros >= 10000 ? number_format($euros, 0, '', '.') : (string) $euros).','.$decimals."\u{00A0}€",
        };
    }
}
