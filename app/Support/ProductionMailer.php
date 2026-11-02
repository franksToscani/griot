<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * In production the emails must really leave. With the "log" mailer they would be written, whole,
 * in the log (the customer's address and private link included); with "array" they would be lost.
 *
 * The check runs only where an email can leave: every web request, and the commands that send the
 * queued emails or start the scheduled ones (SENDING_COMMANDS). AppServiceProvider calls it. Every
 * other command starts as usual: "composer install" runs package:discover before any .env exists,
 * when Laravel's defaults are APP_ENV=production and MAIL_MAILER=log (a fresh clone, the CI), and
 * migrate, config:cache or the build must work before the mailer is set. Local development and the
 * tests are left alone.
 */
final class ProductionMailer
{
    /**
     * The mailers that never send anything.
     */
    private const NOT_SENDING = ['log', 'array'];

    /**
     * The commands that can send emails: the queue workers and the scheduler.
     */
    public const SENDING_COMMANDS = ['queue:work', 'queue:listen', 'schedule:run', 'schedule:work'];

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

    /**
     * The same check before a command starts, for the commands that can send emails only.
     */
    public static function ensureItSendsBefore(string $command, Application $app): void
    {
        if (in_array($command, self::SENDING_COMMANDS, true)) {
            self::ensureItSends($app);
        }
    }
}
