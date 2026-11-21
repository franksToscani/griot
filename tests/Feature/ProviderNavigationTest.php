<?php

use App\Booking\AnonymizeOldBookings;
use App\Booking\BookingTransitions;
use App\Enums\BookingStatus;
use App\Http\Middleware\ShareProviderNavigation;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

// The provider's sidebar (components/app-sidebar.tsx, docs/bookings.md): every section of the area
// as a link, and next to "Prenotazioni" the number of the requests waiting for an answer
// (Booking::awaitingAnswer()), shared as provider_nav by ShareProviderNavigation with the private
// area and the settings only. There is no JavaScript runner: the browser side is read from the
// sources.

// "Now" is Monday 16 November 2026, 08:00 UTC.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', 'UTC'));
    app()->setLocale('it');
});

function pnSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

/**
 * A booking of the provider, starting at $start (UTC).
 *
 * @param  array<string, mixed>  $attributes
 */
function pnBooking(Provider $provider, string $start, BookingStatus $status = BookingStatus::Pending, array $attributes = []): Booking
{
    $starts = CarbonImmutable::parse($start, 'UTC');

    return Booking::factory()->for($provider)->for(Service::factory()->for($provider))->create([
        'status' => $status,
        'starts_at' => $starts,
        'ends_at' => $starts->addHour(),
        ...$attributes,
    ]);
}

/**
 * The bookings of the dataset of awaitingAnswer(): only the first two wait for an answer.
 *
 * @return array<string, Booking>
 */
function pnDataset(Provider $provider): array
{
    $bookings = [
        'pending, tomorrow' => pnBooking($provider, '2026-11-17 09:00'),
        'pending, in a minute' => pnBooking($provider, '2026-11-16 08:01'),
        'pending, starting now' => pnBooking($provider, '2026-11-16 08:00'),
        'pending, started an hour ago' => pnBooking($provider, '2026-11-16 07:00'),
        'pending, last month' => pnBooking($provider, '2026-10-10 09:00'),
        'confirmed, tomorrow' => pnBooking($provider, '2026-11-17 11:00', BookingStatus::Confirmed),
        'cancelled, tomorrow' => pnBooking($provider, '2026-11-17 13:00', BookingStatus::Cancelled),
        'pending, anonymous' => pnBooking($provider, '2026-01-10 09:00'),
    ];
    app(AnonymizeOldBookings::class)->run(CarbonImmutable::parse('2026-02-01', 'UTC'));
    $bookings['pending, anonymous']->refresh();
    expect($bookings['pending, anonymous']->isAnonymized())->toBeTrue();

    return $bookings;
}

// ---------------------------------------------------------------------------------------------
// The rule: awaitingAnswer() is canConfirm() for the database
// ---------------------------------------------------------------------------------------------

test('awaitingAnswer() takes exactly the bookings that BookingTransitions::canConfirm() allows', function () {
    $provider = Provider::factory()->create();
    $bookings = pnDataset($provider);
    // An anonymous request in the future cannot happen in normal use: forced, to test the column.
    $future = pnBooking($provider, '2026-11-18 09:00');
    DB::table('bookings')->where('id', $future->id)->update(['anonymized_at' => CarbonImmutable::now()]);
    $bookings['pending, tomorrow, anonymous (forced)'] = $future->refresh();

    $transitions = app(BookingTransitions::class);
    foreach ($bookings as $name => $booking) {
        expect(Booking::query()->awaitingAnswer()->whereKey($booking->id)->exists())
            ->toBe($transitions->canConfirm($booking), "Not the same for: {$name}");
    }

    expect(Booking::query()->awaitingAnswer()->pluck('id')->sort()->values()->all())
        ->toBe([$bookings['pending, tomorrow']->id, $bookings['pending, in a minute']->id]);
});

test('the reminders start from awaitingAnswer(): one rule in one place', function () {
    $model = (string) file_get_contents(app_path('Models/Booking.php'));

    expect($model)->toMatch('/function awaitingReminder\([^)]*\): void\s*\{[^}]*->awaitingAnswer\(\)/');
});

// ---------------------------------------------------------------------------------------------
// The shared prop
// ---------------------------------------------------------------------------------------------

