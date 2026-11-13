<?php

use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Models\User;
use App\Profile\AvatarNotStored;
use App\Profile\PhotoNotStored;
use App\Profile\StoreProviderAvatar;
use App\Support\MediaDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

// A media disk that fails (docs/media-storage.md, "Quando il disco non funziona"): not configured
// (the AWS_* variables empty, as on the first deploy without a bucket), an error of the bucket or
// of the network. The provider's photo, the gallery and the removals never end on an error page:
// a message in the form, or a warning in the log. No test connects to a real service.

beforeEach(function () {
    Storage::fake(MediaDisk::NAME);
    app()->setLocale('it');
    $this->mueDir = sys_get_temp_dir().'/griot-mue-'.Str::random(12);
    mkdir($this->mueDir);
});

afterEach(function () {
    File::deleteDirectory($this->mueDir);
    Provider::flushEventListeners();
});

const MUE_NOT_SAVED = 'Non è stato possibile salvare la foto. Riprova più tardi.';

function mueImage(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 120, 200));

    ob_start();
    imagejpeg($image, null, 90);

    return (string) ob_get_clean();
}

function mueUpload(int $width = 600, int $height = 400): UploadedFile
{
    $path = test()->mueDir.'/'.Str::random(12);
    file_put_contents($path, mueImage($width, $height));

    return new UploadedFile($path, 'foto.jpg', null, null, true);
}

function mueAvatar(User $user): TestResponse
{
    return test()->actingAs($user)->post(route('provider.avatar.store'), ['avatar' => mueUpload()]);
}

function mueGallery(User $user): TestResponse
{
    return test()->actingAs($user)->post(route('provider.photos.store'), ['photo' => mueUpload(2000, 1500), 'consent' => '1']);
}

/**
 * The media disk of production with the AWS_* variables empty: the S3 client is refused as soon
 * as the disk is made (no region).
 */
function mueUnconfiguredDisk(): void
{
    config()->set('filesystems.disks.'.MediaDisk::NAME, [
        'driver' => 's3',
        'key' => '',
        'secret' => '',
        'region' => '',
        'bucket' => '',
        'url' => '',
        'endpoint' => '',
        'use_path_style_endpoint' => false,
        'visibility' => 'public',
        'throw' => true,
        'report' => false,
    ]);
    Storage::forgetDisk(MediaDisk::NAME);
}

/**
 * Whether a text of the log, or of a reported error, has nothing personal of the provider.
 */
function mueAnonymous(string $text, Provider $provider): bool
{
    return ! str_contains($text, $provider->user->email)
        && ! str_contains($text, $provider->user->name)
        && ! str_contains($text, $provider->name);
}

// ---------------------------------------------------------------------------------------------
// The provider's photo
// ---------------------------------------------------------------------------------------------

test('without a configured disk the provider photo gets the message in the form, never an error page', function () {
    $provider = Provider::factory()->create(['avatar_path' => null]);
    mueUnconfiguredDisk();
    // The disk is refused already when it is made: the case of the first deploy.
    expect(fn () => MediaDisk::get())->toThrow(InvalidArgumentException::class);
    Exceptions::fake();

    mueAvatar($provider->user)
        ->assertRedirect()
        ->assertSessionHasErrors(['avatar' => MUE_NOT_SAVED]);

    expect($provider->fresh()->avatar_path)->toBeNull();
    Exceptions::assertReported(fn (AvatarNotStored $exception) => $exception->getPrevious() instanceof InvalidArgumentException);
});

test('a disk that fails to write keeps the old photo and gets the message', function (string $failure) {
    $real = MediaDisk::get();
    $real->put('avatars/vecchia.webp', 'vecchia');
    $provider = Provider::factory()->create(['avatar_path' => 'avatars/vecchia.webp']);
    $disk = Mockery::mock($real)->makePartial();
    $failure === 'throws'
        ? $disk->shouldReceive('put')->andThrow(new RuntimeException('Network error'))
        : $disk->shouldReceive('put')->andReturn(false);
    Storage::set(MediaDisk::NAME, $disk);

    mueAvatar($provider->user)->assertSessionHasErrors(['avatar' => MUE_NOT_SAVED]);

    expect($provider->fresh()->avatar_path)->toBe('avatars/vecchia.webp')
        ->and($real->allFiles())->toBe(['avatars/vecchia.webp']);
})->with(['throws', 'returns false']);

