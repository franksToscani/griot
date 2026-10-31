<?php

namespace App\Support;

/**
 * The two questions about a saved language (of a booking, of a user), answered in one place.
 *
 * - available(): may the site be shown in it now? Only the languages of app.available_locales.
 * - forDelivery(): in which language is an email or a WhatsApp text written? Any language that is
 *   written (its lang/{code}.json exists), even one not open to everyone yet: a customer who
 *   booked in French during a preview still reads French. Anything else gives Italian.
 */
final class Locales
{
    /**
     * The language, if the site can be shown in it now; null otherwise.
     */
    public static function available(?string $locale): ?string
    {
        return $locale !== null && array_key_exists($locale, config()->array('app.available_locales')) ? $locale : null;
    }

    /**
     * The language to write in: the one given if it is written, otherwise the reference language.
     *
     * Not app.locale: Laravel changes it with the language of the request, so a provider who never
     * chose would get the language of the customer who sent the request. app.fallback_locale is
     * Italian, the reference language, and does not change.
     */
    public static function forDelivery(?string $locale): string
    {
        if ($locale !== null && self::isWritten($locale)) {
            return $locale;
        }

        $reference = config()->string('app.fallback_locale');

        return self::isWritten($reference) ? $reference : 'it';
    }

    /**
     * Whether the language has its translations: two lowercase letters only, since the value
     * becomes part of a file name.
     */
    private static function isWritten(string $locale): bool
    {
        return preg_match('/^[a-z]{2}$/', $locale) === 1 && is_file(lang_path("{$locale}.json"));
    }
}
