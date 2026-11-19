<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * How two kinds of exceptions are written in the log (issue #186, bootstrap/app.php): never their
 * message, which can hold the customers' data.
 *
 * - A database error (QueryException): its message holds the SQL with the values of the query. An
 *   error while a booking is saved would write the customer's name, phone, email and notes, and
 *   the secret code of the private link (public_token is in the insert). PostgreSQL also writes a
 *   duplicate value in its own message. The log gets the class, the SQLSTATE and the connection.
 * - A mail transport error (Symfony Mailer): an SMTP answer usually names the recipient's address.
 *   The log gets the class and the code (the SMTP code, when there is one).
 *
 * Both also get the file and the line of the application's code that led to the error (the first
 * frame of the trace under app/), or of the exception when there is none: enough to find the
 * place, never a value. Every other exception is reported by Laravel as before, message included.
 */
final class PrivateExceptionLog
{
    public static function query(QueryException $exception): void
    {
        Log::error('Database error (details hidden: they can hold personal data).', [
            'class' => $exception::class,
            'sqlstate' => (string) ($exception->errorInfo[0] ?? $exception->getCode()),
            'connection' => $exception->getConnectionName(),
            ...self::origin($exception),
        ]);
    }

    public static function mail(TransportExceptionInterface $exception): void
    {
        Log::error('Mail transport error (details hidden: they can hold an email address).', [
            'class' => $exception::class,
            'code' => $exception->getCode(),
            ...self::origin($exception),
        ]);
    }

    /**
     * The file and the line of the first frame in the application's code (app/), relative to the
     * project; the exception's own place when none is there.
     *
     * @return array{file: string, line: int}
     */
    private static function origin(Throwable $exception): array
    {
        $frames = [['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$exception->getTrace()];
        $app = app_path().DIRECTORY_SEPARATOR;

        foreach ($frames as $frame) {
            if (isset($frame['file'], $frame['line']) && str_starts_with($frame['file'], $app)) {
                return ['file' => self::relative($frame['file']), 'line' => $frame['line']];
            }
        }

        return ['file' => self::relative($exception->getFile()), 'line' => $exception->getLine()];
    }

    private static function relative(string $file): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
