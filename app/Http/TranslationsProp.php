<?php

namespace App\Http;

use Inertia\OnceProp;

/**
 * The translations of the page's language (lang/{locale}.json), shared with every page as a
 * "once" prop of Inertia (issue #178): the browser gets them on the first visit and keeps them;
 * the next visits send their key in X-Inertia-Except-Once-Props and the server leaves them out.
 *
 * The key is "translations.{locale}.{hash}": another language (the selector, the private page of a
 * booking in its own language) or other texts (a deploy) give another key, and the translations
 * come again. It is read when the page is drawn, never before: the language can be set after
 * HandleInertiaRequests (UseBookingLocale), so a key fixed in the middleware could name the wrong
 * one, and the browser would keep the texts of another language.
 *
 * The browser puts the kept translations back into the props of each new page before "navigate",
 * so t() and pageText() read them as before (resources/js/lib/document-language.ts).
 */
final class TranslationsProp extends OnceProp
{
    public function __construct()
    {
        parent::__construct(fn (): array => self::current());
    }

    /**
     * The key of the language of the response, with the hash of its file.
     */
    public function getKey(): string
    {
        return 'translations.'.app()->getLocale().'.'.self::hash();
    }

    /**
     * Translations for the current locale, read from lang/{locale}.json.
     *
     * @return array<string, string>
     */
    public static function current(): array
    {
        $path = self::path();

        if (! is_file($path)) {
            return [];
        }

        /** @var array<string, string>|null $translations */
        $translations = json_decode((string) file_get_contents($path), true);

        return $translations ?? [];
    }

    /**
     * 16 hexadecimal characters of the file's content: the same texts, the same key.
     */
    private static function hash(): string
    {
        $path = self::path();

        return is_file($path) ? substr((string) hash_file('xxh128', $path), 0, 16) : 'none';
    }

    private static function path(): string
    {
        return lang_path(app()->getLocale().'.json');
    }
}
