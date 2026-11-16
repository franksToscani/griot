<?php

use App\Booking\AnonymizeOldBookings;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Models\Service;
use App\Models\User;
use App\Profile\AccountHasBookings;
use App\Profile\DeleteProviderAccount;
use App\Support\MediaDisk;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

// Issue #133: a provider with bookings, in any status and anonymous ones too, cannot delete the
// account (bookings.provider_id and service_id are restrictOnDelete). The refusal is the error
// "account" with the site's contact, never an error page, and the provider stays logged in.
// The same on SQLite here and on PostgreSQL in the tests-postgres job of the CI.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', 'UTC'));
    app()->setLocale('it');
    Storage::fake(MediaDisk::NAME);
    config(['contact.email' => 'info@example.test']);
});

/**
 * A provider with a photo and a gallery photo, both on the disk.
 *
 * @return array{Provider, list<string>}
 */
function adProvider(): array
{
    MediaDisk::get()->put('avatars/foto-del-provider.webp', 'webp');
    $provider = Provider::factory()->create(['avatar_path' => 'avatars/foto-del-provider.webp']);
    $photo = ProviderPhoto::factory()->for($provider)->create(['position' => 1]);

    foreach ($photo->paths() as $path) {
        MediaDisk::get()->put($path, 'webp');
    }

    return [$provider, ['avatars/foto-del-provider.webp', ...$photo->paths()]];
}

/**
 * A booking of the provider: in the future, in the past, or past and made anonymous.
 */
function adBooking(Provider $provider, string $kind): Booking
{
    $start = $kind === 'future' ? CarbonImmutable::parse('2026-11-20 09:00', 'UTC') : CarbonImmutable::parse('2026-01-10 09:00', 'UTC');

    $booking = Booking::factory()->for($provider)->for(Service::factory()->for($provider))->create([
        'starts_at' => $start,
        'ends_at' => $start->addHour(),
    ]);

    if ($kind === 'anonymous') {
        app(AnonymizeOldBookings::class)->run(CarbonImmutable::now());
        expect($booking->fresh()?->isAnonymized())->toBeTrue();
    }

    return $booking;
}

/**
 * Runs $run with a listener on the deletion of a user, then removes it.
 */
function adWhileDeleting(Closure $listener, Closure $run): mixed
{
    User::deleting($listener);

    try {
        return $run();
    } finally {
        User::flushEventListeners();
    }
}

const AD_REFUSAL = 'Il tuo account ha delle prenotazioni, quindi per ora non puoi eliminarlo da solo. Per chiedere di eliminarlo, scrivi a info@example.test.';

// ---------------------------------------------------------------------------------------------
// The refusal
// ---------------------------------------------------------------------------------------------

test('a provider with a booking cannot delete the account: the error, still logged in, nothing removed', function (string $kind) {
    [$provider, $files] = adProvider();
    adBooking($provider, $kind);

    $this->actingAs($provider->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['account' => AD_REFUSAL]);

    $this->assertAuthenticatedAs($provider->user);
    expect($provider->user->fresh())->not->toBeNull()
        ->and($provider->fresh())->not->toBeNull()
        ->and($provider->photos()->count())->toBe(1);
    foreach ($files as $path) {
        MediaDisk::get()->assertExists($path);
    }
})->with(['future', 'past', 'anonymous']);

