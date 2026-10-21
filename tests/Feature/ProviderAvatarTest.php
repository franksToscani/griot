<?php

use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// Real UploadedFile instances on temporary files outside the repository: UploadedFile::fake()
// guesses the type from the file NAME, while the rules must look at the CONTENT.

beforeEach(function () {
    Storage::fake('public');
    app()->setLocale('it');
    $this->pavDir = sys_get_temp_dir().'/griot-pav-'.Str::random(12);
    mkdir($this->pavDir);
});

afterEach(function () {
    File::deleteDirectory($this->pavDir);
});

function pavImageBytes(int $width, int $height, string $type = 'jpeg'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 120, 200));

    ob_start();
    match ($type) {
        'png' => imagepng($image),
        'webp' => imagewebp($image),
        'gif' => imagegif($image),
        default => imagejpeg($image, null, 90),
    };

    return (string) ob_get_clean();
}

function pavUpload(string $name, string $bytes): UploadedFile
{
    $path = test()->pavDir.'/'.Str::random(12);
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * A valid JPEG of exactly $kilobytes KB: bytes after the end marker are ignored by readers.
 */
function pavJpegOfSize(int $kilobytes): string
{
    $jpeg = pavImageBytes(300, 300);

    return $jpeg.str_repeat("\0", $kilobytes * 1024 - strlen($jpeg));
}

/**
 * @param  array<string, mixed>  $data
 */
function pavPost(User $user, array $data): TestResponse
{
    return test()->actingAs($user)->post(route('provider.avatar.store'), $data);
}

const PAV_INVALID = "Il file non è una foto valida: usa un'immagine JPG, PNG o WebP.";

test('guests, unverified users and users without a provider are sent away', function () {
    $file = fn () => pavUpload('foto.jpg', pavImageBytes(300, 300));

    $this->post(route('provider.avatar.store'), ['avatar' => $file()])->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    Provider::factory()->create(['user_id' => $unverified->id]);
    pavPost($unverified, ['avatar' => $file()])->assertRedirect(route('verification.notice'));

    pavPost(User::factory()->create(), ['avatar' => $file()])->assertRedirect(route('provider.setup'));
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('a valid photo is saved under a random name, as WebP', function (string $name, string $type, int $width, int $height) {
    $provider = Provider::factory()->create();

    pavPost($provider->user, ['avatar' => pavUpload($name, pavImageBytes($width, $height, $type))])
        ->assertRedirect(route('provider.edit'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Foto aggiornata.']);

    $path = $provider->fresh()->avatar_path;
    expect($path)->toMatch('#^avatars/[A-Za-z0-9]{40}\.webp$#')->not->toContain(pathinfo($name, PATHINFO_FILENAME));
    expect(Storage::disk('public')->allFiles())->toBe([$path]);
    expect(getimagesizefromstring((string) Storage::disk('public')->get($path))['mime'])->toBe('image/webp');
    expect($provider->fresh()->avatarUrl())->toBe(Storage::disk('public')->url($path));
})->with([
    'JPEG 800x600' => ['mia-foto.jpg', 'jpeg', 800, 600],
    'PNG 300x300' => ['mia-foto.png', 'png', 300, 300],
    'WebP 500x500' => ['mia-foto.webp', 'webp', 500, 500],
]);

test('a second photo replaces the first, which is deleted from the disk', function () {
    $provider = Provider::factory()->create();

    pavPost($provider->user, ['avatar' => pavUpload('prima.jpg', pavImageBytes(300, 300))])->assertSessionHasNoErrors();
    $first = $provider->fresh()->avatar_path;
    pavPost($provider->user, ['avatar' => pavUpload('seconda.jpg', pavImageBytes(300, 300))])->assertSessionHasNoErrors();
    $second = $provider->fresh()->avatar_path;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    expect(Storage::disk('public')->allFiles())->toBe([$second]);
});

test('files that are not a valid photo are refused with nothing saved', function (Closure $data) {
    $provider = Provider::factory()->create(['avatar_path' => null]);

    pavPost($provider->user, $data())->assertSessionHasErrors(['avatar' => PAV_INVALID]);

    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect($provider->fresh()->avatar_path)->toBeNull();
})->with([
    // All of these are stopped by "mimes", which reads the real type of the content.
    'text named .jpg' => [fn () => ['avatar' => pavUpload('foto.jpg', 'solo testo, non una foto')]],
    'SVG named .png' => [fn () => ['avatar' => pavUpload('x.png', '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"><script>alert(1)</script></svg>')]],
    'SVG' => [fn () => ['avatar' => pavUpload('x.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"></svg>')]],
    'GIF' => [fn () => ['avatar' => pavUpload('x.gif', pavImageBytes(300, 300, 'gif'))]],
    'PDF' => [fn () => ['avatar' => pavUpload('x.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF")]],
    'PHP named .jpg' => [fn () => ['avatar' => pavUpload('x.jpg', '<?php echo "ciao"; ?>')]],
    'empty file' => [fn () => ['avatar' => pavUpload('vuoto.jpg', '')]],
    'not a file' => [fn () => ['avatar' => 'testo']],
]);

test('no file at all asks to choose a photo', function () {
    $provider = Provider::factory()->create();

    pavPost($provider->user, [])->assertSessionHasErrors(['avatar' => 'Scegli una foto.']);

    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect($provider->fresh()->avatar_path)->toBeNull();
});

test('a JPEG that passes the rules but cannot be decoded gets the unreadable message', function () {
    $jpeg = pavImageBytes(300, 300);
    // Only the header up to the frame size: "mimes" and "dimensions" pass, GD cannot decode it.
    $sof = (int) strpos($jpeg, "\xFF\xC0");
    $headerOnly = substr($jpeg, 0, $sof + 2 + unpack('n', substr($jpeg, $sof + 2, 2))[1]);
    $provider = Provider::factory()->create();

    pavPost($provider->user, ['avatar' => pavUpload('rotta.jpg', $headerOnly)])
        ->assertSessionHasErrors(['avatar' => 'Non riesco a leggere la foto. Prova con un altro file.']);

    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect($provider->fresh()->avatar_path)->toBeNull();
});

test('weight: 1536 KB passes, 1537 KB is refused', function () {
    $provider = Provider::factory()->create();

    pavPost($provider->user, ['avatar' => pavUpload('giusta.jpg', pavJpegOfSize(1536))])->assertSessionHasNoErrors();
    $saved = $provider->fresh()->avatar_path;

    pavPost($provider->user, ['avatar' => pavUpload('pesante.jpg', pavJpegOfSize(1537))])
        ->assertSessionHasErrors(['avatar' => 'La foto è troppo pesante: al massimo 1,5 MB.']);
    expect($provider->fresh()->avatar_path)->toBe($saved);
});

test('dimensions between 200 and 4000 pixels per side', function (int $width, int $height, bool $accepted) {
    $provider = Provider::factory()->create();

    $response = pavPost($provider->user, ['avatar' => pavUpload('foto.jpg', pavImageBytes($width, $height))]);

    if ($accepted) {
        $response->assertSessionHasNoErrors();
        expect($provider->fresh()->avatar_path)->not->toBeNull();
    } else {
        $response->assertSessionHasErrors(['avatar' => 'La foto deve essere tra 200 e 4000 pixel per lato.']);
        expect(Storage::disk('public')->allFiles())->toBe([]);
    }
})->with([
    '199x200' => [199, 200, false],
    '200x199' => [200, 199, false],
    '200x200' => [200, 200, true],
    '4000x4000' => [4000, 4000, true],
    '4001x100' => [4001, 100, false],
]);

test('a PNG named .jpg is accepted and saved as WebP', function () {
    $provider = Provider::factory()->create();

    pavPost($provider->user, ['avatar' => pavUpload('in-realta-png.jpg', pavImageBytes(300, 300, 'png'))])->assertSessionHasNoErrors();

    expect($provider->fresh()->avatar_path)->toEndWith('.webp');
});

test('the name of the uploaded file never enters the path', function (string $name) {
    $provider = Provider::factory()->create();

    pavPost($provider->user, ['avatar' => pavUpload($name, pavImageBytes(300, 300, 'png'))])->assertSessionHasNoErrors();

    expect($provider->fresh()->avatar_path)->toMatch('#^avatars/[A-Za-z0-9]{40}\.webp$#');
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
})->with(['../../x.png', 'a b.png', 'evil.php.png']);

test('a file over the PHP limit gets the weight message, not an error page', function (int $error) {
    $provider = Provider::factory()->create();
    // What PHP hands over for a file above upload_max_filesize: no content, an error code.
    $tooBig = new UploadedFile('/non-esiste', 'enorme.jpg', 'image/jpeg', $error, true);

    pavPost($provider->user, ['avatar' => $tooBig])
        ->assertRedirect()
        ->assertSessionHasErrors(['avatar' => 'La foto è troppo pesante: al massimo 1,5 MB.']);

    expect($provider->fresh()->avatar_path)->toBeNull();
})->with([
    'upload_max_filesize' => [UPLOAD_ERR_INI_SIZE],
    'MAX_FILE_SIZE of the form' => [UPLOAD_ERR_FORM_SIZE],
]);

test('another upload error gets the invalid file message', function () {
    $provider = Provider::factory()->create();
    $partial = new UploadedFile('/non-esiste', 'mezza.jpg', 'image/jpeg', UPLOAD_ERR_PARTIAL, true);

    pavPost($provider->user, ['avatar' => $partial])->assertSessionHasErrors(['avatar' => PAV_INVALID]);
});

test('user_id, is_published, slug and avatar_path in the body are ignored', function () {
    $provider = Provider::factory()->create(['slug' => 'il-mio', 'is_published' => false]);
    $other = Provider::factory()->create(['slug' => 'altro', 'avatar_path' => null]);

    pavPost($provider->user, [
        'avatar' => pavUpload('foto.jpg', pavImageBytes(300, 300)),
        'user_id' => $other->user_id,
        'is_published' => '1',
        'slug' => 'cambiato',
        'avatar_path' => 'avatars/scelto-da-me.webp',
    ])->assertSessionHasNoErrors();

    $after = $provider->fresh();
    expect($after->user_id)->toBe($provider->user_id);
    expect($after->is_published)->toBeFalse();
    expect($after->slug)->toBe('il-mio');
    expect($after->avatar_path)->not->toBe('avatars/scelto-da-me.webp');
    expect($other->fresh()->avatar_path)->toBeNull();
});

test('the photo of another provider is not touched', function () {
    Storage::disk('public')->put('avatars/di-un-altro.webp', 'altro');
    $other = Provider::factory()->create(['avatar_path' => 'avatars/di-un-altro.webp']);
    $provider = Provider::factory()->create();

    pavPost($provider->user, ['avatar' => pavUpload('foto.jpg', pavImageBytes(300, 300))])->assertSessionHasNoErrors();

    expect($other->fresh()->avatar_path)->toBe('avatars/di-un-altro.webp');
    Storage::disk('public')->assertExists('avatars/di-un-altro.webp');
});

test('the 11th upload in a minute of the same user is refused', function () {
    $provider = Provider::factory()->create();

    for ($i = 1; $i <= 10; $i++) {
        pavPost($provider->user, ['avatar' => pavUpload('foto.jpg', pavImageBytes(200, 200))])->assertSessionHasNoErrors();
    }
    $saved = $provider->fresh()->avatar_path;

    pavPost($provider->user, ['avatar' => pavUpload('foto.jpg', pavImageBytes(200, 200))])
        ->assertRedirect(route('provider.edit'))
        ->assertSessionHasErrors(['avatar' => 'Hai fatto troppe richieste in poco tempo. Riprova tra qualche minuto.']);
    expect($provider->fresh()->avatar_path)->toBe($saved);

    // Per user: another provider can still upload.
    $other = Provider::factory()->create();
    pavPost($other->user, ['avatar' => pavUpload('foto.jpg', pavImageBytes(200, 200))])->assertSessionHasNoErrors();

    $this->travel(61)->seconds();
    pavPost($provider->user, ['avatar' => pavUpload('foto.jpg', pavImageBytes(200, 200))])->assertSessionHasNoErrors();
});

test('the edit page gets avatar_url, null without a photo', function () {
    $provider = Provider::factory()->create(['avatar_path' => null]);

    $this->actingAs($provider->user)
        ->get(route('provider.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('provider', 8)
            ->where('provider.avatar_url', null));
});

test('the dashboard and the public page show the new photo', function () {
    $provider = Provider::factory()->published()->create();

    pavPost($provider->user, ['avatar' => pavUpload('foto.jpg', pavImageBytes(300, 300))])->assertSessionHasNoErrors();
    $url = Storage::disk('public')->url($provider->fresh()->avatar_path);

    $this->actingAs($provider->user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('provider.avatar_url', $url));
    $this->get(route('providers.show', $provider->slug))
        ->assertInertia(fn (Assert $page) => $page->where('provider.avatar_url', $url));
});

test('the number of queries does not depend on the previous photo', function () {
    Storage::disk('public')->put('avatars/vecchia.webp', 'vecchia');
    $withPhoto = Provider::factory()->create(['avatar_path' => 'avatars/vecchia.webp']);
    $withoutPhoto = Provider::factory()->create(['avatar_path' => null]);
    $uploads = [pavUpload('a.jpg', pavImageBytes(300, 300)), pavUpload('b.jpg', pavImageBytes(300, 300))];

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    pavPost($withoutPhoto->user, ['avatar' => $uploads[0]])->assertSessionHasNoErrors();
    $countWithout = $queries;

    $queries = 0;
    pavPost($withPhoto->user, ['avatar' => $uploads[1]])->assertSessionHasNoErrors();

    expect($queries)->toBe($countWithout);
});

test('the Italian texts are exactly the expected ones', function () {
    expect(__('provider.validation.avatar_required'))->toBe('Scegli una foto.');
    expect(__('provider.validation.avatar_invalid'))->toBe(PAV_INVALID);
    expect(__('provider.validation.avatar_max'))->toBe('La foto è troppo pesante: al massimo 1,5 MB.');
    expect(__('provider.validation.avatar_dimensions'))->toBe('La foto deve essere tra 200 e 4000 pixel per lato.');
    expect(__('provider.validation.avatar_unreadable'))->toBe('Non riesco a leggere la foto. Prova con un altro file.');
    expect(__('provider.avatar.saved'))->toBe('Foto aggiornata.');
    expect(__('provider.avatar.title'))->toBe('La tua foto');
    expect(__('provider.avatar.help'))->toBe('JPG, PNG o WebP, al massimo 1,5 MB, almeno 200 pixel per lato. Viene ridimensionata.');
    expect(__('provider.avatar.label'))->toBe('Scegli una foto');
    expect(__('provider.avatar.submit'))->toBe('Carica la foto');
});
