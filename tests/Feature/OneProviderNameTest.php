<?php

use App\Models\Provider;
use App\Models\User;
use App\Profile\SyncAccountName;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// One name for a provider, the business one (docs/provider-first-steps.md, « Un solo nome »): the
// menu shows it, the settings page shows it with a link to the profile and never changes it, the
// account's name follows it one way only, and providers:sync-account-names aligns the accounts
// saved before. A user without a provider keeps the field "Nome" of the settings.

/**
 * A provider whose account has another name, as before "one name".
 */
function opnProvider(string $business = 'Salone Awa', string $account = 'Persona Diversa'): Provider
{
    $user = User::factory()->create(['name' => $account, 'email' => 'awa@example.test']);

    return Provider::factory()->for($user)->create(['name' => $business, 'slug' => 'salone-awa', 'city' => 'Bologna']);
}

/**
 * The profile form, valid, with another business name.
 *
 * @return array<string, mixed>
 */
function opnForm(string $name): array
{
    return ['name' => $name, 'city' => 'Bologna', 'bio' => '', 'whatsapp' => '', 'instagram' => '', 'slug' => 'salone-awa'];
}

/**
 * The shared auth.user of a page, read as a new request would (a fresh user, no relation kept).
 *
 * @return array<string, mixed>
 */
function opnSharedUser(User $user, string $route): array
{
    $shared = [];
    test()->actingAs($user->fresh())->get(route($route))->assertOk()
        ->assertInertia(function (Assert $page) use (&$shared) {
            $shared = $page->toArray()['props']['auth']['user'];
        });

    return $shared;
}

/**
 * The queries of one page that read the provider of the account.
 */
function opnProviderQueries(User $user, string $route): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->actingAs($user->fresh())->get(route($route))->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return count(array_filter($log, fn (array $query) => preg_match('/from "providers" where "providers"\."user_id" = \?/', $query['query']) === 1));
}

/**
 * @return array{output: string, code: int}
 */
function opnSync(bool $force = false): array
{
    $code = Artisan::call('providers:sync-account-names', $force ? ['--force' => true] : []);

    return ['output' => Artisan::output(), 'code' => $code];
}

// ---------------------------------------------------------------------------------------------
// The name shown
// ---------------------------------------------------------------------------------------------

test('the menu shows the business name, and a new business name at the next request', function () {
    $provider = opnProvider();
    $user = $provider->user;

    foreach (['dashboard', 'provider.edit', 'profile.edit'] as $route) {
        expect(opnSharedUser($user, $route)['name'])->toBe('Salone Awa', $route);
    }

    $this->actingAs($user->fresh())->put(route('provider.update'), opnForm('Salone Nuovo'))->assertSessionHasNoErrors();

    foreach (['dashboard', 'provider.edit', 'profile.edit'] as $route) {
        expect(opnSharedUser($user, $route)['name'])->toBe('Salone Nuovo', $route);
    }
});

