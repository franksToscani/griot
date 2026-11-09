<?php

namespace Database\Seeders;

use RuntimeException;

/**
 * The development data never reaches a real database (issue #59, docs/production.md): example
 * accounts with a known password (ProviderSeeder::DEV_PASSWORD), invented providers and bookings.
 * DatabaseSeeder, ProviderSeeder and BookingSeeder call it before writing anything. Only the
 * categories (CategorySeeder) are real data, seeded on their own in production.
 */
final class DevelopmentOnly
{
    /**
     * The environments where the development data may be written.
     */
    public const ENVIRONMENTS = ['local', 'testing'];

    /**
     * @throws RuntimeException outside local development and the tests
     */
    public static function ensure(string $seeder): void
    {
        $environment = (string) app()->environment();

        if (! in_array($environment, self::ENVIRONMENTS, true)) {
            throw new RuntimeException("{$seeder} writes development data (example accounts with a known password) and runs only in ".implode(' or ', self::ENVIRONMENTS).", not in \"{$environment}\". In production seed the categories only: php artisan db:seed --class=CategorySeeder --force");
        }
    }
}