test('the refusal comes with a wrong password too, and the password error stays', function () {
    [$provider] = adProvider();
    adBooking($provider, 'future');

    $this->actingAs($provider->user)
        ->delete(route('profile.destroy'), ['password' => 'sbagliata'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['password', 'account']);

    $this->assertAuthenticatedAs($provider->user);
});

test('without the site\'s contact the refusal says to contact who runs Griot', function () {
    config(['contact.email' => null]);
    [$provider] = adProvider();
    adBooking($provider, 'past');

    $this->actingAs($provider->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors(['account' => 'Il tuo account ha delle prenotazioni, quindi per ora non puoi eliminarlo da solo. Per chiedere di eliminarlo, contatta chi gestisce Griot.']);
});

test('a booking arriving between the validation and the delete gives the same error, not an error page', function () {
    [$provider, $files] = adProvider();
    $inserted = false;

    adWhileDeleting(function (User $deleting) use (&$inserted) {
        // Only the first time: a booking is made just before the row is deleted.
        if (! $inserted) {
            $inserted = true;
            $provider = $deleting->provider()->firstOrFail();
            Booking::factory()->for($provider)->for(Service::factory()->for($provider))->create();
        }
    }, fn () => $this->actingAs($provider->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['account' => AD_REFUSAL]));

    expect($inserted)->toBeTrue();
    $this->assertAuthenticatedAs($provider->user);
    expect($provider->user->fresh())->not->toBeNull();
    foreach ($files as $path) {
        MediaDisk::get()->assertExists($path);
    }
});

test('the action alone refuses a provider with bookings, without the request', function () {
    [$provider] = adProvider();
    adBooking($provider, 'future');

    expect(fn () => app(DeleteProviderAccount::class)->delete($provider->user))->toThrow(AccountHasBookings::class);
    expect($provider->user->fresh())->not->toBeNull();
});

test('the deleting listener above is not registered any more', function () {
    expect(Event::hasListeners('eloquent.deleting: '.User::class))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Without bookings
// ---------------------------------------------------------------------------------------------

test('without bookings the account, the provider and the files go, and the user is logged out', function () {
    [$provider, $files] = adProvider();
    $user = $provider->user;
    expect($user->getRememberToken())->not->toBeEmpty();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    // Never written again by the logout ("remember me" token on a deleted model).
    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(Provider::query()->whereKey($provider->id)->exists())->toBeFalse()
        ->and(ProviderPhoto::query()->where('provider_id', $provider->id)->exists())->toBeFalse();
    foreach ($files as $path) {
        MediaDisk::get()->assertMissing($path);
    }
});

// ---------------------------------------------------------------------------------------------
// The page
// ---------------------------------------------------------------------------------------------

test('the settings page says whether the account has bookings', function (bool $withBooking) {
    [$provider] = adProvider();

    if ($withBooking) {
        adBooking($provider, 'anonymous');
    }

    $this->actingAs($provider->user)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/profile')
            ->where('has_bookings', $withBooking));
})->with(['with a booking' => true, 'without' => false]);

test('a user without a provider has no bookings', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('has_bookings', false));
});

test('the page shows the explanation in place of the button, and the focus goes there after a refusal', function () {
    $source = (string) file_get_contents(resource_path('js/components/delete-user.tsx'));
    $page = (string) file_get_contents(resource_path('js/pages/settings/profile.tsx'));

    expect($page)->toContain('<DeleteUser hasBookings={has_bookings} />')
        ->and($source)->toMatch('/if \(hasBookings\) \{\s*return \(/')
        ->and($source)->toContain("{t('settings.delete.has_bookings')}")
        ->and($source)->toContain('href={`mailto:${contact.email}`}')
        ->and($source)->toContain("t('settings.delete.has_bookings_no_contact')")
        ->and($source)->toMatch('/if \(errors\.account\) \{\s*requestAnimationFrame\(\(\) =>\s*document\s*\.getElementById\(BLOCKED_ID\)\s*\?\.focus\(\),/')
        ->and($source)->toContain('tabIndex={-1}');
});

test('the controller logs out only after the deletion, and never with logout()', function () {
    $source = (string) file_get_contents(app_path('Http/Controllers/Settings/ProfileController.php'));

    expect(strpos($source, '$deleteAccount->delete('))->toBeLessThan(strpos($source, 'Auth::logoutCurrentDevice();'))
        ->and($source)->not->toContain('Auth::logout()');
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts in the three languages', function () {
    $nbsp = "\u{00A0}";
    $expected = [
        'it' => [
            'settings.delete.has_bookings' => 'Il tuo account ha delle prenotazioni, quindi per ora non puoi eliminarlo da solo.',
            'settings.delete.has_bookings_email' => 'Per chiedere di eliminarlo, scrivi a :email.',
            'settings.delete.has_bookings_write' => 'Per chiedere di eliminarlo, scrivi a',
            'settings.delete.has_bookings_no_contact' => 'Per chiedere di eliminarlo, contatta chi gestisce Griot.',
        ],
        'fr' => [
            'settings.delete.has_bookings' => "Votre compte a des réservations{$nbsp}: pour l'instant, vous ne pouvez pas le supprimer vous-même.",
            'settings.delete.has_bookings_email' => 'Pour demander sa suppression, écrivez à :email.',
            'settings.delete.has_bookings_write' => 'Pour demander sa suppression, écrivez à',
            'settings.delete.has_bookings_no_contact' => "Pour demander sa suppression, contactez l'équipe qui gère Griot.",
        ],
        'en' => [
            'settings.delete.has_bookings' => 'Your account has bookings, so for now you cannot delete it yourself.',
            'settings.delete.has_bookings_email' => 'To ask for it to be deleted, write to :email.',
            'settings.delete.has_bookings_write' => 'To ask for it to be deleted, write to',
            'settings.delete.has_bookings_no_contact' => 'To ask for it to be deleted, contact the people who run Griot.',
        ],
    ];

    foreach ($expected as $locale => $texts) {
        foreach ($texts as $key => $text) {
            expect(__($key, [], $locale))->toBe($text);
        }
    }
});
