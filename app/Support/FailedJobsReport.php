<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The hourly warning about the failed jobs (issue #63, docs/deploy.md): how many rows of failed_jobs
 * are new since the last check, written once in the log with Log::error, so that a failed email is
 * seen in the logs of the hosting.
 *
 * Never a personal datum: the payload holds the customer's email for the answers to the bookings
 * (issue #186), so the log gets only the number, the class of each job (the "displayName" of the
 * payload, read and dropped) and the first and last time of failure. Never the payload, the
 * exception or the uuid.
 *
 * The last id seen is kept in the cache (the database in production): the same code on SQLite,
 * MySQL and PostgreSQL, no raw SQL.
 * - First run, or the cache emptied: every row is new, so failures not reported yet are not lost.
 * - queue:prune-failed deletes old rows but the ids go on: the last id seen stays valid.
 * - The table emptied and its ids started again (the highest id is below the last one seen): every
 *   row is new again.
 */
final class FailedJobsReport
{
    /**
     * The cache key of the last id seen.
     */
    public const CACHE_KEY = 'failed-jobs-report:last-id';

    /**
     * The message of the log, without any value of a job in it.
     */
    public const MESSAGE = 'New failed jobs since the last check.';

    /**
     * The rows read at a time.
     */
    private const CHUNK = 200;

    public function __construct(
        private Cache $cache,
    ) {}

    /**
     * Report the new failed jobs, if any, and remember the last id seen.
     *
     * @return int how many new failed jobs were reported
     */
    public function run(): int
    {
        $table = DB::table(config()->string('queue.failed.table'));
        $highest = (int) $table->clone()->max('id');
        $lastSeen = $this->lastSeen();

        // First run, or the table started again: every row is new.
        if ($lastSeen === null || $highest < $lastSeen) {
            $lastSeen = 0;
        }

        $count = 0;
        $classes = [];
        $first = null;
        $last = null;
        $lastRead = 0;

        $table->clone()
            ->where('id', '>', $lastSeen)
            ->orderBy('id')
            ->select(['id', 'payload', 'failed_at'])
            ->chunkById(self::CHUNK, function ($rows) use (&$count, &$classes, &$first, &$last, &$lastRead): void {
                foreach ($rows as $row) {
                    $count++;
                    $lastRead = (int) $row->id;
                    $class = self::jobClass($row->payload);
                    $classes[$class] = ($classes[$class] ?? 0) + 1;
                    $failedAt = (string) $row->failed_at;
                    $first = $first === null || $failedAt < $first ? $failedAt : $first;
                    $last = $last === null || $failedAt > $last ? $failedAt : $last;
                }
            });

        // A row written while this run was reading is counted now, and not again next time.
        $this->cache->forever(self::CACHE_KEY, max($highest, $lastRead));

        if ($count > 0) {
            ksort($classes);

            Log::error(self::MESSAGE, [
                'count' => $count,
                'jobs' => $classes,
                'first_failed_at' => $first,
                'last_failed_at' => $last,
            ]);
        }

        return $count;
    }

    /**
     * The last id seen by a previous run, or null on the first one.
     */
    private function lastSeen(): ?int
    {
        $value = $this->cache->get(self::CACHE_KEY);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The class of the job, read from the payload and nothing else: "displayName", such as
     * "App\Notifications\NewBookingNotification". A payload that cannot be read gives "unknown".
     */
    private static function jobClass(mixed $payload): string
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : null;
        $name = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;

        return is_string($name) && preg_match('/^[A-Za-z0-9_\\\\]+$/', $name) === 1 ? $name : 'unknown';
    }
}
