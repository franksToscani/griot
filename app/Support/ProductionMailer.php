<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * In production the emails must really leave. With the "log" mailer they would be written, whole,
 * in the log (the customer's address and private link included); with "array" they would be lost.
 * AppServiceProvider calls this first: the application refuses to start, every request and every
 * command, until a real mailer is set. Local development and the tests are left alone.
 */
final class ProductionMailer
{
    /**
     * The mailers that never send anything.
     */
    private const NOT_SENDING = ['log', 'array'];

    /**
     * Throw when the application runs in production with a mailer that does not send. The message
     * names the mailer only: no host, user or password.
     */
    public static function ensureItSends(Application $app): void
    {
        if (! $app->isProduction()) {
            return;
        }

        $mailer = $app->make('config')->get('mail.default');

        if (in_array($mailer, self::NOT_SENDING, true)) {
            throw new RuntimeException("The default mailer is \"{$mailer}\" in production: the emails would not be sent, and with \"log\" the customers' data would end up in the log. Set MAIL_MAILER to a real mailer.");
        }
    }
}
