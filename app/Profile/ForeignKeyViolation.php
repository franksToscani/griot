<?php

namespace App\Profile;

use Illuminate\Database\QueryException;

/**
 * Tells whether a database error is a row still referenced by a foreign key (restrictOnDelete).
 * Laravel has no exception of its own for it: it is a plain QueryException. Shared by
 * ManageService and ManageOption, so the criteria live in one place. The codes are those seen on
 * the real databases (docs/ci.md, "Codici SQLSTATE").
 */
final class ForeignKeyViolation
{
    /**
     * PostgreSQL has SQLSTATEs of its own: 23001 ("restrict_violation"), what a delete blocked by
     * ON DELETE RESTRICT gives, and 23503 ("foreign_key_violation"), the general one. A unique
     * violation there is 23505, never matched.
     */
    private const POSTGRES_STATES = ['23001', '23503'];

    /**
     * MySQL shares SQLSTATE 23000 among every integrity violation; its own code tells them apart:
     * 1451 "Cannot delete or update a parent row" (the delete), 1452 "Cannot add or update a child
     * row" (the insert). A unique violation (1062) is never matched.
     */
    private const MYSQL_CODES = [1451, 1452];

    public static function from(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $state = $errorInfo[0] ?? null;

        if (in_array($state, self::POSTGRES_STATES, true)) {
            return true;
        }

        if ($state !== '23000') {
            return false;
        }

        // SQLite also gives 23000, with one code (19) for every violation: its message tells.
        return in_array($errorInfo[1] ?? null, self::MYSQL_CODES, true)
            || str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed');
    }
}
