<?php

namespace App\Profile;

use Illuminate\Database\QueryException;

/**
 * Tells whether a database error is a row still referenced by a foreign key (restrictOnDelete).
 * Laravel has no exception of its own for it: it is a plain QueryException. Shared by
 * ManageService and ManageOption, so the criteria live in one place.
 */
final class ForeignKeyViolation
{
    /**
     * SQLSTATE 23000 is shared by every integrity violation, so the driver tells them apart:
     * MySQL code 1451 ("Cannot delete or update a parent row"); SQLite has one code (19) for all
     * of them, so its message is read. A unique violation (MySQL 1062) is never matched.
     */
    public static function from(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];

        if (($errorInfo[0] ?? null) !== '23000') {
            return false;
        }

        return ($errorInfo[1] ?? null) === 1451
            || str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed');
    }
}