test('provider_nav counts the provider\'s own requests waiting for an answer, and gives the public slug', function () {
    $provider = Provider::factory()->published()->create();
    pnDataset($provider);
    // Another provider's request: never counted.
    pnBooking(Provider::factory()->create(), '2026-11-17 09:00');

    $this->actingAs($provider->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('provider_nav.pending_requests', 2)
            ->where('provider_nav.public_slug', $provider->slug));
});

test('an unpublished profile has no public slug, and no requests give 0', function () {
    $provider = Provider::factory()->create(['is_published' => false]);

    $this->actingAs($provider->user)
        ->get(route('provider.services.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('provider_nav.pending_requests', 0)
            ->where('provider_nav.public_slug', null));
});

test('every page of the private area and of the settings has it', function (string $route) {
    $provider = Provider::factory()->published()->create();
    pnBooking($provider, '2026-11-17 09:00');

    $this->actingAs($provider->user)
        ->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('provider_nav.pending_requests', 1));
})->with(['dashboard', 'provider.bookings.index', 'provider.bookings.create', 'provider.services.index', 'provider.availability.index', 'provider.edit', 'provider.preview', 'profile.edit', 'appearance.edit']);

test('a user without a provider gets null', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('provider_nav', null));
});

test('the public pages never have it, not even for a logged-in provider after a page of the area', function (string $page) {
    $provider = Provider::factory()->published()->create();
    $booking = pnBooking($provider, '2026-11-17 09:00');
    $this->actingAs($provider->user);

    // A page of the area first: Inertia keeps the shared props for the whole process.
    $this->get(route('dashboard'))->assertInertia(fn (Assert $assert) => $assert->has('provider_nav'));

    $url = match ($page) {
        'providers.show' => route('providers.show', $provider->slug),
        'bookings.show' => route('bookings.show', $booking->public_token),
        default => route($page),
    };

    $this->get($url)->assertOk()->assertInertia(fn (Assert $assert) => $assert->missing('provider_nav'));
})->with(['home', 'providers.index', 'providers.show', 'privacy', 'terms', 'bookings.show']);

test('the pages of the area and the settings have the middleware; the public ones do not', function () {
    foreach (['dashboard', 'provider.bookings.index', 'provider.bookings.show', 'provider.edit', 'profile.edit', 'security.edit'] as $name) {
        expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain(ShareProviderNavigation::class);
    }

    foreach (['home', 'providers.index', 'providers.show', 'bookings.show', 'privacy', 'terms', 'login'] as $name) {
        expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->not->toContain(ShareProviderNavigation::class);
    }
});

test('a partial reload of the bookings with provider_nav gives the new number', function () {
    $provider = Provider::factory()->create();
    pnBooking($provider, '2026-11-17 09:00');
    $response = $this->actingAs($provider->user)->get(route('provider.bookings.index'));

    // A new request, made after the page was shown.
    pnBooking($provider, '2026-11-18 09:00');

    $response->assertInertia(fn (Assert $page) => $page
        ->where('provider_nav.pending_requests', 1)
        ->reloadOnly(['bookings', 'provider_nav'], fn (Assert $reload) => $reload
            ->where('provider_nav.pending_requests', 2)
            ->has('bookings')
            ->missing('translations')));
});

