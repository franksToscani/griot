<?php

namespace App\Profile;

use App\Models\Provider;
use Illuminate\Http\Request;

/**
 * The guide to the first steps of a provider who is not published yet (#53, docs/accessibility.md,
 * « Guida ai primi passi »): four steps on the pages that already exist, never a page of its own,
 * never a redirect, never an order to follow. Read only: every state comes from the data, through
 * PublishingRequirements, so nothing is stored and nothing can disagree with the database.
 *
 * 1. business (provider.edit): done with the name and the city, which a provider always has;
 *    optional, not needed to publish (bio, contacts, photos are extras).
 * 2. services (provider.services.index): at least one active service.
 * 3. availability (provider.availability.index): at least one time window.
 * 4. publish (dashboard): done once published, and then the guide is gone.
 */
final class SetupSteps
{
    public const STEPS = ['business', 'services', 'availability', 'publish'];

    // The routes of the four steps, by key: the pages that show the guide.
    public const ROUTES = [
        'business' => 'provider.edit',
        'services' => 'provider.services.index',
        'availability' => 'provider.availability.index',
        'publish' => 'dashboard',
    ];

    // The same routes, as a list for routeIs().
    public const PAGES = ['provider.edit', 'provider.services.index', 'provider.availability.index', 'dashboard'];

    // Where the memo lives: the request itself, so it never outlives it (a test with two requests, a
    // queue worker), and the dashboard does not ask the requirements twice.
    private const MEMO = 'setup_steps.missing';

    public function __construct(
        private PublishingRequirements $requirements,
    ) {}

    /**
     * The missing requirements of the provider, asked once per request (PublishingRequirements
     * itself is never cached: PublishProvider asks it again under its lock).
     *
     * @return list<'name'|'city'|'service'|'availability'>
     */
    public function missing(Request $request, Provider $provider): array
    {
        /** @var array<int, list<'name'|'city'|'service'|'availability'>> $memo */
        $memo = $request->attributes->get(self::MEMO, []);

        if (! array_key_exists($provider->id, $memo)) {
            $memo[$provider->id] = $this->requirements->missing($provider);
            $request->attributes->set(self::MEMO, $memo);
        }

        return $memo[$provider->id];
    }

    /**
     * The steps of an unpublished provider, null once published. "current" is the first step not
     * done among services, availability and publish: the one to do next.
     *
     * @return array{current: string, steps: list<array{key: string, state: 'done'|'todo', optional: bool, url: string}>}|null
     */
    public function for(Request $request, Provider $provider): ?array
    {
        if ($provider->is_published) {
            return null;
        }

        $missing = $this->missing($request, $provider);
        $done = [
            'business' => ! in_array('name', $missing, true) && ! in_array('city', $missing, true),
            'services' => ! in_array('service', $missing, true),
            'availability' => ! in_array('availability', $missing, true),
            // Shown to unpublished providers only.
            'publish' => false,
        ];

        $steps = array_map(fn (string $key) => [
            'key' => $key,
            'state' => $done[$key] ? 'done' : 'todo',
            'optional' => $key === 'business',
            'url' => route(self::ROUTES[$key], absolute: false),
        ], self::STEPS);

        $current = 'publish';
        foreach (['services', 'availability'] as $key) {
            if (! $done[$key]) {
                $current = $key;
                break;
            }
        }

        return ['current' => $current, 'steps' => $steps];
    }
}