test('the name in the menu costs no query: the provider is read once, where the page already reads it', function () {
    $user = opnProvider()->user;

    foreach (['dashboard', 'provider.edit', 'profile.edit'] as $route) {
        expect(opnProviderQueries($user, $route))->toBe(1, $route);
    }
    // A public page does not read the provider: the account's name, which the profile keeps equal.
    expect(opnProviderQueries($user, 'home'))->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// One way: the business name to the account
// ---------------------------------------------------------------------------------------------

test('saving the profile gives the account the business name; the account never changes the business', function () {
    $provider = opnProvider();

    $this->actingAs($provider->user)->put(route('provider.update'), opnForm('Salone Nuovo'))->assertSessionHasNoErrors();
    expect($provider->user->fresh()->name)->toBe('Salone Nuovo')
        ->and($provider->fresh()->name)->toBe('Salone Nuovo');

    // The other way round never happens: a new account name leaves the business as it is.
    $provider->user->fresh()->update(['name' => 'Altro Nome']);
    expect($provider->fresh()->name)->toBe('Salone Nuovo');
});

test('the first profile gives the account the business name', function () {
    $user = User::factory()->create(['name' => 'Persona Diversa']);

    $this->actingAs($user)->post(route('provider.store'), ['name' => 'Barberia Kofi', 'city' => 'Torino'])->assertSessionHasNoErrors();

    expect($user->fresh()->provider->name)->toBe('Barberia Kofi')
        ->and($user->fresh()->name)->toBe('Barberia Kofi');
});

// ---------------------------------------------------------------------------------------------
// The settings page
// ---------------------------------------------------------------------------------------------

test('a provider sees the business name in the settings, never a field to change it', function () {
    $provider = opnProvider();

    $this->actingAs($provider->user)->get(route('profile.edit'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/profile')->where('provider_name', 'Salone Awa'));

    // A "name" sent anyway changes nothing; the email is saved, and it must be verified again.
    $this->actingAs($provider->user)->patch(route('profile.update'), ['name' => 'Nome Cambiato', 'email' => 'nuova@example.test'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user = $provider->user->fresh();
    expect($user->name)->toBe('Persona Diversa')
        ->and($provider->fresh()->name)->toBe('Salone Awa')
        ->and($user->email)->toBe('nuova@example.test')
        ->and($user->email_verified_at)->toBeNull();

    // Without "name" too: a provider is never asked for it.
    $this->actingAs($user)->patch(route('profile.update'), ['email' => 'nuova@example.test'])->assertSessionHasNoErrors();
});

test('a user without a provider keeps the field "Nome" and changes it as before', function () {
    $user = User::factory()->create(['name' => 'Vecchio Nome']);

    $this->actingAs($user)->get(route('profile.edit'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('provider_name', null)->where('auth.user.name', 'Vecchio Nome'));

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Nuovo Nome', 'email' => $user->email])->assertSessionHasNoErrors();
    expect($user->fresh()->name)->toBe('Nuovo Nome');

    $this->actingAs($user)->patch(route('profile.update'), ['name' => '', 'email' => $user->email])->assertSessionHasErrors('name');
});

test('the page: the sentence with the name instead of the field, the link to the profile, 44px on phones', function () {
    $page = (string) file_get_contents(resource_path('js/pages/settings/profile.tsx'));

    expect($page)
        ->toContain("import { edit as editProvider } from '@/routes/provider';")
        ->toMatch("/t\\('settings\\.profile\\.business_name'\\)\\.split\\(\\s*':name',?\\s*\\)/")
        ->toMatch('/\{before\}\s*<strong className="font-semibold">\{name\}<\/strong>\s*\{after\}/')
        ->toMatch('/<Link\s+href=\{editProvider\(\)\}\s+className=\{`self-start text-sm \$\{LINK\} \$\{touchTarget\}`\}\s*>\s*\{t\(\'settings\.profile\.business_name_link\'\)\}/')
        // The field "Nome" only without a provider.
        ->toMatch('/\{provider_name !== null \? \(\s*<BusinessName name=\{provider_name\} \/>\s*\) : \(\s*<div className="grid gap-2">\s*<Label htmlFor="name">/')
        ->not->toMatch('/aria-label(?!ledby)/');
});

// ---------------------------------------------------------------------------------------------
// The command
// ---------------------------------------------------------------------------------------------

test('the command lists without --force, writes with it, and the second time changes nothing', function () {
    $different = opnProvider();
    $same = Provider::factory()->for(User::factory()->create(['name' => 'Barberia Kofi', 'email' => 'kofi@example.test']))
        ->create(['name' => 'Barberia Kofi', 'slug' => 'barberia-kofi']);

    $dry = opnSync();
    expect($dry['code'])->toBe(0)
        ->and($dry['output'])->toContain('salone-awa diverso')
        ->not->toContain('barberia-kofi')
        ->toContain('Professionisti controllati: 2. Diversi: 1. Aggiornati: 0. Senza account: 0.')
        ->toContain('--force');
    expect($different->user->fresh()->name)->toBe('Persona Diversa');

    $forced = opnSync(force: true);
    expect($forced['output'])->toContain('salone-awa diverso')
        ->toContain('Professionisti controllati: 2. Diversi: 1. Aggiornati: 1. Senza account: 0.');
    // The business name wins, the business does not change.
    expect($different->user->fresh()->name)->toBe('Salone Awa')
        ->and($different->fresh()->name)->toBe('Salone Awa')
        ->and($same->user->fresh()->name)->toBe('Barberia Kofi');

    expect(opnSync(force: true)['output'])->toContain('Diversi: 0. Aggiornati: 0.')->not->toContain('diverso');
});

test('the command never prints a name or an email, and gives id-N to a provider without a slug', function () {
    $provider = opnProvider();
    DB::table('providers')->where('id', $provider->id)->update(['slug' => '']);

    foreach ([false, true] as $force) {
        expect(opnSync($force)['output'])
            ->toContain("id-{$provider->id} diverso")
            ->not->toContain('Salone Awa')
            ->not->toContain('Persona Diversa')
            ->not->toMatch('/@/');
    }
    expect($provider->user->fresh()->name)->toBe('Salone Awa');
});

test('a provider without an account is skipped and counted apart, without an error', function () {
    // The foreign key forbids it in the database: the provider is given an empty relation here.
    $orphan = opnProvider()->setRelation('user', null);
    $other = Provider::factory()->for(User::factory()->create(['name' => 'Persona Diversa']))
        ->create(['name' => 'Barberia Kofi', 'slug' => 'barberia-kofi']);
    $other->load('user');

    expect(SyncAccountName::all([$orphan, $other], force: false))->toBe([
        'different' => ['barberia-kofi'], 'checked' => 2, 'updated' => 0, 'without' => 1,
    ])->and($other->user->fresh()->name)->toBe('Persona Diversa');

    expect(SyncAccountName::all([$orphan, $other], force: true))->toBe([
        'different' => ['barberia-kofi'], 'checked' => 2, 'updated' => 1, 'without' => 1,
    ])->and($other->user->fresh()->name)->toBe('Barberia Kofi');
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts in the three languages, with the French spaces', function () {
    $nb = "\u{00A0}";
    $expected = [
        'it' => [
            'settings.profile.business_name' => 'Il nome che vedono i clienti è quello della tua attività: :name.',
            'settings.profile.business_name_link' => 'Modifica il nome nel tuo profilo',
            'console.sync_names.line' => ':provider diverso',
        ],
        'fr' => [
            'settings.profile.business_name' => "Le nom que voient vos clients est celui de votre activité{$nb}: :name.",
            'settings.profile.business_name_link' => 'Modifier le nom dans votre profil',
            'console.sync_names.line' => ':provider différent',
        ],
        'en' => [
            'settings.profile.business_name' => 'The name your customers see is your business name: :name.',
            'settings.profile.business_name_link' => 'Change the name in your profile',
            'console.sync_names.line' => ':provider different',
        ],
    ];

    foreach ($expected as $locale => $texts) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        foreach ([...array_keys($texts), 'console.sync_names.summary', 'console.sync_names.dry_run'] as $key) {
            expect($json)->toHaveKey($key);
            if ($locale === 'fr') {
                expect(preg_match('/ [:?!;](?![a-z_])/', $json[$key]))->toBe(0, "fr {$key}");
            }
        }
        foreach ($texts as $key => $text) {
            expect($json[$key])->toBe($text, "{$locale} {$key}");
        }
    }
});
