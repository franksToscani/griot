<?php

namespace App\Support;

/**
 * The address of a WhatsApp chat with a number (https://wa.me/<digits>), shared by the providers'
 * numbers (Provider::whatsappUrl()) and the site's own (SiteContact).
 */
final class WhatsApp
{
    /**
     * Only for a number in international format (+39… or 0039…): any other value gets no link,
     * the country code is never guessed. Spaces, dashes and the like are left out.
     */
    public static function url(?string $number): ?string
    {
        $number = trim($number ?? '');

        if (! str_starts_with($number, '+') && ! str_starts_with($number, '00')) {
            return null;
        }

        // wa.me wants the country code without "+" or "00".
        $digits = preg_replace('/\D/', '', $number) ?? '';
        $digits = str_starts_with($number, '00') ? substr($digits, 2) : $digits;

        return $digits !== '' ? 'https://wa.me/'.$digits : null;
    }
}
