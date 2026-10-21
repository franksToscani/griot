<?php

use App\Models\Provider;
use App\Profile\InvalidAvatar;
use App\Profile\StoreProviderAvatar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Real UploadedFile instances on temporary files outside the repository: UploadedFile::fake()
// guesses the type from the file NAME, a real upload from the CONTENT, which is what matters here.

beforeEach(function () {
    Storage::fake('public');
    // The uploads of each test live in their own temporary folder, outside the repository.
    $this->spaDir = sys_get_temp_dir().'/griot-spa-'.Str::random(12);
    mkdir($this->spaDir);
});

afterEach(function () {
    File::deleteDirectory($this->spaDir);
    Provider::flushEventListeners();
});

/**
 * The bytes of an image generated with GD, in a colour that is not all black.
 */
function spaImageBytes(int $width, int $height, string $type = 'jpeg'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 80, 40));

    ob_start();
    match ($type) {
        'png' => imagepng($image),
        'webp' => imagewebp($image),
        default => imagejpeg($image, null, 90),
    };

    return (string) ob_get_clean();
}

/**
 * An uploaded file as PHP would give it: the type comes from the content.
 */
function spaUpload(string $name, string $bytes): UploadedFile
{
    $path = test()->spaDir.'/'.Str::random(12);
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * A JPEG with an EXIF block (APP1) holding a recognisable description and the given
 * orientation, and a JPEG comment, both inserted right after the start marker.
 */
function spaJpegWithExif(string $jpeg, string $description, int $orientation = 1): string
{
    $text = $description."\0";
    // TIFF header (big endian), one IFD with two entries, then the text the first one points to:
    // 8 bytes of header + 2 (count) + 2 × 12 (entries) + 4 (next IFD) = offset 38.
    $tiff = "MM\x00\x2A".pack('N', 8)
        .pack('n', 2)
        .pack('nnNN', 0x010E, 2, strlen($text), 38)
        .pack('nnN', 0x0112, 3, 1).pack('nn', $orientation, 0)
        .pack('N', 0)
        .$text;
    $exif = "Exif\x00\x00".$tiff;
    $comment = 'GRIOT-COMMENTO-SEGRETO';

    return substr($jpeg, 0, 2)
        ."\xFF\xE1".pack('n', strlen($exif) + 2).$exif
        ."\xFF\xFE".pack('n', strlen($comment) + 2).$comment
        .substr($jpeg, 2);
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function spaSavedSize(Provider $provider): array
{
    $info = getimagesizefromstring((string) Storage::disk('public')->get((string) $provider->avatar_path));

    return [$info[0], $info[1], $info[2]];
}

test('a valid file is stored and the provider returned with the new path', function () {
    $provider = Provider::factory()->create();

    $result = app(StoreProviderAvatar::class)->store($provider, spaUpload('foto.jpg', spaImageBytes(800, 600)));

    expect($result)->toBe($provider);
    expect($provider->fresh()->avatar_path)->toBe($provider->avatar_path)->toMatch('#^avatars/[A-Za-z0-9]{40}\.webp$#');
    Storage::disk('public')->assertExists($provider->avatar_path);
});

test('the image is rewritten as WebP, at most 512 on the long side, never enlarged', function (int $width, int $height, string $type, int $savedWidth, int $savedHeight) {
    $provider = Provider::factory()->create();

    app(StoreProviderAvatar::class)->store($provider, spaUpload("foto.{$type}", spaImageBytes($width, $height, $type)));

    expect(spaSavedSize($provider))->toBe([$savedWidth, $savedHeight, IMAGETYPE_WEBP]);
})->with([
    'JPEG 800x600' => [800, 600, 'jpeg', 512, 384],
    'PNG 300x300, not enlarged' => [300, 300, 'png', 300, 300],
    'WebP 500x500' => [500, 500, 'webp', 500, 500],
    'JPEG 4000x1000' => [4000, 1000, 'jpeg', 512, 128],
    // Documents the current behaviour (a known debt): extreme proportions pass the rules and
    // become a strip.
    'JPEG 4000x200, a strip' => [4000, 200, 'jpeg', 512, 26],
]);

test('a transparent PNG stays transparent in the WebP', function () {
    $image = imagecreatetruecolor(300, 300);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    $provider = Provider::factory()->create();

    app(StoreProviderAvatar::class)->store($provider, spaUpload('trasparente.png', $png));

    $saved = imagecreatefromstring((string) Storage::disk('public')->get($provider->avatar_path));
    expect(imagecolorat($saved, 10, 10) >> 24)->toBe(127);
});

test('the EXIF block and the comment of the original are not in the saved file', function () {
    $input = spaJpegWithExif(spaImageBytes(600, 400), 'GRIOT-EXIF-SEGRETO');
    $upload = spaUpload('con-exif.jpg', $input);

    // First, the input really has them: otherwise the test would prove nothing.
    expect($input)->toContain("Exif\x00\x00")->toContain('GRIOT-EXIF-SEGRETO')->toContain('GRIOT-COMMENTO-SEGRETO');
    expect(exif_read_data($upload->getRealPath())['ImageDescription'] ?? null)->toBe('GRIOT-EXIF-SEGRETO');

    $provider = Provider::factory()->create();
    app(StoreProviderAvatar::class)->store($provider, $upload);

    $saved = (string) Storage::disk('public')->get($provider->avatar_path);
    expect($saved)
        ->not->toContain('GRIOT-EXIF-SEGRETO')
        ->not->toContain('GRIOT-COMMENTO-SEGRETO')
        ->not->toContain('Exif')
        ->not->toContain('EXIF');
    expect(substr($saved, 0, 4).substr($saved, 8, 4))->toBe('RIFFWEBP');
});

test('a JPEG taken on its side is turned upright from its EXIF orientation', function () {
    // Orientation 6: the camera was turned, the picture must rotate 90° clockwise.
    $upload = spaUpload('di-lato.jpg', spaJpegWithExif(spaImageBytes(400, 200), 'ruotata', 6));
    $provider = Provider::factory()->create();

    app(StoreProviderAvatar::class)->store($provider, $upload);

    // 400x200 turned becomes 200x400, already within 512.
    expect(spaSavedSize($provider))->toBe([200, 400, IMAGETYPE_WEBP]);
});

test('the old file is deleted only after the new one is saved', function () {
    $provider = Provider::factory()->create();
    $store = app(StoreProviderAvatar::class);

    $store->store($provider, spaUpload('prima.jpg', spaImageBytes(300, 300)));
    $first = $provider->avatar_path;
    $store->store($provider, spaUpload('seconda.jpg', spaImageBytes(300, 300)));

    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($provider->avatar_path);
    expect(Storage::disk('public')->allFiles())->toBe([$provider->avatar_path]);
});

test('if the database save fails, the old photo and the old path stay and the new file is removed', function () {
    Storage::disk('public')->put('avatars/vecchia.webp', 'vecchia');
    $provider = Provider::factory()->create(['avatar_path' => 'avatars/vecchia.webp']);
    // Removed in afterEach; Provider has no listeners of its own.
    Provider::updating(fn () => throw new RuntimeException('Database non disponibile'));

    expect(fn () => app(StoreProviderAvatar::class)->store($provider, spaUpload('nuova.jpg', spaImageBytes(300, 300))))
        ->toThrow(RuntimeException::class, 'Database non disponibile');

    expect($provider->avatar_path)->toBe('avatars/vecchia.webp');
    expect($provider->fresh()->avatar_path)->toBe('avatars/vecchia.webp');
    expect(Storage::disk('public')->allFiles())->toBe(['avatars/vecchia.webp']);
});

test('if the disk refuses to write, nothing changes', function () {
    $disk = Storage::disk('public');
    $disk->put('avatars/vecchia.webp', 'vecchia');
    $provider = Provider::factory()->create(['avatar_path' => 'avatars/vecchia.webp']);
    // The same fake disk, except that put() answers false.
    $refusing = Mockery::mock($disk)->makePartial();
    $refusing->shouldReceive('put')->andReturn(false);
    Storage::set('public', $refusing);

    expect(fn () => app(StoreProviderAvatar::class)->store($provider, spaUpload('nuova.jpg', spaImageBytes(300, 300))))
        ->toThrow(RuntimeException::class, 'could not be written');

    expect($provider->fresh()->avatar_path)->toBe('avatars/vecchia.webp');
    expect($disk->allFiles())->toBe(['avatars/vecchia.webp']);
});

test('a file GD cannot read throws InvalidAvatar and writes nothing', function () {
    $jpeg = spaImageBytes(300, 300);
    // Only the header up to the frame size: the size is readable, the picture is not.
    $sof = (int) strpos($jpeg, "\xFF\xC0");
    $headerOnly = substr($jpeg, 0, $sof + 2 + unpack('n', substr($jpeg, $sof + 2, 2))[1]);
    $provider = Provider::factory()->create();

    expect(fn () => app(StoreProviderAvatar::class)->store($provider, spaUpload('rotta.jpg', $headerOnly)))
        ->toThrow(InvalidAvatar::class);

    expect($provider->fresh()->avatar_path)->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('InvalidAvatar has technical messages without personal data', function () {
    expect(InvalidAvatar::unreadable()->getMessage())->toBe('The uploaded avatar could not be decoded as an image.');
    expect(InvalidAvatar::notEncoded()->getMessage())->toBe('The avatar could not be encoded as WebP.');
});
