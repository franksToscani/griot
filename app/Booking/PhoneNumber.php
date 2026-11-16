<?php

namespace App\Booking;

/**
 * The customer's phone number in international format: "+" and the digits, no spaces, for
 * example +393331234567. The one place where the rule is written: the booking form saves
 * numbers this way, and the WhatsApp link of the provider's list reads them with it.
 *
 * Only numbers with a known country become international without a prefix: the country code is
 * never guessed for the others.
 */
final class PhoneNumber
{
    /**
     * The number in international format, or null if it cannot be read. A number already in
     * international format comes back the same.
     *
     * - With "+" or "00": the country code and the number, from 8 to 15 digits in all (as for the
     *   providers' WhatsApp), never starting with 0.
     * - Without a prefix, Italy: 10 digits starting with 3 (a mobile number), becomes +39….
     * - Without a prefix, Cameroon: 9 digits starting with 6 (mobile) or 2 (fixed), becomes +237….
     *   Verified on the ITU communication of the ART of 6.X.2014 (the plan of nine digits since 21
     *   November 2014, "S" = 6 for mobile, 2 for fixed): see display().
     * - Anything else, letters included, is null.
     *
     * Spaces, dashes, dots and brackets between the digits are ignored.
     */
    public static function normalize(string $number): ?string
    {
        $number = trim($number);

        // Digits and separators only, with at most one "+" at the very start.
        if (preg_match('/^\+?[0-9 ().\-]+$/', $number) !== 1) {
            return null;
        }

        $compact = preg_replace('/[ ().\-]/', '', $number) ?? '';

        $international = match (true) {
            str_starts_with($compact, '+') => substr($compact, 1),
            str_starts_with($compact, '00') => substr($compact, 2),
            default => null,
        };

        if ($international !== null) {
            return preg_match('/^[1-9][0-9]{7,14}$/', $international) === 1 ? '+'.$international : null;
        }

        return match (true) {
            preg_match('/^3[0-9]{9}$/', $compact) === 1 => '+39'.$compact,
            preg_match('/^[62][0-9]{8}$/', $compact) === 1 => '+237'.$compact,
            default => null,
        };
    }

    /**
     * A number written to be read (issue #177): the country code and groups of digits, separated
     * by U+00A0 so that the number never wraps in the middle. The format follows the country of
     * the number, never the language of the page. Only three formats, which are sure:
     *
     * - Italy, mobile: +39 333 123 4567 (10 digits starting with 3);
     * - Cameroon: +237 6 71 23 45 67, the nine-digit plan in force since 21 November 2014, "S"
     *   = 6 for mobile and 2 for fixed numbers, written 1-2-2-2-2. Source: ITU, "Cameroon (country
     *   code +237) Communication of 6.X.2014" of the ART, https://www.itu.int/dms_pub/itu-t/oth/02/02/T02020000240001PDFE.pdf
     *   (read on 16 November 2026);
     * - France: +33 6 12 34 56 78 (9 digits, in pairs after the first).
     *
     * Anything else comes back as it was received (trimmed): Italian fixed numbers (area codes of
     * different lengths), other countries (where the country code ends cannot be known without a
     * table), numbers that are not in international format. The links (tel:, wa.me) keep the
     * compact number: this is for the eyes only.
     */
    public static function display(string $number): string
    {
        $number = trim($number);
        $compact = preg_replace('/[ ().\-\x{00A0}]/u', '', $number) ?? '';

        if (preg_match('/^(?:\+|00)([0-9]+)$/', $compact, $international) !== 1) {
            return $number;
        }

        $digits = $international[1];

        if (preg_match('/^39(3[0-9]{2})([0-9]{3})([0-9]{4})$/', $digits, $italy) === 1) {
            return self::groups('+39', array_slice($italy, 1));
        }

        if (preg_match('/^237([26])([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})$/', $digits, $cameroon) === 1) {
            return self::groups('+237', array_slice($cameroon, 1));
        }

        if (preg_match('/^33([1-9])([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})$/', $digits, $france) === 1) {
            return self::groups('+33', array_slice($france, 1));
        }

        return $number;
    }

    /**
     * @param  list<string>  $groups
     */
    private static function groups(string $countryCode, array $groups): string
    {
        return implode("\u{00A0}", [$countryCode, ...$groups]);
    }
}
