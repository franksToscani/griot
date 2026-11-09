<?php

use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Profile\InvalidAvatar;
use App\Profile\WebpEncoder;
use App\Profile\WebpVariant;
use App\Support\MediaDisk;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// The gallery of the provider's work, PR A (issue #143, docs/gallery.md): the shared WebP encoder
// and the provider_photos table. Uploads, pages and deletion of files come in the next PRs.

beforeEach(function () {
    Storage::fake(MediaDisk::NAME);
    // Real uploads on temporary files outside the repository: the type comes from the content.
    $this->gmDir = sys_get_temp_dir().'/griot-gm-'.Str::random(12);
    mkdir($this->gmDir);
});

afterEach(function () {
    File::deleteDirectory($this->gmDir);
});

/**
 * The bytes of an image made with GD, in a colour that is not black.
 */
function gmImage(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 90));

    ob_start();
    imagejpeg($image, null, 90);

    return (string) ob_get_clean();
}

/**
 * A JPEG with an EXIF block (a recognisable description and an orientation) and a comment.
 */
function gmWithExif(string $jpeg, string $description, int $orientation = 1): string
{
    $text = $description."\0";
    $tiff = "MM\x00\x2A".pack('N', 8)
        .pack('n', 2)
        .pack('nnNN', 0x010E, 2, strlen($text), 38)
        .pack('nnN', 0x0112, 3, 1).pack('nn', $orientation, 0)
        .pack('N', 0)
        .$text;
    $exif = "Exif\x00\x00".$tiff;
    $comment = 'GRIOT-COMMENTO-GALLERIA';

    return substr($jpeg, 0, 2)
        ."\xFF\xE1".pack('n', strlen($exif) + 2).$exif
        ."\xFF\xFE".pack('n', strlen($comment) + 2).$comment
        .substr($jpeg, 2);
}

function gmUpload(string $bytes): UploadedFile
{
    $path = test()->gmDir.'/'.Str::random(12);
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'foto.jpg', null, null, true);
}

/**
 * The size and type of each variant, read back from its bytes, keyed as the encoder keys them.
 *
 * @param  array<int, WebpVariant>  $variants
 * @return array<int, array{0: int, 1: int, 2: int}>
 */
function gmSizes(array $variants): array
{
    return array_map(function (WebpVariant $variant) {
        $info = getimagesizefromstring($variant->bytes);
        // The size it says is the size it has.
        expect([$info[0], $info[1]])->toBe([$variant->width, $variant->height]);

        return [$info[0], $info[1], $info[2]];
    }, $variants);
}

// ---------------------------------------------------------------------------------------------
// The shared encoder
// ---------------------------------------------------------------------------------------------

test('a large photo gets every size asked, smallest first, as WebP', function () {
    $variants = app(WebpEncoder::class)->encode(gmUpload(gmImage(2000, 1000)), [1600, 400, 800], 82);

    expect(gmSizes($variants))->toBe([
        400 => [400, 200, IMAGETYPE_WEBP],
        800 => [800, 400, IMAGETYPE_WEBP],
        1600 => [1600, 800, IMAGETYPE_WEBP],
    ]);
});

test('a photo is never enlarged: a size longer than the photo is not made', function () {
    $encoder = app(WebpEncoder::class);

    // 900 wide: 400 and 800, not 1600.
    expect(gmSizes($encoder->encode(gmUpload(gmImage(900, 600)), [400, 800, 1600], 82)))
        ->toBe([400 => [400, 267, IMAGETYPE_WEBP], 800 => [800, 533, IMAGETYPE_WEBP]]);
    // Exactly 800: 400 and 800.
    expect(array_keys($encoder->encode(gmUpload(gmImage(800, 800)), [400, 800, 1600], 82)))->toBe([400, 800]);
    // Smaller than every size: the smallest is still made, at the photo's own size.
    expect(gmSizes($encoder->encode(gmUpload(gmImage(300, 200)), [400, 800, 1600], 82)))
        ->toBe([400 => [300, 200, IMAGETYPE_WEBP]]);
});

