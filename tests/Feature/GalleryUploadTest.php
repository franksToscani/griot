<?php

use App\Models\Booking;
use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

// The gallery of the provider's work, PR B (issue #143, docs/gallery.md): upload with consent,
// removal of a photo, removal of the files with the account. Real images made with GD, real
// uploads on temporary files outside the repository, the fake media disk.

beforeEach(function () {
    Storage::fake(MediaDisk::NAME);
    $this->guDir = sys_get_temp_dir().'/griot-gu-'.Str::random(12);
    mkdir($this->guDir);
});

afterEach(function () {
    File::deleteDirectory($this->guDir);
});

function guImage(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 90));

    ob_start();
    imagejpeg($image, null, 90);

    return (string) ob_get_clean();
}

function guUpload(string $bytes, string $name = 'foto.jpg'): UploadedFile
{
    $path = test()->guDir.'/'.Str::random(12);
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * @param  array<string, mixed>  $data
 */
function guPost(User $user, array $data): TestResponse
{
    return test()->actingAs($user)->post(route('provider.photos.store'), $data);
}

/**
 * A valid upload: a photo of 2000x1500, the consent ticked.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function guValid(array $override = []): array
{
    return ['photo' => guUpload(guImage(2000, 1500)), 'consent' => '1', ...$override];
}

/**
 * A photo row with its files on the fake disk.
 */
function guPhoto(Provider $provider, int $position): ProviderPhoto
{
    $photo = ProviderPhoto::factory()->for($provider)->create(['position' => $position]);

    foreach ($photo->paths() as $path) {
        MediaDisk::get()->put($path, 'webp');
    }

    return $photo;
}

// ---------------------------------------------------------------------------------------------
// Upload
// ---------------------------------------------------------------------------------------------

test('a photo is added with its sizes, its text and the moment of the consent, last in the order', function () {
    $provider = Provider::factory()->create();

    guPost($provider->user, guValid(['alt_text' => '  Box braids lunghe  ']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('provider.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Foto aggiunta.']);

    $photo = $provider->photos()->sole();
    expect($photo->position)->toBe(1)
        ->and($photo->alt_text)->toBe('Box braids lunghe')
        ->and($photo->consent_at)->not->toBeNull()
        ->and([$photo->width, $photo->height])->toBe([1600, 1200])
        ->and(strlen($photo->name))->toBe(40);

    // Every size, and only those.
    $files = MediaDisk::get()->allFiles();
    $expected = $photo->paths();
    sort($files);
    sort($expected);
    expect($files)->toBe($expected)->toHaveCount(3);
    expect(getimagesizefromstring((string) MediaDisk::get()->get($photo->pathFor(800)))['mime'])->toBe('image/webp');

    // The next one comes after, without a text.
    guPost($provider->user, guValid())->assertSessionHasNoErrors();
    expect($provider->photos()->pluck('position')->all())->toBe([1, 2])
        ->and($provider->photos()->where('position', 2)->sole()->alt_text)->toBeNull();
});

test('the ninth photo is added, the tenth is refused with nothing saved', function () {
    $provider = Provider::factory()->create();
    ProviderPhoto::factory()->count(8)->for($provider)->sequence(fn ($sequence) => ['position' => $sequence->index + 1])->create();

    guPost($provider->user, guValid())->assertSessionHasNoErrors();
    expect($provider->photos()->count())->toBe(9)
        ->and($provider->photos()->max('position'))->toBe(9);
    $files = MediaDisk::get()->allFiles();

    guPost($provider->user, guValid())
        ->assertSessionHasErrors(['photo' => 'Hai già 9 foto: eliminane una per aggiungerne un\'altra.'])
        ->assertRedirect(route('provider.edit'));

    expect($provider->photos()->count())->toBe(9)
        ->and(MediaDisk::get()->allFiles())->toBe($files);
});

test('a refused upload saves nothing', function (Closure $data, string $field, string $message) {
    $provider = Provider::factory()->create();

    guPost($provider->user, $data())->assertSessionHasErrors([$field => $message]);

    expect(ProviderPhoto::query()->count())->toBe(0)
        ->and(MediaDisk::get()->allFiles())->toBe([]);
})->with([
    'no consent' => [fn () => guValid(['consent' => null]), 'consent', 'Conferma il consenso delle persone nella foto.'],
    'consent not ticked' => [fn () => guValid(['consent' => '0']), 'consent', 'Conferma il consenso delle persone nella foto.'],
    'text too long' => [fn () => guValid(['alt_text' => str_repeat('a', 151)]), 'alt_text', 'La descrizione può avere al massimo 150 caratteri.'],
    'no file' => [fn () => guValid(['photo' => null]), 'photo', 'Scegli una foto.'],
    'not an image' => [fn () => guValid(['photo' => guUpload('non è una foto', 'foto.jpg')]), 'photo', "Il file non è una foto valida: usa un'immagine JPG, PNG o WebP."],
    'under 800 on the longest side' => [fn () => guValid(['photo' => guUpload(guImage(799, 500))]), 'photo', 'La foto è troppo piccola: almeno 800 pixel sul lato più lungo.'],
    'over 4000 on a side' => [fn () => guValid(['photo' => guUpload(guImage(4001, 1000))]), 'photo', 'La foto può avere al massimo 4000 pixel per lato.'],
    'over 2 MB' => [fn () => guValid(['photo' => guUpload(guImage(1000, 800).str_repeat("\0", 2049 * 1024))]), 'photo', 'La foto è troppo pesante: al massimo 2 MB.'],
]);

test('800 on the longest side is enough, whichever side it is', function (int $width, int $height) {
    $provider = Provider::factory()->create();

    guPost($provider->user, guValid(['photo' => guUpload(guImage($width, $height))]))->assertSessionHasNoErrors();

    // 800 long: the sizes 400 and 800, not 1600.
    expect($provider->photos()->sole()->paths())->toHaveCount(2);
})->with(['landscape' => [800, 500], 'portrait' => [500, 800]]);

test('only a logged-in provider adds photos, always to its own gallery', function () {
    $this->post(route('provider.photos.store'), guValid())->assertRedirect(route('login'));

    $withoutProvider = User::factory()->create();
    guPost($withoutProvider, guValid())->assertRedirect(route('provider.setup'));

    // An id in the body is ignored: the photo goes to the logged-in provider.
    $mine = Provider::factory()->create();
    $other = Provider::factory()->create();
    guPost($mine->user, guValid(['provider_id' => $other->id]))->assertSessionHasNoErrors();

    expect($mine->photos()->count())->toBe(1)
        ->and($other->photos()->count())->toBe(0);
});

test('uploads and removals over the limit of the minute are refused', function () {
    config(['booking.rate_limit_photos_per_minute' => 2]);
    $provider = Provider::factory()->create();

    guPost($provider->user, guValid())->assertSessionHasNoErrors();
    guPost($provider->user, guValid())->assertSessionHasNoErrors();
    guPost($provider->user, guValid())
        ->assertSessionHasErrors('photo')
        ->assertRedirect(route('provider.edit'));

    expect($provider->photos()->count())->toBe(2);
});

test('if a size cannot be written, nothing of the photo is left', function (string $failure) {
    $provider = Provider::factory()->create();
    $real = MediaDisk::get();
    $writes = 0;
    $disk = Mockery::mock($real)->makePartial();
    $disk->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use ($real, &$writes, $failure) {
        // The first size is written for real, the second fails.
        if ($writes++ === 0) {
            return $real->put($path, $contents);
        }

        return $failure === 'throws' ? throw new RuntimeException('Network error') : false;
    });
    Storage::set(MediaDisk::NAME, $disk);

    guPost($provider->user, guValid())
        ->assertSessionHasErrors(['photo' => 'Non è stato possibile salvare la foto. Riprova più tardi.']);

    expect(ProviderPhoto::query()->count())->toBe(0)
        ->and($real->allFiles())->toBe([])
        ->and($writes)->toBe(2);
})->with(['returns false', 'throws']);

// ---------------------------------------------------------------------------------------------
// Removal of a photo
// ---------------------------------------------------------------------------------------------

test('removing a photo takes its row and its files, and the others close the gap', function () {
    $provider = Provider::factory()->create();
    $first = guPhoto($provider, 1);
    $second = guPhoto($provider, 2);
    $third = guPhoto($provider, 3);

    $this->actingAs($provider->user)
        ->delete(route('provider.photos.destroy', $second->id))
        ->assertRedirect(route('provider.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Foto eliminata.']);

    expect($provider->photos()->pluck('id')->all())->toBe([$first->id, $third->id])
        ->and($provider->photos()->pluck('position')->all())->toBe([1, 2]);

    foreach ($second->paths() as $path) {
        MediaDisk::get()->assertMissing($path);
    }
    foreach ([...$first->paths(), ...$third->paths()] as $path) {
        MediaDisk::get()->assertExists($path);
    }
});

test('a photo of another provider cannot be removed: not found, nothing touched', function () {
    $mine = Provider::factory()->create();
    $theirs = guPhoto(Provider::factory()->create(), 1);

    $this->actingAs($mine->user)
        ->delete(route('provider.photos.destroy', $theirs->id))
        ->assertNotFound();

    expect($theirs->fresh())->not->toBeNull();
    foreach ($theirs->paths() as $path) {
        MediaDisk::get()->assertExists($path);
    }
});

test('if a file cannot be removed, the photo is removed anyway, with a warning without personal data', function () {
    $provider = Provider::factory()->create();
    $photo = guPhoto($provider, 1);
    $disk = Mockery::mock(MediaDisk::get())->makePartial();
    $disk->shouldReceive('delete')->andThrow(new RuntimeException("Network error on {$photo->name}"));
    Storage::set(MediaDisk::NAME, $disk);
    Log::spy();

    $this->actingAs($provider->user)
        ->delete(route('provider.photos.destroy', $photo->id))
        ->assertRedirect(route('provider.edit'));

    expect($photo->fresh())->toBeNull();
    Log::shouldHaveReceived('warning')->times(3)->withArgs(function (string $message, array $context = []) use ($photo, $provider) {
        $logged = $message.json_encode($context);

        return $message === 'A gallery photo could not be removed from the media disk.'
            && $context === ['exception' => RuntimeException::class]
            && ! str_contains($logged, $photo->name)
            && ! str_contains($logged, $provider->user->email);
    });
});

// ---------------------------------------------------------------------------------------------
// The account
// ---------------------------------------------------------------------------------------------

test('deleting the account removes the files of every photo, after the account is gone', function () {
    $provider = Provider::factory()->create();
    $photos = [guPhoto($provider, 1), guPhoto($provider, 2)];

    $this->actingAs($provider->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    expect($provider->user->fresh())->toBeNull()
        ->and(ProviderPhoto::query()->count())->toBe(0)
        ->and(MediaDisk::get()->allFiles())->toBe([]);
    expect($photos)->toHaveCount(2);
});

test('a deletion refused by the database (a provider with bookings) leaves every photo', function () {
    $provider = Provider::factory()->create();
    $photo = guPhoto($provider, 1);
    Booking::factory()->for($provider)->create();

    // In a savepoint: on PostgreSQL the refused delete spoils the transaction of the test.
    failingRequestInSavepoint(fn () => $this->actingAs($provider->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertServerError());

    expect($photo->fresh())->not->toBeNull();
    foreach ($photo->paths() as $path) {
        MediaDisk::get()->assertExists($path);
    }
});

test('if the files cannot be removed with the account, it is deleted anyway, with warnings without personal data', function () {
    $provider = Provider::factory()->create();
    $photo = guPhoto($provider, 1);
    $disk = Mockery::mock(MediaDisk::get())->makePartial();
    $disk->shouldReceive('delete')->andReturn(false);
    Storage::set(MediaDisk::NAME, $disk);
    Log::spy();

    $this->actingAs($provider->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    expect($provider->user->fresh())->toBeNull();
    Log::shouldHaveReceived('warning')->times(count($photo->paths()))->withArgs(fn (string $message, array $context = []) => $message === 'A gallery photo could not be removed from the media disk.'
        && ! str_contains($message.json_encode($context), $photo->name)
        && ! str_contains($message.json_encode($context), $provider->user->email));
});

test('a provider without photos nor avatar is deleted without touching the disk', function () {
    $provider = Provider::factory()->create(['avatar_path' => null]);
    $disk = Mockery::mock(MediaDisk::get())->makePartial();
    $disk->shouldNotReceive('delete');
    Storage::set(MediaDisk::NAME, $disk);

    $this->actingAs($provider->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    expect($provider->user->fresh())->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// The source
// ---------------------------------------------------------------------------------------------

test('the new code uses the media disk by its one name, and logs no personal data', function () {
    $files = ['Profile/StoreProviderPhoto.php', 'Profile/DeleteProviderPhoto.php', 'Http/Controllers/ProviderPhotoStoreController.php', 'Http/Controllers/ProviderPhotoDestroyController.php', 'Http/Requests/StoreProviderPhotoRequest.php', 'Http/Controllers/Settings/ProfileController.php'];

    foreach ($files as $path) {
        $source = (string) file_get_contents(app_path($path));
        expect($source)->not->toContain("disk('public')")->not->toContain("'media'")->not->toContain('Storage::disk(');

        // Every warning: a fixed message and, at most, the class of the error.
        preg_match_all('/Log::warning\(([^;]*)\);/s', $source, $warnings);
        foreach ($warnings[1] as $warning) {
            expect($warning)->not->toMatch('/\$path|\$user|\$provider|email|name|customer/i');
        }
    }
});

test('the browser makes the photo at most 2400 on its longest side, never larger, in proportion', function () {
    $script = sys_get_temp_dir().'/griot-resize-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path('js/lib/resize-image.ts');
    file_put_contents($script, "import * as r from '{$helper}';\nconsole.log(JSON.stringify({
        max: r.MAX_SIDE, quality: r.JPEG_QUALITY,
        large: r.targetSize(4032, 3024),
        portrait: r.targetSize(3024, 4032),
        square: r.targetSize(5000, 5000),
        small: r.targetSize(1200, 900),
        exact: r.targetSize(2400, 1600),
        tiny: r.targetSize(1, 1),
        other: r.targetSize(4000, 1000, 800),
    }));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'max' => 2400,
        'quality' => 0.85,
        'large' => ['width' => 2400, 'height' => 1800],
        'portrait' => ['width' => 1800, 'height' => 2400],
        'square' => ['width' => 2400, 'height' => 2400],
        'small' => ['width' => 1200, 'height' => 900],
        'exact' => ['width' => 2400, 'height' => 1600],
        'tiny' => ['width' => 1, 'height' => 1],
        'other' => ['width' => 800, 'height' => 200],
    ]);
    // Not read: the original goes as it is.
    expect((string) file_get_contents($helper))->toMatch('/catch \{\s*return file;\s*\}/');
});

test('the texts of the gallery exist in Italian, French and English, with the same placeholders', function () {
    $keys = ['provider.photos.saved', 'provider.photos.deleted', 'provider.photos.consent', 'provider.validation.photo_required', 'provider.validation.photo_invalid', 'provider.validation.photo_max', 'provider.validation.photo_dimensions', 'provider.validation.photo_too_small', 'provider.validation.photo_unreadable', 'provider.validation.photo_consent', 'provider.validation.photo_alt_max', 'provider.validation.gallery_full', 'provider.validation.photo_not_saved'];
    $json = fn (string $locale) => json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        sort($found[0]);

        return $found[0];
    };

    foreach ($keys as $key) {
        foreach (['fr', 'en'] as $locale) {
            expect($json($locale))->toHaveKey($key)
                ->and($placeholders($json($locale)[$key]))->toBe($placeholders($json('it')[$key]), "{$locale} {$key}");
        }
    }

    expect(__('provider.photos.consent', [], 'it'))->toBe('Le persone riconoscibili in questa foto hanno accettato che venga pubblicata.')
        ->and(__('provider.photos.consent', [], 'fr'))->toBe("Les personnes reconnaissables sur cette photo ont accepté qu'elle soit publiée.")
        ->and(__('provider.photos.consent', [], 'en'))->toBe('The people who can be recognised in this photo have agreed to its publication.')
        ->and(__('provider.validation.gallery_full', ['max' => 9], 'fr'))->toBe("Vous avez déjà 9 photos\u{00A0}: supprimez-en une pour en ajouter une autre.");
});
