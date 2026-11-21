<?php

use App\Http\Requests\StoreProviderAvatarRequest;
use App\Http\Requests\StoreProviderPhotoRequest;
use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Profile\StoreProviderAvatar;
use App\Support\MediaDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

// The photos of the providers, PR 1 of #53 (docs/gallery.md): the thumbnails of the card with
// every size and sizes that follow the grid, 15 photos, 4 MB, 40 requests a minute, the provider's
// photo made smaller in the browser.

beforeEach(function () {
    Storage::fake(MediaDisk::NAME);
    $this->phqDir = sys_get_temp_dir().'/griot-phq-'.Str::random(12);
    mkdir($this->phqDir);
});

afterEach(function () {
    File::deleteDirectory($this->phqDir);
});

function phqSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * A JPEG of the given size, made heavier with bytes after its end (GD reads it the same).
 */
function phqUpload(int $width, int $height, int $kilobytes = 0): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 90));
    ob_start();
    imagejpeg($image, null, 90);
    $bytes = (string) ob_get_clean();

    $path = test()->phqDir.'/'.Str::random(12);
    file_put_contents($path, $kilobytes > 0 ? str_pad($bytes, $kilobytes * 1024, "\0") : $bytes);

    return new UploadedFile($path, 'foto.jpg', null, null, true);
}

// ---------------------------------------------------------------------------------------------
// The thumbnails of the card
// ---------------------------------------------------------------------------------------------

