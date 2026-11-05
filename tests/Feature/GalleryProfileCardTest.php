<?php

use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Support\MediaDisk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

// The gallery card of the provider's edit page, PR C (issue #143, docs/gallery.md): the data of
// the edit page, the card's source and its small rules, and its texts.

beforeEach(function () {
    Storage::fake(MediaDisk::NAME);
});

function gpcSource(string $path = 'js/components/gallery-form.tsx'): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * The props of the edit page, for the logged-in provider.
 *
 * @return array<string, mixed>
 */
function gpcEditProps(Provider $provider): array
{
    $props = [];

    test()->actingAs($provider->user)
        ->get(route('provider.edit'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $page->component('provider/edit');
            $props = $page->toArray()['props']['provider'];
        });

    return $props;
}

// ---------------------------------------------------------------------------------------------
// The data
// ---------------------------------------------------------------------------------------------

test('the edit page gets its own photos in their order, with only what the card shows', function () {
    $provider = Provider::factory()->create();
    $third = ProviderPhoto::factory()->for($provider)->create(['position' => 3, 'alt_text' => null]);
    $first = ProviderPhoto::factory()->for($provider)->create(['position' => 1, 'alt_text' => 'Box braids lunghe']);
    $second = ProviderPhoto::factory()->for($provider)->create(['position' => 2, 'width' => 800, 'height' => 533]);
    ProviderPhoto::factory()->create(['position' => 1]);

    $props = gpcEditProps($provider);

    expect(array_column($props['photos'], 'id'))->toBe([$first->id, $second->id, $third->id]);
    expect($props['photos'][0])->toBe([
        'id' => $first->id,
        'url' => MediaDisk::get()->url("gallery/{$first->name}-400.webp"),
        'width' => 1600,
        'height' => 1200,
        'alt_text' => 'Box braids lunghe',
        'position' => 1,
    ]);
    expect($props['photos'][1])->toMatchArray(['width' => 800, 'height' => 533, 'position' => 2])
        ->and($props['photos'][2]['alt_text'])->toBeNull();
    expect($props['gallery'])->toBe(['max_photos' => 9, 'min_side' => 800]);

    // Never the name, a path or the consent.
    $json = (string) json_encode($props['photos']);
    expect($json)->not->toContain('"name"')->not->toContain('consent')->not->toContain('"path');
    foreach ($props['photos'] as $photo) {
        expect(array_keys($photo))->toBe(['id', 'url', 'width', 'height', 'alt_text', 'position']);
    }
});

test('without photos the list is empty, and the limits come from the configuration', function () {
    config(['booking.gallery.max_photos' => 5]);

    $props = gpcEditProps(Provider::factory()->create());

    expect($props['photos'])->toBe([])
        ->and($props['gallery'])->toBe(['max_photos' => 5, 'min_side' => 800]);
});

test('the photos are read with one query, however many there are', function () {
    $count = function (Provider $provider): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        gpcEditProps($provider);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $none = Provider::factory()->create();
    $many = Provider::factory()->create();
    ProviderPhoto::factory()->count(9)->for($many)->sequence(fn ($sequence) => ['position' => $sequence->index + 1])->create();

    expect($count($many))->toBe($count($none));
});

