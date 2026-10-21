<?php

namespace App\Profile;

use App\Models\Provider;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a provider, only with the minimum requirements, or takes it off, always. Both in
 * one class: they are the two sides of the same switch. They never touch slug, avatar_path or
 * the profile fields.
 */
final class PublishProvider
{
    public function __construct(
        private PublishingRequirements $requirements,
    ) {}

    /**
     * Publish the provider. Already published: nothing is written.
     *
     * @throws CannotPublish when some requirements are missing
     */
    public function publish(Provider $provider): void
    {
        DB::transaction(function () use ($provider): void {
            // This MUST be the first statement, and from here on only $locked is used, never the
            // model received: it may be old. A service deactivated at the same moment must not let
            // an incomplete profile be published; the requirements are read after the lock, on
            // the data as it is now.
            $locked = Provider::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();

            $missing = $this->requirements->missing($locked);

            if ($missing !== []) {
                throw CannotPublish::because($locked, $missing);
            }

            if ($locked->is_published) {
                return;
            }

            // Not fillable: assigned on its own.
            $locked->is_published = true;
            $locked->save();
        });
    }

    /**
     * Take the provider off: always allowed, without checking the requirements. Already off:
     * nothing is written.
     */
    public function unpublish(Provider $provider): void
    {
        DB::transaction(function () use ($provider): void {
            // Read again like publish(), so that the decision is taken on the current row.
            $locked = Provider::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_published) {
                return;
            }

            $locked->is_published = false;
            $locked->save();
        });
    }
}