test('the failed photo is reported, with nothing personal in the error', function () {
    $provider = Provider::factory()->create(['name' => 'Salone Esempio', 'avatar_path' => null]);
    $disk = Mockery::mock(MediaDisk::get())->makePartial();
    $disk->shouldReceive('put')->andThrow(new RuntimeException('Network error'));
    Storage::set(MediaDisk::NAME, $disk);
    Exceptions::fake();

    mueAvatar($provider->user)->assertSessionHasErrors('avatar');

    Exceptions::assertReported(function (AvatarNotStored $exception) use ($provider) {
        $text = $exception->getMessage().' '.$exception->getPrevious()?->getMessage();

        return $exception->getMessage() === 'The avatar could not be written to the media disk.'
            && $exception->getPrevious() instanceof RuntimeException
            && mueAnonymous($text, $provider);
    });
});

test('if the old file cannot be removed, the new photo is saved anyway, with a warning in the log', function (string $failure) {
    $real = MediaDisk::get();
    $real->put('avatars/vecchia.webp', 'vecchia');
    $provider = Provider::factory()->create(['avatar_path' => 'avatars/vecchia.webp']);
    $disk = Mockery::mock($real)->makePartial();
    $failure === 'throws'
        ? $disk->shouldReceive('delete')->andThrow(new RuntimeException('Network error on avatars/vecchia.webp'))
        : $disk->shouldReceive('delete')->andReturn(false);
    Storage::set(MediaDisk::NAME, $disk);
    Log::spy();

    mueAvatar($provider->user)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('provider.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Foto aggiornata.']);

    $saved = $provider->fresh()->avatar_path;
    expect($saved)->not->toBe('avatars/vecchia.webp');
    $real->assertExists($saved);
    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context = []) use ($failure, $provider) {
        return $message === 'The previous provider photo could not be removed from the media disk.'
            && $context === ($failure === 'throws' ? ['exception' => RuntimeException::class] : [])
            && mueAnonymous($message.json_encode($context), $provider)
            && ! str_contains(json_encode($context).$message, 'vecchia');
    });
})->with(['throws', 'returns false']);

test('if the database fails and the new file cannot be removed either, the error of the database is the one thrown', function () {
    $provider = Provider::factory()->create(['avatar_path' => null]);
    $disk = Mockery::mock(MediaDisk::get())->makePartial();
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('Network error'));
    Storage::set(MediaDisk::NAME, $disk);
    // Removed in afterEach.
    Provider::updating(fn () => throw new RuntimeException('Database non disponibile'));
    Log::spy();

    expect(fn () => app(StoreProviderAvatar::class)->store($provider, mueUpload()))
        ->toThrow(RuntimeException::class, 'Database non disponibile');

    expect($provider->avatar_path)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->with('A new provider photo could not be removed from the media disk.', ['exception' => RuntimeException::class]);
});

// ---------------------------------------------------------------------------------------------
// The gallery
// ---------------------------------------------------------------------------------------------

test('without a configured disk a gallery photo gets the message in the form, and no row', function () {
    $provider = Provider::factory()->create();
    mueUnconfiguredDisk();
    Exceptions::fake();

    mueGallery($provider->user)
        ->assertRedirect(route('provider.edit'))
        ->assertSessionHasErrors(['photo' => MUE_NOT_SAVED]);

    expect(ProviderPhoto::query()->count())->toBe(0);
    Exceptions::assertReported(fn (PhotoNotStored $exception) => $exception->getPrevious() instanceof InvalidArgumentException);
});

