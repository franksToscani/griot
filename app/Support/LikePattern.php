<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A search with LIKE that reads the text as plain text: "%" and "_" typed by a visitor are
 * characters to find, not wildcards. The same SQL on SQLite, MySQL and PostgreSQL:
 *
 * - the escape character is "!", written in the query: a backslash would need "\\" in MySQL and
 *   "\" in SQLite and PostgreSQL, and SQLite has no escape character of its own;
 * - LOWER() on the column and on the text, so the search ignores upper and lower case on
 *   PostgreSQL too, where LIKE alone does not. SQLite lowers only the letters A-Z: there "É" and
 *   "é" stay different.
 */
final class LikePattern
{
    public const ESCAPE = '!';

    /**
     * The pattern that finds the text anywhere, in lower case, with its wildcards escaped.
     */
    public static function contains(string $text): string
    {
        $escaped = str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            mb_strtolower($text),
        );

        return '%'.$escaped.'%';
    }

    /**
     * Add "the column contains the text" to the query, with "and" or "or". The column is written
     * in the code ("providers.name"), never taken from a request: it goes into the SQL as it is.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $column
     */
    public static function whereContains(Builder $query, string $column, string $text, string $boolean = 'and'): void
    {
        $query->whereRaw("lower({$column}) like ? escape '".self::ESCAPE."'", [self::contains($text)], $boolean);
    }

    /**
     * The words of a search: separated by any space, at most $limit, the others dropped.
     *
     * @return list<string>
     */
    public static function words(string $text, int $limit): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_slice($words === false ? [] : $words, 0, $limit);
    }
}