test('the public page of the provider does not change: no photos, no gallery address', function () {
    $provider = Provider::factory()->published()->create();
    $photo = ProviderPhoto::factory()->for($provider)->create();

    $this->get(route('providers.show', $provider->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('providers/show')
            ->missing('provider.photos')
            ->missing('provider.gallery'))
        ->assertDontSee('gallery/')
        ->assertDontSee($photo->name);
});

// ---------------------------------------------------------------------------------------------
// The card
// ---------------------------------------------------------------------------------------------

test('the card sits right under the photo of the profile, with the photos and the limits', function () {
    $page = gpcSource('js/pages/provider/edit.tsx');

    expect($page)->toContain("import { GalleryForm } from '@/components/gallery-form';")
        ->toMatch('/<AvatarForm\s+name=\{provider\.name\}\s+avatarUrl=\{provider\.avatar_url\}\s*\/>\s*<GalleryForm\s+photos=\{provider\.photos\}\s+limits=\{provider\.gallery\}\s*\/>\s*<\/div>/');
});

test('the card has one h2, as the photo of the profile above it, and the title takes the focus', function () {
    $source = gpcSource();
    preg_match_all('/<h([1-6])\b/', $source, $levels);

    // The page has its h1, the photo card an h2; this card one h2 too (the dialog title is
    // Radix's own h2, inside the dialog).
    expect($levels[1])->toBe(['2'])
        ->and($source)->toMatch('/<h2\s+id=\{TITLE_ID\}\s+tabIndex=\{-1\}/')
        ->toContain("const TITLE_ID = 'gallery-title';")
        ->toContain('document.getElementById(TITLE_ID)?.focus();');
});

test('the photos are a list, each with its thumbnail and its own removal', function () {
    $source = gpcSource();

    expect($source)
        ->toMatch('/<ul className="grid grid-cols-1 gap-4 md:grid-cols-3">\s*\{photos\.map\(\(photo, index\) => \(\s*<li key=\{photo\.id\}/')
        ->toMatch('/<PhotoItem\s+photo=\{photo\}\s+number=\{index \+ 1\}\s+total=\{total\}\s*\/>/');

    // The thumbnail: the smallest size, its space kept, loaded only when near.
    preg_match('/<img[\s\S]*?\/>/', $source, $img);
    expect($img[0])
        ->toContain('src={photo.url}')
        ->toContain('width={photo.width}')
        ->toContain('height={photo.height}')
        ->toContain('alt={photoAlt(t, photo.alt_text, number, total)}')
        ->toContain('loading="lazy"')
        ->toContain('decoding="async"');
});

test('the remove button says which photo, with t() and sr-only text, never a written aria-label', function () {
    $source = gpcSource();

    expect($source)->toMatch("/<DialogTrigger asChild>[\\s\\S]*?<Button variant=\"outline\" size=\"sm\" className=\"self-start\">\\s*\\{t\\('provider\\.gallery\\.remove'\\)\\}\\s*<span className=\"sr-only\">\\s*\\{' '\\}\\s*\\{t\\('provider\\.gallery\\.remove_which', \\{ number, total \\}\\)\\}\\s*<\\/span>\\s*<\\/Button>\\s*<\\/DialogTrigger>/")
        ->not->toContain('aria-label');

    // 44px on phones: the kit's Button has it for every size, and nothing here takes it away.
    expect(gpcSource('js/components/ui/button.tsx'))->toContain('max-md:min-h-11 max-md:min-w-11');
    expect($source)->not->toMatch('/min-h-(?!11)|min-w-(?!11)/');

    expect(__('provider.gallery.remove', [], 'it').' '.__('provider.gallery.remove_which', ['number' => 2, 'total' => 6], 'it'))->toBe('Togli la foto 2 di 6')
        ->and(__('provider.gallery.remove', [], 'fr').' '.__('provider.gallery.remove_which', ['number' => 2, 'total' => 6], 'fr'))->toBe('Retirer la photo 2 sur 6')
        ->and(__('provider.gallery.remove', [], 'en').' '.__('provider.gallery.remove_which', ['number' => 2, 'total' => 6], 'en'))->toBe('Remove photo 2 of 6');
});

test('a removal is confirmed in the kit\'s Dialog, then the focus goes to the title', function () {
    $source = gpcSource();

    expect($source)
        ->toContain("from '@/components/ui/dialog';")
        ->toMatch('/<DialogContent[\s\S]*?<DialogTitle>\s*\{t\(\'provider\.gallery\.remove_title\', \{ number, total \}\)\}\s*<\/DialogTitle>\s*<DialogDescription>\s*\{t\(\'provider\.gallery\.remove_description\'\)\}/')
        // The DELETE request only inside the dialog, behind the confirmation.
        ->toMatch('/<DialogContent[\s\S]*?\{\.\.\.destroyPhoto\.form\(photoId\)\}[\s\S]*?<\/DialogContent>/')
        ->toMatch('/<DialogClose asChild>\s*<Button type="button" variant="secondary">\s*\{t\(\'provider\.gallery\.remove_cancel\'\)\}/')
        ->toMatch('/<Button\s+type="submit"\s+variant="destructive"[\s\S]*?\{t\(\'provider\.gallery\.remove_confirm\'\)\}/')
        ->toMatch('/onSuccess=\{\(\) => \{\s*removed\.current = true;\s*setOpen\(false\);\s*focusTitle\(\);\s*\}\}/');
    expect(substr_count($source, 'destroyPhoto.form('))->toBe(1);
});

test('the upload form: the right types, a described text field, the consent box, the button needs only a file', function () {
    $source = gpcSource();

    expect($source)
        ->toContain('id="gallery-form"')
        ->toContain('accept="image/jpeg,image/png,image/webp"')
        ->toMatch("/\\{\\.\\.\\.fieldA11y\\(\\s*'gallery-photo',\\s*form\\.errors\\.photo,\\s*'gallery-photo-help',?\\s*\\)\\}/")
        ->toMatch("/\\{\\.\\.\\.fieldA11y\\(\\s*'gallery-alt',\\s*form\\.errors\\.alt_text,\\s*'gallery-alt-help',?\\s*\\)\\}/")
        ->toMatch("/\\{\\.\\.\\.fieldA11y\\('gallery-consent', form\\.errors\\.consent\\)\\}/")
        ->toMatch("/<Label htmlFor=\"gallery-consent\"[^>]*>\\s*\\{t\\('provider\\.photos\\.consent'\\)\\}/")
        ->toContain('disabled={form.data.photo === null || busy}')
        ->toContain("onError: () => focusFirstInvalid('gallery-form')");

    // Emptied after a success: the data, and the file input, which React does not control.
    expect($source)->toMatch("/onSuccess: \\(\\) => \\{[\\s\\S]*?form\\.reset\\(\\);[\\s\\S]*?fileInput\\.current\\.value = '';/");
});

test('the photo is made smaller in the browser only when sent, and the wait is announced', function () {
    $source = gpcSource();

    // In the submit handler, never while rendering: no window, and document only in focusTitle.
    expect($source)
        ->toMatch('/const submit = async \(event: FormEvent<HTMLFormElement>\) => \{[\s\S]*?setPreparing\(true\);\s*const photo = await resizeImage\(original\);\s*form\.transform\(\(data\) => \(\{ \.\.\.data, photo \}\)\);/')
        ->not->toContain('window.');
    expect(substr_count($source, 'document.'))->toBe(1);

    expect($source)
        ->toContain('const busy = preparing || form.processing;')
        ->toContain('onFinish: () => setPreparing(false)')
        ->toMatch("/<p role=\"status\" className=\"text-sm text-muted-foreground\">\\s*\\{busy \\? t\\('provider\\.gallery\\.uploading'\\) : ''\\}\\s*<\\/p>/")
        // The spinner's own status would say it a second time.
        ->toContain('{busy && <Spinner aria-hidden="true" />}');
});

test('with no photos an invitation and no list; with the most photos a message and no form', function () {
    $source = gpcSource();

    expect($source)
        ->toMatch("/\\{state === 'empty' \\? \\(\\s*<p className=\"text-sm text-muted-foreground\">\\s*\\{t\\('provider\\.gallery\\.empty'\\)\\}\\s*<\\/p>\\s*\\) : \\(/")
        ->toMatch("/\\{state === 'full' \\? \\(\\s*<p className=\"text-sm text-muted-foreground\">\\s*\\{t\\('provider\\.gallery\\.full', \\{\\s*max: limits\\.max_photos,?\\s*\\}\\)\\}\\s*<\\/p>\\s*\\) : \\(\\s*<UploadForm minSide=\\{limits\\.min_side\\} \\/>\\s*\\)\\}/")
        ->toMatch("/t\\('provider\\.gallery\\.count', \\{\\s*count: total,\\s*max: limits\\.max_photos,?\\s*\\}\\)/");
});

test('no colour of its own, no sticky element, one column on phones', function () {
    $source = gpcSource();

    // Errors through InputError, the removal through the kit's destructive style.
    expect($source)
        ->not->toMatch('/\b(text|bg|border)-(red|green|decor|destructive)\b/')
        ->not->toContain('sticky')
        ->not->toContain('fixed')
        ->not->toContain('outline-none')
        ->not->toMatch('/\banimate-/');
    expect(preg_match_all('/(?<!md:)grid-cols-([0-9]+)/', $source, $columns))->toBe(1)
        ->and($columns[1])->toBe(['1']);
});

// ---------------------------------------------------------------------------------------------
// The small rules, run with Node
// ---------------------------------------------------------------------------------------------

test('the state of the card and the alternative text of a thumbnail', function () {
    $script = sys_get_temp_dir().'/griot-gallery-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path('js/lib/gallery.ts');
    // A t() as the hook's: the key, with its placeholders written in.
    file_put_contents($script, "import * as g from '{$helper}';
const t = (key, r = {}) => Object.entries(r).reduce((text, [k, v]) => text.split(':' + k).join(String(v)), key + '|:number/:total');
console.log(JSON.stringify({
    states: [[0, 9], [1, 9], [8, 9], [9, 9], [10, 9], [0, 0]].map(([c, m]) => g.galleryState(c, m)),
    own: g.photoAlt(t, 'Box braids lunghe', 2, 6),
    trimmed: g.photoAlt(t, '  Treccine  ', 1, 1),
    none: g.photoAlt(t, null, 2, 6),
    blank: g.photoAlt(t, '   ', 3, 9),
}));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'states' => ['empty', 'open', 'open', 'full', 'full', 'full'],
        'own' => 'Box braids lunghe',
        'trimmed' => 'Treccine',
        'none' => 'provider.gallery.fallback_alt|2/6',
        'blank' => 'provider.gallery.fallback_alt|3/9',
    ]);
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts of the card exist in Italian, French and English, with the same placeholders', function () {
    $json = fn (string $locale) => json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        sort($found[0]);

        return $found[0];
    };

    // Every key the card uses, and the one of the consent from PR B.
    preg_match_all("/t\\(\\s*'([a-z_.]+)'/", gpcSource().gpcSource('js/lib/gallery.ts'), $used);
    $keys = array_values(array_unique($used[1]));
    expect($keys)->toContain('provider.photos.consent', 'provider.gallery.fallback_alt')
        ->and(count($keys))->toBe(19);

    foreach ($keys as $key) {
        expect($json('it'))->toHaveKey($key);
        foreach (['fr', 'en'] as $locale) {
            expect($json($locale))->toHaveKey($key)
                ->and($placeholders($json($locale)[$key]))->toBe($placeholders($json('it')[$key]), "{$locale} {$key}");
        }

        // French: a no-break space before : ? ! ; and never a plain one (":max" is a placeholder).
        expect(preg_match('/ [:?!;](?![a-z])/', $json('fr')[$key]))->toBe(0, "fr {$key}");
    }

    expect(__('provider.gallery.count', ['count' => 2, 'max' => 9], 'it'))->toBe('2 di 9 foto')
        ->and(__('provider.gallery.full', ['max' => 9], 'it'))->toBe("Hai raggiunto il massimo di 9 foto. Togline una per aggiungerne un'altra.")
        ->and(__('provider.gallery.fallback_alt', ['number' => 2, 'total' => 6], 'it'))->toBe('Foto 2 di 6')
        ->and(__('provider.gallery.alt_label', [], 'it'))->toBe('Descrizione della foto (facoltativa)')
        ->and(__('provider.gallery.submit', [], 'it'))->toBe('Aggiungi la foto')
        ->and(__('provider.gallery.uploading', [], 'it'))->toBe('Caricamento in corso…')
        ->and(__('provider.gallery.remove_title', ['number' => 2, 'total' => 6], 'fr'))->toBe("Retirer la photo 2 sur 6\u{00A0}?")
        ->and(__('provider.gallery.description', ['max' => 9], 'fr'))->toStartWith("Montrez votre travail\u{00A0}: ajoutez de 3 à 9 photos.");
});