test('if the cleaning after a failed size fails too, the message stays and no row is left', function () {
    $provider = Provider::factory()->create();
    $real = MediaDisk::get();
    $writes = 0;
    $disk = Mockery::mock($real)->makePartial();
    // The first size is written for real, the second fails; removing the first fails as well.
    $disk->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use ($real, &$writes) {
        return $writes++ === 0 ? $real->put($path, $contents) : throw new RuntimeException('Network error');
    });
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('Network error'));
    Storage::set(MediaDisk::NAME, $disk);
    Log::spy();

    mueGallery($provider->user)->assertSessionHasErrors(['photo' => MUE_NOT_SAVED]);

    expect(ProviderPhoto::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->with('A gallery photo could not be removed from the media disk.', ['exception' => RuntimeException::class]);
});

// ---------------------------------------------------------------------------------------------
// Removals
// ---------------------------------------------------------------------------------------------

test('without a configured disk a gallery photo is removed anyway, with a warning per file', function () {
    $provider = Provider::factory()->create();
    $photo = ProviderPhoto::factory()->for($provider)->create(['position' => 1]);
    mueUnconfiguredDisk();
    Log::spy();

    $this->actingAs($provider->user)
        ->delete(route('provider.photos.destroy', $photo->id))
        ->assertRedirect(route('provider.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Foto eliminata.']);

    expect($photo->fresh())->toBeNull();
    Log::shouldHaveReceived('warning')->times(count($photo->paths()))
        ->with('A gallery photo could not be removed from the media disk.', ['exception' => InvalidArgumentException::class]);
});

