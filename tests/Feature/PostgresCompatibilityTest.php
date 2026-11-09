<?php

use App\Models\Booking;
use App\Models\Category;
use App\Profile\ForeignKeyViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * What changes with PostgreSQL (issue #57, docs/ci.md): the same suite runs on SQLite here and on
 * PostgreSQL in the tests-postgres job of the CI.
 */

/**
 * A QueryException as the driver gives it: SQLSTATE, code of the driver, message.
 *
 * @param  array{0: string, 1: int|string|null, 2: string}  $errorInfo
 */
function pgcError(array $errorInfo): QueryException
{
    $pdo = new PDOException($errorInfo[2]);
    $pdo->errorInfo = $errorInfo;

    return new QueryException('testing', 'delete from "services" where "id" = ?', [1], $pdo);
}

test('a foreign key violation is told apart from the other errors on every database', function (array $errorInfo, bool $foreignKey) {
    expect(ForeignKeyViolation::from(pgcError($errorInfo)))->toBe($foreignKey);
})->with([
    'PostgreSQL, foreign key' => [['23503', 7, 'ERROR:  update or delete on table "services" violates foreign key constraint'], true],
    'PostgreSQL, unique' => [['23505', 7, 'ERROR:  duplicate key value violates unique constraint'], false],
    'PostgreSQL, not null' => [['23502', 7, 'ERROR:  null value in column violates not-null constraint'], false],
    'MySQL, foreign key' => [['23000', 1451, 'Cannot delete or update a parent row: a foreign key constraint fails'], true],
    'MySQL, unique' => [['23000', 1062, 'Duplicate entry'], false],
    'SQLite, foreign key' => [['23000', 19, 'FOREIGN KEY constraint failed'], true],
    'SQLite, unique' => [['23000', 19, 'UNIQUE constraint failed: services.name'], false],
    'another error' => [['42P01', 7, 'ERROR:  relation does not exist'], false],
]);

test('a real foreign key violation of the database of the tests is recognised, a unique one is not', function () {
    $booking = Booking::factory()->create();

    // Inside a transaction, as ManageService does: on PostgreSQL a failed statement spoils the
    // whole transaction, and the rollback to the savepoint lets the test go on.
    $foreignKey = null;
    try {
        DB::transaction(fn () => $booking->service->delete());
    } catch (QueryException $exception) {
        $foreignKey = $exception;
    }

    $unique = null;
    try {
        DB::transaction(fn () => Category::factory()->create(['slug' => $booking->service->category->slug]));
    } catch (QueryException $exception) {
        $unique = $exception;
    }

    expect($foreignKey)->toBeInstanceOf(QueryException::class)
        ->and(ForeignKeyViolation::from($foreignKey))->toBeTrue()
        ->and($unique)->toBeInstanceOf(QueryException::class)
        ->and(ForeignKeyViolation::from($unique))->toBeFalse();
    $this->assertModelExists($booking->service);
});
