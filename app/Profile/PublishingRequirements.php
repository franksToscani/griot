<?php

namespace App\Profile;

use App\Models\Provider;

/**
 * What a provider needs before it can be published. This is the only place that knows the
 * requirements: the rest of the application asks missing() and shows the codes.
 *
 * The roadmap says "at least one service"; here it is at least one ACTIVE service, on purpose:
 * an inactive service is not shown on the page and cannot be booked, so it would leave the
 * customers with nothing to book.
 */
final class PublishingRequirements
{
    /**
     * The codes of the missing requirements, always in this order: name, city, service,
     * availability. Empty when the provider can be published. Two light queries (exists()), even
     * if the relations are already loaded: always the same answer, from the database.
     *
     * @return list<'name'|'city'|'service'|'availability'>
     */
    public function missing(Provider $provider): array
    {
        $missing = [];

        if (trim((string) $provider->name) === '') {
            $missing[] = 'name';
        }

        if (trim((string) $provider->city) === '') {
            $missing[] = 'city';
        }

        if (! $provider->services()->where('is_active', true)->exists()) {
            $missing[] = 'service';
        }

        if (! $provider->availabilities()->exists()) {
            $missing[] = 'availability';
        }

        return $missing;
    }
}