test('a photo taken on its side is turned upright before the sizes are made', function () {
    // Orientation 6: the 1000x500 picture becomes 500x1000.
    $variants = app(WebpEncoder::class)->encode(gmUpload(gmWithExif(gmImage(1000, 500), 'ruotata', 6)), [400, 800], 82);

    expect(gmSizes($variants))->toBe([400 => [200, 400, IMAGETYPE_WEBP], 800 => [400, 800, IMAGETYPE_WEBP]]);
});

test('no variant keeps the EXIF block or the comment of the original', function () {
    $input = gmWithExif(gmImage(1200, 900), 'GRIOT-EXIF-GALLERIA');
    // The input really has them: otherwise the test would prove nothing.
    expect($input)->toContain('GRIOT-EXIF-GALLERIA')->toContain('GRIOT-COMMENTO-GALLERIA');

    foreach (app(WebpEncoder::class)->encode(gmUpload($input), [400, 800], 82) as $variant) {
        expect($variant->bytes)
            ->not->toContain('GRIOT-EXIF-GALLERIA')
            ->not->toContain('GRIOT-COMMENTO-GALLERIA')
            ->not->toContain('Exif');
        expect(substr($variant->bytes, 0, 4).substr($variant->bytes, 8, 4))->toBe('RIFFWEBP');
    }
});

test('the quality asked is the one used', function () {
    // A photo with detail, so the quality makes a difference.
    $image = imagecreatetruecolor(800, 600);
    for ($x = 0; $x < 800; $x += 4) {
        imageline($image, $x, 0, 800 - $x, 600, (int) imagecolorallocate($image, $x % 255, (3 * $x) % 255, (7 * $x) % 255));
    }
    ob_start();
    imagejpeg($image, null, 95);
    $photo = gmUpload((string) ob_get_clean());

    $low = app(WebpEncoder::class)->encode($photo, [800], 30)[800];
    $high = app(WebpEncoder::class)->encode($photo, [800], 95)[800];

    expect(strlen($low->bytes))->toBeLessThan(strlen($high->bytes));
});

test('a file GD cannot read throws InvalidAvatar', function () {
    expect(fn () => app(WebpEncoder::class)->encode(gmUpload('non è una foto'), [400], 82))
        ->toThrow(InvalidAvatar::class);
});

test('the avatar uses the shared encoder, with its one size of 512', function () {
    $avatar = (string) file_get_contents(app_path('Profile/StoreProviderAvatar.php'));

    expect($avatar)
        ->toContain('public function __construct(private readonly WebpEncoder $encoder) {}')
        ->toContain('$this->encoder->encode($file, [self::MAX_SIDE], self::WEBP_QUALITY)[self::MAX_SIDE]->bytes')
        ->toContain('private const MAX_SIDE = 512;')
        ->toContain('private const WEBP_QUALITY = 85;')
        ->toContain('MediaDisk::get()')
        // GD is used in one place only.
        ->not->toContain('imagecreatefromstring')
        ->not->toContain('imagewebp');
});

// ---------------------------------------------------------------------------------------------
// The configuration
// ---------------------------------------------------------------------------------------------

test('the gallery sizes, quality and maximum are in config/booking.php, not in the code', function () {
    expect(config('booking.gallery'))->toBe([
        'max_photos' => 9,
        'sizes' => [400, 800, 1600],
        'quality' => 82,
    ]);

    foreach (['Profile/WebpEncoder.php', 'Models/ProviderPhoto.php'] as $path) {
        expect(preg_match('/\b(400|800|1600|82)\b/', (string) file_get_contents(app_path($path))))->toBe(0, "A gallery number written in {$path}");
    }
});

// ---------------------------------------------------------------------------------------------
// The table and the model
// ---------------------------------------------------------------------------------------------

