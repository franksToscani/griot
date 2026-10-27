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
     *   NOT VERIFIED: the Cameroonian rule follows the national plan of 2014 (nine digits) as it is
     *   commonly described. To check on the official numbering plan of the ART (Agence de
     *   Régulation des Télécommunications, https://www.art.cm) or on the national numbering plan
     *   that ITU-T publishes for country code 237 (list of E.164 national numbering plans).
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
}