test('the card gets every size of each photo, and its thumbnails use srcset and sizes', function () {
    $provider = Provider::factory()->create();
    $photo = ProviderPhoto::factory()->for($provider)->create(['position' => 1, 'width' => 1200, 'height' => 1600]);

    $this->actingAs($provider->user)->get(route('provider.edit'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('provider/edit')
            // A portrait photo: each size is narrower than its side, as the encoder makes it.
            ->where('provider.photos.0.variants', [
                ['width' => 300, 'url' => MediaDisk::get()->url("gallery/{$photo->name}-400.webp")],
                ['width' => 600, 'url' => MediaDisk::get()->url("gallery/{$photo->name}-800.webp")],
                ['width' => 1200, 'url' => MediaDisk::get()->url("gallery/{$photo->name}-1600.webp")],
            ])
            ->missing('provider.photos.0.url'));

    preg_match('/<img[\s\S]*?\/>/', phqSource('js/components/gallery-form.tsx'), $img);
    expect($img[0])
        ->toContain('srcSet={srcSet(photo.variants)}')
        ->toContain('sizes={THUMBNAIL_SIZES}')
        ->toContain('src={fallbackSrc(photo.variants)}');
});

test('the sizes of the thumbnails follow the classes of their grid, the card and the page', function () {
    $gallery = phqSource('js/lib/gallery.ts');
    preg_match("/export const THUMBNAIL_GRID =\\s*'([^']+)';/", $gallery, $grid);
    preg_match("/export const THUMBNAIL_SIZES =\\s*'([^']+)';/", $gallery, $sizes);
    expect($grid)->toHaveCount(2)->and($sizes)->toHaveCount(2);

    // The numbers, read from the classes where they live.
    $classes = explode(' ', $grid[1]);
    expect($classes)->toContain('grid-cols-1')->toContain('md:grid-cols-3')->toContain('xl:grid-cols-2')->toContain('gap-4');
    $gap = 1; // gap-4
    expect(phqSource('js/pages/provider/edit.tsx'))->toContain('<div className="flex flex-col gap-6 p-4">');
    $page = 2 * 1; // p-4 on both sides
    expect(phqSource('js/components/ui/card.tsx'))->toContain('className={cn("px-6", className)}');
    $card = 2 * 1.5; // px-6 on both sides
    expect(phqSource('js/components/gallery-form.tsx'))->toContain('<Card className="max-w-2xl">');
    $cardWidth = 42; // max-w-2xl
    preg_match('/xl:grid-cols-\[minmax\(0,[0-9.]+rem\)_([0-9.]+)rem\]/', phqSource('js/components/add-panel.tsx'), $column);
    $columnWidth = (float) $column[1];

    $md = (int) ceil(($cardWidth - $card - 2 * $gap) / 3);
    $xl = (int) ceil(($columnWidth - $card - $gap) / 2);

    // md and xl of Tailwind: 768 and 1280 px, the largest first so that the browser takes it.
    expect($sizes[1])->toBe("(min-width: 1280px) {$xl}rem, (min-width: 768px) {$md}rem, calc(100vw - ".($page + $card).'rem)')
        ->and($sizes[1])->toBe('(min-width: 1280px) 10rem, (min-width: 768px) 13rem, calc(100vw - 5rem)');
    expect(phqSource('js/components/gallery-form.tsx'))->toContain('<ul className={THUMBNAIL_GRID}>');
});

// ---------------------------------------------------------------------------------------------
// The limits
// ---------------------------------------------------------------------------------------------

test('the gallery takes 15 photos, from the configuration only', function () {
    expect(config('booking.gallery.max_photos'))->toBe(15);

    // Never written by hand where it is used: the configuration, its resource and the texts.
    foreach (['app/Profile/StoreProviderPhoto.php', 'app/Http/Resources/ProviderEditResource.php', 'app/Http/Controllers/ProviderPhotoStoreController.php'] as $path) {
        expect((string) file_get_contents(base_path($path)))
            ->toContain("config()->integer('booking.gallery.max_photos')")
            ->not->toMatch('/\b(9|15)\b/');
    }
    expect(phqSource('js/components/gallery-form.tsx'))->not->toMatch('/\b15\b/');
});

test('a photo of 3 MB is taken now, one over 4 MB is not, and the limit is read from the configuration', function () {
    $provider = Provider::factory()->create();

    $this->actingAs($provider->user)
        ->post(route('provider.photos.store'), ['photo' => phqUpload(1000, 800, 3 * 1024), 'consent' => '1'])
        ->assertSessionHasNoErrors();
    expect($provider->photos()->count())->toBe(1);

    $this->actingAs($provider->user)
        ->post(route('provider.photos.store'), ['photo' => phqUpload(1000, 800, 4097), 'consent' => '1'])
        ->assertSessionHasErrors(['photo' => 'La foto è troppo pesante: al massimo 4 MB.']);

    expect(StoreProviderPhotoRequest::maxKilobytes())->toBe(4096);
    // With another value in the configuration, the rule and the message follow it.
    config(['booking.gallery.max_kilobytes' => 1024]);
    $this->actingAs($provider->user)
        ->post(route('provider.photos.store'), ['photo' => phqUpload(1000, 800, 1025), 'consent' => '1'])
        ->assertSessionHasErrors(['photo' => 'La foto è troppo pesante: al massimo 1 MB.']);
    expect($provider->photos()->count())->toBe(1);
});

test('uploads and removals of the gallery: 40 a minute, the 41st refused', function () {
    expect(config('booking.rate_limit_photos_per_minute'))->toBe(40);
    $provider = Provider::factory()->create();

    // A photo that does not exist: a 404, which still counts. The cheapest request of the limiter.
    for ($request = 1; $request <= 40; $request++) {
        $this->actingAs($provider->user)->delete(route('provider.photos.destroy', 999999))->assertNotFound();
    }
    $this->actingAs($provider->user)->delete(route('provider.photos.destroy', 999999))
        ->assertRedirect(route('provider.edit'))
        ->assertSessionHasErrors('photo');
});

// ---------------------------------------------------------------------------------------------
// The provider's photo
// ---------------------------------------------------------------------------------------------

test('the photo of the profile is made smaller only when it is heavier than the server takes, the original if it cannot be', function () {
    $page = phqSource('js/pages/provider/edit.tsx');
    $resize = phqSource('js/lib/resize-image.ts');

    expect($page)
        ->toMatch("/import \\{\\s*AVATAR_MAX_SIDE,\\s*needsResize,\\s*resizeImage,?\\s*\\} from '@\\/lib\\/resize-image';/")
        // Only in the submit handler, never while rendering; a lighter photo goes as it is.
        ->toMatch('/const submit = async \(event: FormEvent<HTMLFormElement>\) => \{[\s\S]*?setPreparing\(true\);\s*const avatar = needsResize\(original, maxKilobytes\)\s*\? await resizeImage\(original, AVATAR_MAX_SIDE\)\s*: original;\s*form\.transform\(\(\) => \(\{ avatar \}\)\);\s*form\.post\(storeAvatar\.url\(\), \{/')
        // The limit comes from the server, never written in the page.
        ->toMatch('/<AvatarForm\s+name=\{provider\.name\}\s+avatarUrl=\{provider\.avatar_url\}\s+maxKilobytes=\{provider\.avatar_max_kilobytes\}\s*\/>/')
        ->not->toMatch('/\b1536\b|1[.,]5|1572864/')
        ->toContain('<form onSubmit={submit} className="flex flex-col gap-4">')
        ->toContain('disabled={form.data.avatar === null || busy}')
        ->toContain('<InputError message={form.errors.avatar} />');
    expect(substr_count($page, 'resizeImage('))->toBe(1);
    // If the browser cannot read or write it, the original goes as it is: the server decides.
    expect($resize)->toMatch('/catch \{\s*return file;\s*\}/');

    // The limit has one home, the request; the page gets it through the resource.
    expect((string) file_get_contents(app_path('Http/Requests/StoreProviderAvatarRequest.php')))
        ->toContain('public const MAX_KILOBYTES = 1536;')
        ->toContain("'max:'.self::MAX_KILOBYTES,")
        ->not->toContain("'max:1536'");
    expect((string) file_get_contents(app_path('Http/Resources/ProviderEditResource.php')))
        ->toContain("'avatar_max_kilobytes' => StoreProviderAvatarRequest::MAX_KILOBYTES,");

    // 1024: twice the 512 px the server keeps, never fewer pixels than it needs.
    preg_match('/export const AVATAR_MAX_SIDE = ([0-9]+);/', $resize, $side);
    $kept = (new ReflectionClassConstant(StoreProviderAvatar::class, 'MAX_SIDE'))->getValue();
    expect((int) $side[1])->toBe(1024)->toBeGreaterThanOrEqual(2 * $kept);
});

test('only a file heavier than the limit is made smaller: a lighter one goes as it is', function () {
    $script = sys_get_temp_dir().'/griot-phq-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path('js/lib/resize-image.ts');
    // The limit the server sends, in KB, and files around it, in bytes.
    $limit = StoreProviderAvatarRequest::MAX_KILOBYTES;
    file_put_contents($script, "import * as r from '{$helper}';\nconsole.log(JSON.stringify([0, 1, {$limit} * 1024 - 1, {$limit} * 1024, {$limit} * 1024 + 1, 6 * 1024 * 1024].map((size) => r.needsResize({ size }, {$limit}))));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());
    // Exactly as Laravel's "max": up to the limit it is taken, one byte more it is not.
    expect(json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR))->toBe([false, false, false, false, true, true]);
});

test('the photo of the profile is still taken by the server as before', function () {
    $provider = Provider::factory()->create();

    $this->actingAs($provider->user)
        ->post(route('provider.avatar.store'), ['avatar' => phqUpload(1024, 768)])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('provider.edit'));

    expect($provider->refresh()->avatar_path)->toStartWith('avatars/');
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the weight message in the three languages, with the number from the configuration', function () {
    $nb = "\u{00A0}";

    expect(__('provider.validation.photo_max', ['max' => 4], 'it'))->toBe('La foto è troppo pesante: al massimo 4 MB.')
        ->and(__('provider.validation.photo_max', ['max' => 4], 'fr'))->toBe("La photo est trop lourde{$nb}: 4 Mo au maximum.")
        ->and(__('provider.validation.photo_max', ['max' => 4], 'en'))->toBe('The photo is too large: up to 4 MB.');

    // No limit of before is left in the texts of the gallery.
    foreach (['it', 'fr', 'en'] as $locale) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        foreach ($json as $key => $text) {
            if (str_starts_with($key, 'provider.gallery.') || str_starts_with($key, 'provider.validation.photo') || $key === 'provider.validation.gallery_full') {
                expect($text)->not->toMatch('/\b(2 MB|2 Mo|9 foto|9 photos)\b/', "{$locale} {$key}");
            }
        }
    }
});