test('the table has its columns, a unique name and a non-unique index on provider and position', function () {
    expect(Schema::getColumnListing('provider_photos'))->toBe([
        'id', 'provider_id', 'name', 'width', 'height', 'alt_text', 'position', 'consent_at', 'created_at', 'updated_at',
    ]);

    $indexes = collect(Schema::getIndexes('provider_photos'))->keyBy(fn (array $index) => implode(',', $index['columns']));

    expect($indexes['name']['unique'])->toBeTrue()
        ->and($indexes['provider_id,position']['unique'])->toBeFalse();

    $foreign = collect(Schema::getForeignKeys('provider_photos'))->firstWhere('columns', ['provider_id']);
    expect($foreign['foreign_table'])->toBe('providers')
        ->and(strtolower((string) $foreign['on_delete']))->toBe('cascade');
});

test('the photos of a provider come in their order: position, then id', function () {
    $provider = Provider::factory()->create();
    $third = ProviderPhoto::factory()->for($provider)->create(['position' => 3]);
    $first = ProviderPhoto::factory()->for($provider)->create(['position' => 1]);
    $secondA = ProviderPhoto::factory()->for($provider)->create(['position' => 2]);
    $secondB = ProviderPhoto::factory()->for($provider)->create(['position' => 2]);
    ProviderPhoto::factory()->create(['position' => 1]);

    expect($provider->photos->pluck('id')->all())->toBe([$first->id, $secondA->id, $secondB->id, $third->id]);
    // Said in the query: SQLite would give this order anyway, reading the (provider_id, position)
    // index, but another database need not.
    expect($provider->photos()->toRawSql())->toEndWith('order by "position" asc, "id" asc');
});

test('the photos go with their provider (cascade in the database)', function () {
    $provider = Provider::factory()->create();
    ProviderPhoto::factory()->count(2)->for($provider)->create();
    $other = ProviderPhoto::factory()->create();

    $provider->delete();

    expect(ProviderPhoto::query()->pluck('id')->all())->toBe([$other->id]);
});

test('two photos cannot share a name, and consent_at is required', function () {
    ProviderPhoto::factory()->create(['name' => str_repeat('a', 40)]);

    // In its own transaction (a savepoint): on PostgreSQL a failed statement spoils the test's
    // transaction, and the next query would fail too (docs/ci.md).
    expect(fn () => DB::transaction(fn () => ProviderPhoto::factory()->create(['name' => str_repeat('a', 40)])))->toThrow(QueryException::class);
    expect(fn () => ProviderPhoto::factory()->create(['consent_at' => null]))->toThrow(QueryException::class);
});

test('only the alternative text can be mass assigned; the rest is set by code', function () {
    $photo = new ProviderPhoto(['alt_text' => 'Box braids lunghe', 'name' => 'scelto-da-fuori', 'position' => 1, 'consent_at' => now()]);

    expect($photo->alt_text)->toBe('Box braids lunghe')
        ->and($photo->name)->toBeNull()
        ->and($photo->position)->toBeNull()
        ->and($photo->consent_at)->toBeNull();
    expect(ProviderPhoto::factory()->create()->consent_at)->toBeInstanceOf(DateTimeImmutable::class);
});

test('the sizes made, the paths and the addresses come from the media disk', function (int $width, int $height, array $sizes) {
    $photo = ProviderPhoto::factory()->make(['name' => 'abc', 'width' => $width, 'height' => $height]);

    expect($photo->sizes())->toBe($sizes)
        ->and($photo->paths())->toBe(array_map(fn (int $side) => "gallery/abc-{$side}.webp", $sizes))
        ->and($photo->pathFor(800))->toBe('gallery/abc-800.webp')
        ->and($photo->urlFor(800))->toBe(MediaDisk::get()->url('gallery/abc-800.webp'));
})->with([
    'every size' => [1600, 1200, [400, 800, 1600]],
    'portrait, every size' => [1200, 1600, [400, 800, 1600]],
    'up to 800' => [800, 533, [400, 800]],
    'smaller than every size' => [300, 200, [400]],
]);
