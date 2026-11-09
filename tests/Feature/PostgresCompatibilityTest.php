<?php

use App\Models\Booking;
use App\Models\Category;
use App\Models\Provider;
use App\Profile\ForeignKeyViolation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
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

test('every code of a row still referenced is recognised: 23000, 23001 and 23503', function () {
    // As the databases give them: SQLite and MySQL (23000), PostgreSQL with ON DELETE RESTRICT
    // (23001, seen in the tests-postgres job) and the general foreign key violation (23503).
    $errors = [
        'SQLite 23000' => ['23000', 19, 'SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed'],
        'MySQL 23000/1451' => ['23000', 1451, 'Cannot delete or update a parent row: a foreign key constraint fails'],
        'MySQL 23000/1452' => ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails'],
        'PostgreSQL 23001' => ['23001', 7, 'ERROR:  update or delete on table "services" violates RESTRICT setting of foreign key constraint'],
        'PostgreSQL 23503' => ['23503', 7, 'ERROR:  update or delete on table "services" violates foreign key constraint'],
    ];

    foreach ($errors as $name => $errorInfo) {
        expect(ForeignKeyViolation::from(pgcError($errorInfo)))->toBeTrue("Not recognised: {$name}");
    }
});

test('a foreign key violation is told apart from the other errors on every database', function (array $errorInfo, bool $foreignKey) {
    expect(ForeignKeyViolation::from(pgcError($errorInfo)))->toBe($foreignKey);
})->with([
    'PostgreSQL, restrict' => [['23001', 7, 'ERROR:  update or delete on table "services" violates RESTRICT setting of foreign key constraint'], true],
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

test('a real unique violation of the database of the tests is a UniqueConstraintViolationException', function () {
    // CreateProviderProfile and UpdateProviderProfile catch this class for a taken slug. Laravel
    // chooses it for each database (SQLite and MySQL by the message, PostgreSQL by 23505): this
    // checks it on the database the suite runs on.
    $provider = Provider::factory()->create();

    $unique = null;
    try {
        DB::transaction(fn () => Provider::factory()->create(['slug' => $provider->slug]));
    } catch (QueryException $exception) {
        $unique = $exception;
    }

    expect($unique)->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and(ForeignKeyViolation::from($unique))->toBeFalse();
});

/**
 * The tables created by the migrations without an "id" column: a primary key of another name or
 * of two columns.
 *
 * @return list<string>
 */
function pgcTablesWithoutId(): array
{
    $tables = [];

    foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
        preg_match_all("/Schema::create\('(\w+)', function \(Blueprint \\\$table\) \{(.*?)\n        \}\);/s", (string) file_get_contents($file), $creates, PREG_SET_ORDER);

        foreach ($creates as [, $table, $body]) {
            if (! str_contains($body, '->id()') && ! str_contains($body, "'id'")) {
                $tables[] = $table;
            }
        }
    }

    return $tables;
}

test('no query names an id column on a table without one', function () {
    // SQLite reads an unknown "id" as a string and says nothing; PostgreSQL refuses the query
    // (42703, column "id" does not exist), as it did on the pivot in DataRetentionTest.
    $tables = pgcTablesWithoutId();
    expect($tables)->toEqualCanonicalizing(['password_reset_tokens', 'cache', 'cache_locks', 'booking_service_option']);

    $files = [];
    foreach (['app', 'database', 'tests'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory))) as $file) {
            if ($file->getExtension() === 'php' && $file->getRealPath() !== __FILE__) {
                $files[] = $file->getRealPath();
            }
        }
    }
    expect(count($files))->toBeGreaterThan(100);

    $pattern = "/DB::table\\('(?:".implode('|', $tables).")'\\)[^;]*;/s";
    $found = [];
    foreach ($files as $path) {
        preg_match_all($pattern, (string) file_get_contents($path), $queries);

        foreach ($queries[0] as $query) {
            // A subquery on another table, such as $service->options()->select('id'), is not this
            // table's column: the expressions that start from a variable are left out.
            $own = (string) preg_replace('/\$\w+(?:->\w+(?:\([^()]*\))?)+/', '', $query);

            // "id" alone or of one of these tables; "service_options.id" in a join is another table.
            if (preg_match('/[\'"](?:(?:'.implode('|', $tables).')\.)?id[\'"]/', $own) === 1) {
                $found[] = str_replace(base_path().'/', '', $path).': '.$query;
            }
        }
    }

    expect($found)->toBe([]);
});
