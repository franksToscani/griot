<?php

use App\Models\Booking;
use App\Models\Provider;
use App\Support\MediaDisk;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// The disk of the images (issue #61): "media", local in development, an S3-compatible bucket in
// production (docs/media-storage.md). No test connects to a real service.

/**
 * The disks of config/filesystems.php as they are read with some environment variables set.
 *
 * @param  array<string, string>  $variables
 * @return array<string, array<string, mixed>>
 */
function mdsDisks(array $variables): array
{
    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }

    try {
        $config = require config_path('filesystems.php');
    } finally {
        foreach ($previous as $name => [$env, $server, $put]) {
            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }

            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }

            putenv($put === false ? $name : "{$name}={$put}");
        }
    }

    return $config['disks'];
}

/**
 * A provider's user with a photo on the (fake) media disk.
 */
function mdsProviderWithPhoto(): Provider
{
    MediaDisk::get()->put('avatars/foto-del-provider.webp', 'webp');

    return Provider::factory()->create(['avatar_path' => 'avatars/foto-del-provider.webp']);
}

beforeEach(function () {
    Storage::fake(MediaDisk::NAME);
});

// ---------------------------------------------------------------------------------------------
// The disk
// ---------------------------------------------------------------------------------------------

test('locally the media disk is the folder and the address of today', function () {
    $media = mdsDisks(['MEDIA_DISK_DRIVER' => 'local', 'APP_URL' => 'https://griot.example'])['media'];

    expect($media)->toMatchArray([
        'driver' => 'local',
        'root' => storage_path('app/public'),
        'url' => 'https://griot.example/storage',
        'visibility' => 'public',
    ]);
    // The public/storage link still points to that folder.
    expect(config('filesystems.links'))->toBe([public_path('storage') => storage_path('app/public')]);
});

test('with MEDIA_DISK_DRIVER=s3 the media disk is a public bucket, from the AWS variables', function () {
    $media = mdsDisks([
        'MEDIA_DISK_DRIVER' => 's3',
        'AWS_ACCESS_KEY_ID' => 'chiave-di-prova',
        'AWS_SECRET_ACCESS_KEY' => 'segreto-di-prova',
        'AWS_DEFAULT_REGION' => 'fr-par',
        'AWS_BUCKET' => 'griot-media',
        'AWS_ENDPOINT' => 'https://s3.fr-par.scw.cloud',
        'AWS_URL' => 'https://griot-media.s3.fr-par.scw.cloud',
        'AWS_USE_PATH_STYLE_ENDPOINT' => 'false',
    ])['media'];

    expect($media)->toBe([
        'driver' => 's3',
        'key' => 'chiave-di-prova',
        'secret' => 'segreto-di-prova',
        'region' => 'fr-par',
        'bucket' => 'griot-media',
        'url' => 'https://griot-media.s3.fr-par.scw.cloud',
        'endpoint' => 'https://s3.fr-par.scw.cloud',
        'use_path_style_endpoint' => false,
        'visibility' => 'public',
        'throw' => true,
        'report' => false,
        // The names are random: a file never changes, it can be kept for a year.
        'options' => ['CacheControl' => 'public, max-age=31536000, immutable'],
    ]);
});

test('the media disk does not change the public disk nor the default one', function () {
    $disks = mdsDisks(['MEDIA_DISK_DRIVER' => 's3']);

    expect($disks['public']['driver'])->toBe('local')
        ->and($disks['public']['root'])->toBe(storage_path('app/public'))
        ->and((string) file_get_contents(config_path('filesystems.php')))->toContain("'default' => env('FILESYSTEM_DISK', 'local'),");
});

test('the photo is saved and read on the media disk, named in one place only', function () {
    expect(MediaDisk::NAME)->toBe('media');

    foreach (['app/Profile/StoreProviderAvatar.php', 'app/Models/Provider.php'] as $path) {
        expect((string) file_get_contents(base_path($path)))
            ->toContain('MediaDisk::get()')
            ->not->toContain("disk('public')")
            ->not->toContain("disk('media')");
    }

    // Nowhere else is the name written by hand.
    $named = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (str_contains((string) file_get_contents($file->getPathname()), "'media'")) {
            $named[] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }
    expect($named)->toBe(['Support/MediaDisk.php']);
});

test('composer asks for the S3 adapter and for the GD and EXIF extensions', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require'])->toHaveKeys(['ext-gd', 'ext-exif', 'league/flysystem-aws-s3-v3']);
});

test('.env.example has the variables of the media disk and no key', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toContain("MEDIA_DISK_DRIVER=local\n")
        ->toContain("AWS_ENDPOINT=\n")
        ->toContain("AWS_URL=\n")
        ->toContain("AWS_ACCESS_KEY_ID=\n")
        ->toContain("AWS_SECRET_ACCESS_KEY=\n")
        ->toContain('# AWS_ENDPOINT=https://s3.fr-par.scw.cloud')
        ->toContain('# AWS_DEFAULT_REGION=fr-par');
});

// ---------------------------------------------------------------------------------------------
// The photo goes with the account
// ---------------------------------------------------------------------------------------------

test('deleting a provider\'s account removes the photo from the media disk', function () {
    $provider = mdsProviderWithPhoto();

    $this->actingAs($provider->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    expect($provider->user->fresh())->toBeNull();
    MediaDisk::get()->assertMissing('avatars/foto-del-provider.webp');
});

test('a deletion refused by the database (a provider with bookings) leaves the photo', function () {
    $provider = mdsProviderWithPhoto();
    Booking::factory()->for($provider)->create();

    // Issue #133: today the database refuses, and the answer is an error page.
    // In a savepoint: on PostgreSQL the refused delete spoils the transaction of the test.
    failingRequestInSavepoint(fn () => $this->actingAs($provider->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertServerError());

    expect($provider->user->fresh())->not->toBeNull();
    MediaDisk::get()->assertExists('avatars/foto-del-provider.webp');
});

test('if the photo cannot be removed, the account is deleted anyway, with a warning without personal data', function (string $failure) {
    $provider = mdsProviderWithPhoto();
    $disk = Mockery::mock(MediaDisk::get())->makePartial();
    $failure === 'throws'
        ? $disk->shouldReceive('delete')->andThrow(new RuntimeException('Network error on avatars/foto-del-provider.webp'))
        : $disk->shouldReceive('delete')->andReturn(false);
    Storage::set(MediaDisk::NAME, $disk);
    Log::spy();

    $this->actingAs($provider->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    expect($provider->user->fresh())->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context = []) use ($provider) {
        $logged = $message.json_encode($context);

        return $message === 'The photo of a deleted provider account could not be removed from the media disk.'
            && ! str_contains($logged, $provider->user->email)
            && ! str_contains($logged, $provider->name)
            && ! str_contains($logged, 'foto-del-provider');
    });
})->with(['throws', 'returns false']);

test('an account without a photo is deleted without touching the disk', function () {
    $provider = Provider::factory()->create(['avatar_path' => null]);
    $disk = Mockery::mock(MediaDisk::get())->makePartial();
    $disk->shouldNotReceive('delete');
    Storage::set(MediaDisk::NAME, $disk);

    $this->actingAs($provider->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    expect($provider->user->fresh())->toBeNull();
});