test('a partial reload without provider_nav does not compute it', function () {
    $provider = Provider::factory()->create();
    $response = $this->actingAs($provider->user)->get(route('provider.bookings.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->reloadOnly(['bookings'], fn (Assert $reload) => $reload->missing('provider_nav')));
});

test('one COUNT only, and nothing more than it on the pages that read the provider already', function (string $route, int $before) {
    $provider = Provider::factory()->published()->create();
    pnBooking($provider, '2026-11-17 09:00');
    $user = $provider->user;

    $this->actingAs($user);
    DB::enableQueryLog();
    $this->get(route($route))->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $navCounts = $queries->filter(fn (string $sql) => str_contains($sql, 'count(*)') && str_contains($sql, 'anonymized_at'));

    // The COUNT of the sidebar: one, with the condition of awaitingAnswer().
    expect($navCounts)->toHaveCount(1)
        // Before this change: $before queries; now one more, the COUNT.
        ->and($queries)->toHaveCount($before + 1);
})->with([
    'the dashboard' => ['dashboard', 3],
    'the bookings' => ['provider.bookings.index', 5],
    'the settings' => ['profile.edit', 2],
]);

// ---------------------------------------------------------------------------------------------
// The sidebar, read from the sources
// ---------------------------------------------------------------------------------------------

test('the sidebar has the sections in their groups and in their order', function () {
    $sidebar = pnSource('components/app-sidebar.tsx');

    $order = ['nav.overview', 'nav.group.agenda', 'provider.area.bookings', 'nav.group.offer', 'provider.area.services', 'provider.area.availability', 'nav.group.showcase', 'provider.area.edit_profile', 'provider.area.preview', 'provider.area.public_page'];
    $positions = array_map(fn (string $key) => strpos($sidebar, "'{$key}'"), $order);

    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());

    // NavUser and the language stay at the foot.
    expect($sidebar)->toMatch('/<SidebarFooter>[\s\S]*<LocaleSwitcher[\s\S]*<NavUser \/>[\s\S]*<\/SidebarFooter>/');
});

test('each link of the sidebar is an existing route', function () {
    $sidebar = pnSource('components/app-sidebar.tsx');

    $links = [
        'href: dashboard()' => 'dashboard',
        'href: bookingsIndex()' => 'provider.bookings.index',
        'href: servicesIndex()' => 'provider.services.index',
        'href: availabilityIndex()' => 'provider.availability.index',
        'href: edit()' => 'provider.edit',
        'href: preview()' => 'provider.preview',
        'href: show(nav.public_slug)' => 'providers.show',
    ];

    foreach ($links as $code => $route) {
        expect($sidebar)->toContain($code)
            ->and(Route::has($route))->toBeTrue("Missing route: {$route}");
    }

    expect($sidebar)
        ->toContain("import { dashboard } from '@/routes';")
        ->toContain("import { edit, preview } from '@/routes/provider';")
        ->toContain("import { index as availabilityIndex } from '@/routes/provider/availability';")
        ->toContain("import { index as bookingsIndex } from '@/routes/provider/bookings';")
        ->toContain("import { index as servicesIndex } from '@/routes/provider/services';")
        ->toContain("import { show } from '@/routes/providers';");
});

test('"Pagina pubblica" only with a published profile; "Anteprima" always', function () {
    $sidebar = pnSource('components/app-sidebar.tsx');

    expect($sidebar)->toMatch("/\.\.\.\(nav\?\.public_slug\s*\?\s*\[\s*\{\s*titleKey: 'provider\.area\.public_page',\s*href: show\(nav\.public_slug\),/")
        ->and($sidebar)->toMatch("/\{\s*titleKey: 'provider\.area\.preview',\s*href: preview\(\),\s*icon: Eye,\s*\},/");
});

test('the active link has aria-current, by whole segments of the address', function () {
    $nav = pnSource('components/nav-main.tsx');

    expect($nav)->toMatch("/aria-current=\\{\\s*active \\? 'page' : undefined\\s*\\}/")
        ->toContain('isActive={active}')
        ->toContain('return currentUrl === path || currentUrl.startsWith(`${path}/`);')
        ->toContain('tooltip={{ children: t(item.titleKey) }}')
        ->not->toContain('aria-label');
});

test('the number is seen, and read by screen readers with its plural; hidden at 0', function () {
    $nav = pnSource('components/nav-main.tsx');
    $plural = pnSource('lib/pending-requests.ts');

    expect($nav)->toContain('item.badge !== undefined && item.badge.value > 0')
        ->toMatch('/<span className="sr-only">\s*\{`, \$\{badge\.text\}`\}\s*<\/span>/')
        ->toMatch('/<SidebarMenuBadge\s+aria-hidden="true"/')
        ->toMatch('/aria-hidden="true"\s+className="[^"]*group-data-\[collapsible=icon\]:block"/');
    expect($plural)->toMatch("/count === 1\s*\?\s*t\('provider\.nav\.pending_one'\)\s*:\s*t\('provider\.nav\.pending', \{ count \}\)/");
    expect(pnSource('components/app-sidebar.tsx'))->toContain('text: pendingRequestsText(t, pending),');
});

test('the dashboard has no section buttons any more, and a summary that leads to the pending requests', function () {
    $dashboard = pnSource('pages/dashboard.tsx');
    // The one exception, written in docs/bookings.md (« Barra dell'area del professionista »): for a
    // provider not published, step 4 of the guide to the first steps (#53, SetupStepsTest) has the
    // preview, inside PublishSection only. The guide's own links live in components/setup-steps.tsx.
    // No section buttons anywhere else: the sidebar has them.
    $publish = (int) strpos($dashboard, 'function PublishSection(');
    $after = (int) strpos($dashboard, "\n}\n", $publish);
    expect($publish)->toBeGreaterThan(0)->and($after)->toBeGreaterThan($publish);
    $section = substr($dashboard, $publish, $after - $publish);
    $outside = (string) preg_replace('/^import [\s\S]*?;\n/m', '', substr($dashboard, 0, $publish).substr($dashboard, $after));

    foreach (['edit()', 'servicesIndex', 'availabilityIndex', 'preview()', 'show(provider.slug)', "t('provider.area.edit_profile')", "t('provider.area.services')", "t('provider.area.availability')", "t('provider.area.bookings')", "t('provider.area.preview')", "t('provider.area.public_page')"] as $gone) {
        expect($outside)->not->toContain($gone);
    }
    expect($section)->toContain('<Link href={preview()}>')
        ->not->toContain('servicesIndex')
        ->not->toContain('availabilityIndex')
        ->not->toContain('edit()')
        ->not->toContain('show(provider.slug)')
        ->not->toContain("t('provider.area.public_page')");

    expect($dashboard)->toContain("href={bookingsIndex({ query: { status: 'pending' } })}")
        ->toContain("t('provider.area.pending_none')")
        ->toContain("t('provider.area.pending_one')")
        ->toContain("t('provider.area.pending', { count: pending })")
        // The publishing stays as it was.
        ->toContain('<PublishSection publishing={publishing} />')
        ->toContain('<UnpublishButton />');
});

test('the pages that update by themselves ask provider_nav too', function () {
    expect(pnSource('lib/live-pages.ts'))
        ->toContain("'provider/bookings/index': ['bookings', 'provider_nav'],")
        ->toContain("'provider/bookings/show': ['booking', 'provider_nav'],")
        ->toContain("'bookings/show': ['booking', 'can_cancel'],");
    expect(pnSource('pages/provider/bookings/index.tsx'))->toContain("useLiveProps(['bookings', 'provider_nav'], {");
    expect(pnSource('pages/provider/bookings/show.tsx'))->toContain("useLiveProps(['booking', 'provider_nav'], {");
});

test('the language selector opens upwards when needed; the other selects keep the kit\'s choice', function () {
    expect(pnSource('components/locale-switcher.tsx'))->toContain('<SelectContent avoidCollisions collisionPadding={8}>');
    expect(pnSource('components/ui/select.tsx'))->toContain('avoidCollisions={false}');

    foreach (['components/provider-sort.tsx', 'components/provider-filters.tsx', 'components/provider-filters-panel.tsx'] as $path) {
        if (is_file(resource_path("js/{$path}"))) {
            expect(pnSource($path))->not->toContain('avoidCollisions');
        }
    }
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts in the three languages', function () {
    $expected = [
        'it' => ['Panoramica', 'Agenda', 'La mia offerta', 'Vetrina', '1 richiesta in attesa', '3 richieste in attesa', 'Nessuna richiesta in attesa di risposta.', 'Hai 1 richiesta in attesa di risposta', 'Hai 3 richieste in attesa di risposta'],
        'fr' => ['Aperçu', 'Agenda', 'Mon offre', 'Vitrine', '1 demande en attente', '3 demandes en attente', 'Aucune demande en attente de réponse.', 'Vous avez 1 demande en attente de réponse', 'Vous avez 3 demandes en attente de réponse'],
        'en' => ['Overview', 'Schedule', 'My offer', 'Showcase', '1 pending request', '3 pending requests', 'No requests waiting for an answer.', 'You have 1 request waiting for an answer', 'You have 3 requests waiting for an answer'],
    ];
    $keys = ['nav.overview', 'nav.group.agenda', 'nav.group.offer', 'nav.group.showcase', 'provider.nav.pending_one', 'provider.nav.pending', 'provider.area.pending_none', 'provider.area.pending_one', 'provider.area.pending'];

    foreach ($expected as $locale => $texts) {
        foreach ($keys as $index => $key) {
            expect(__($key, ['count' => 3], $locale))->toBe($texts[$index]);
        }
    }
});