test('without a configured disk the account is deleted anyway, with its photos', function () {
    $provider = Provider::factory()->create(['avatar_path' => 'avatars/foto.webp']);
    $photo = ProviderPhoto::factory()->for($provider)->create(['position' => 1]);
    mueUnconfiguredDisk();
    Log::spy();

    $this->actingAs($provider->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    expect($provider->user->fresh())->toBeNull()
        ->and(ProviderPhoto::query()->count())->toBe(0);
    // The provider's photo, then each file of the gallery.
    Log::shouldHaveReceived('warning', ['The photo of a deleted provider account could not be removed from the media disk.', ['exception' => InvalidArgumentException::class]])->once();
    Log::shouldHaveReceived('warning', ['A gallery photo could not be removed from the media disk.', ['exception' => InvalidArgumentException::class]])->times(count($photo->paths()));
});

test('deleteQuietly removes a file, and only warns when it cannot', function (string $failure, ?array $warning) {
    $real = MediaDisk::get();
    $real->put('gallery/esempio.webp', 'webp');
    $disk = Mockery::mock($real)->makePartial();
    match ($failure) {
        'throws' => $disk->shouldReceive('delete')->andThrow(new RuntimeException('Network error on gallery/esempio.webp')),
        'returns false' => $disk->shouldReceive('delete')->andReturn(false),
        default => null,
    };
    Storage::set(MediaDisk::NAME, $disk);
    Log::spy();

    MediaDisk::deleteQuietly('gallery/esempio.webp', 'Un avviso di prova.');

    if ($warning === null) {
        $real->assertMissing('gallery/esempio.webp');
        Log::shouldNotHaveReceived('warning');
    } else {
        // The message, and the class of the error when there is one: never the path.
        Log::shouldHaveReceived('warning')->once()->with('Un avviso di prova.', ...$warning);
    }
})->with([
    'removed' => ['none', null],
    'returns false' => ['returns false', []],
    'throws' => ['throws', [['exception' => RuntimeException::class]]],
]);

test('the actions remove files through deleteQuietly only, never with a delete that can throw', function () {
    $sources = [
        'app/Profile/StoreProviderAvatar.php',
        'app/Profile/StoreProviderPhoto.php',
        'app/Profile/DeleteProviderPhoto.php',
        'app/Http/Controllers/Settings/ProfileController.php',
    ];

    foreach ($sources as $source) {
        expect((string) file_get_contents(base_path($source)))->not->toMatch('/(\$disk|MediaDisk::get\(\))->delete\(/');
    }

    // The disk is made inside a try in both uploads.
    expect((string) file_get_contents(base_path('app/Profile/StoreProviderAvatar.php')))
        ->toMatch('/try \{\s*\$stored = MediaDisk::get\(\)->put\(\$path, \$contents\);\s*\} catch \(Throwable \$exception\) \{\s*throw AvatarNotStored::because\(\$exception\);/');
    expect((string) file_get_contents(base_path('app/Profile/StoreProviderPhoto.php')))
        ->toMatch('/try \{\s*\$disk = MediaDisk::get\(\);\s*\} catch \(Throwable \$exception\) \{\s*throw PhotoNotStored::because\(\$exception\);/');
});

// ---------------------------------------------------------------------------------------------
// The errors of a visit (resources/js/lib/http-errors.ts)
// ---------------------------------------------------------------------------------------------

test('an error during a visit is a toast, not Inertia\'s window: prevented, translated, never a reload', function () {
    $source = (string) file_get_contents(resource_path('js/lib/http-errors.ts'));

    expect($source)
        ->toMatch("/router\\.on\\('httpException', \\(event\\) => \\{\\s*event\\.preventDefault\\(\\);/")
        ->toMatch('/toast\.error\(pageText\(httpErrorKey\(event\.detail\.response\.status\)\), \{\s*duration: ERROR_TOAST_DURATION,\s*\}\)/')
        ->toContain('export const ERROR_TOAST_DURATION = 10_000;')
        ->not->toMatch('/\.reload\(|location\./')
        ->not->toContain('aria-label');
});

test('each status has its text: 419 the session, 429 the wait, 503 later, every other one the generic', function () {
    $source = (string) file_get_contents(resource_path('js/lib/http-errors.ts'));

    expect($source)->toMatch("/case 419:\\s*return 'errors\\.request\\.session_expired';/")
        ->toMatch("/case 429:\\s*return 'errors\\.429\\.message';/")
        ->toMatch("/case 503:\\s*return 'errors\\.503\\.message';/")
        ->toMatch("/default:\\s*return 'errors\\.request\\.failed';/");
});

test('the handler is on outside development only, once, and reads the texts of the page shown', function () {
    $app = (string) file_get_contents(resource_path('js/app.tsx'));
    $language = (string) file_get_contents(resource_path('js/lib/document-language.ts'));

    expect($app)->toMatch('/if \(!import\.meta\.env\.DEV\) \{\s*handleHttpErrors\(\);\s*\}/')
        ->and(substr_count($app, 'handleHttpErrors()'))->toBe(1);
    expect($language)->toContain('pageTranslations = translations;')
        ->toMatch('/export function pageText\(key: string\): string \{\s*return pageTranslations\[key\] \?\? key;\s*\}/');
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts in the three languages: the same message for the photo and the gallery', function () {
    $expected = [
        'it' => [
            'provider.validation.avatar_not_saved' => MUE_NOT_SAVED,
            'errors.request.failed' => 'Qualcosa non ha funzionato. Riprova.',
            'errors.request.session_expired' => 'La sessione è scaduta. Ricarica la pagina e riprova.',
        ],
        'fr' => [
            'provider.validation.avatar_not_saved' => "La photo n'a pas pu être enregistrée. Réessayez plus tard.",
            'errors.request.failed' => "Quelque chose n'a pas fonctionné. Réessayez.",
            'errors.request.session_expired' => 'La session a expiré. Rechargez la page et réessayez.',
        ],
        'en' => [
            'provider.validation.avatar_not_saved' => 'The photo could not be saved. Please try again later.',
            'errors.request.failed' => 'Something went wrong. Please try again.',
            'errors.request.session_expired' => 'Your session has expired. Reload the page and try again.',
        ],
    ];

    foreach ($expected as $locale => $texts) {
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        foreach ($texts as $key => $text) {
            expect($translations[$key])->toBe($text);
        }

        expect($translations['provider.validation.photo_not_saved'])->toBe($texts['provider.validation.avatar_not_saved']);

        // No ":", "?", "!" or ";" that would need the French no-break space.
        foreach ($texts as $text) {
            expect($text)->not->toMatch('/[:?!;]/');
        }
    }
});
