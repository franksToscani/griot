<?php

use Illuminate\Support\Facades\Process;

// The zoom of the photos of the work and "Vedi tutte le foto", PR E (issue #143, docs/gallery.md):
// the small rules run with Node, the source of the zoom and of the mosaic's buttons, the texts.

function glbSource(string $path = 'js/components/photo-lightbox.tsx'): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * Run resources/js/lib/gallery.ts with Node and give back what the calls printed.
 *
 * @return array<string, mixed>
 */
function glbRun(string $calls): array
{
    $script = sys_get_temp_dir().'/griot-lightbox-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path('js/lib/gallery.ts');
    file_put_contents($script, "import * as g from '{$helper}';\nconsole.log(JSON.stringify({$calls}));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------------------------
// The small rules, run with Node
// ---------------------------------------------------------------------------------------------

test('the photos shown at each width, and when "Vedi tutte le foto" is there', function () {
    $result = glbRun('[0, 1, 3, 4, 5, 6, 9].map((count) => [count, g.mosaicLayout(count).narrow, g.mosaicLayout(count).wide, g.seeAllPhotos(count)])');

    // [photos, shown below 768 px, shown from 768 px up, "Vedi tutte"]
    expect($result)->toBe([
        [0, 0, 0, 'never'],
        [1, 1, 1, 'never'],
        [3, 3, 3, 'never'],
        // Below 768 px only: on a wide screen every photo is already in the mosaic.
        [4, 3, 4, 'narrow'],
        [5, 3, 5, 'narrow'],
        [6, 3, 5, 'always'],
        [9, 3, 5, 'always'],
    ]);
});

test('previous and next go round, also with one or two photos', function () {
    $result = glbRun('{
        one: [g.nextIndex(0, 1), g.previousIndex(0, 1)],
        two: [g.nextIndex(0, 2), g.nextIndex(1, 2), g.previousIndex(0, 2), g.previousIndex(1, 2)],
        six: [g.nextIndex(2, 6), g.nextIndex(5, 6), g.previousIndex(3, 6), g.previousIndex(0, 6)],
        none: [g.nextIndex(0, 0), g.previousIndex(0, 0)],
    }');

    expect($result)->toBe([
        'one' => [0, 0],
        'two' => [1, 0, 1, 0],
        // After the last the first, before the first the last.
        'six' => [3, 0, 2, 5],
        'none' => [0, 0],
    ]);
});

test('the place of a photo, "Foto N di M", from its index', function () {
    $result = glbRun("(() => {
        const t = (key, r = {}) => Object.entries(r).reduce((text, [k, v]) => text.split(':' + k).join(String(v)), key + '|:number/:total');
        return [g.photoPosition(t, 0, 1), g.photoPosition(t, 1, 6), g.photoPosition(t, 8, 9)];
    })()");

    expect($result)->toBe([
        'providers.show.lightbox.title|1/1',
        'providers.show.lightbox.title|2/6',
        'providers.show.lightbox.title|9/9',
    ]);
});

// ---------------------------------------------------------------------------------------------
// The zoom
// ---------------------------------------------------------------------------------------------

test('the zoom is the kit\'s Dialog, with its title and description, and no heading of its own', function () {
    $source = glbSource();

    expect($source)
        ->toContain("from '@/components/ui/dialog';")
        ->toMatch('/<DialogTitle>\s*\{photoPosition\(t, current, total\)\}\s*<\/DialogTitle>/')
        ->toMatch("/<DialogDescription className=\"sr-only\">\\s*\\{several \\? t\\('providers\\.show\\.lightbox\\.keys'\\) : alt\\}\\s*<\\/DialogDescription>/")
        ->not->toMatch('/<h[1-6]\b/')
        ->not->toContain('aria-label');
    expect(substr_count($source, '<DialogTitle>'))->toBe(1);
});

test('the focus goes back to the button that opened the zoom', function () {
    // The dialog has no Trigger (one dialog, many buttons): Radix would give the focus to nothing.
    expect(glbSource())
        ->not->toContain('DialogTrigger')
        ->toMatch('/onCloseAutoFocus=\{\(event\) => \{\s*event\.preventDefault\(\);\s*opener\.current\?\.focus\(\);\s*\}\}/');
    expect(glbSource('js/components/provider-mosaic.tsx'))
        ->toMatch('/const open = \(event: MouseEvent<HTMLButtonElement>, index: number\) => \{\s*opener\.current = event\.currentTarget;\s*setOpenIndex\(index\);\s*\};/')
        ->toMatch('/<PhotoLightbox[\s\S]*?opener=\{opener\}\s*\/>/');
});

test('previous and next: named with t(), going round, not there with one photo', function () {
    $source = glbSource();

    expect($source)
        ->toContain('const several = total > 1;')
        ->toContain('const previous = () => go(previousIndex(current, total));')
        ->toContain('const next = () => go(nextIndex(current, total));')
        ->toMatch("/\\{several && \\(\\s*<div[^>]*>\\s*<Button[^>]*onClick=\\{previous\\}\\s*>\\s*<ChevronLeft aria-hidden=\"true\" \\/>\\s*\\{t\\('providers\\.show\\.lightbox\\.previous'\\)\\}\\s*<\\/Button>\\s*<Button[^>]*onClick=\\{next\\}\\s*>\\s*\\{t\\('providers\\.show\\.lightbox\\.next'\\)\\}/")
        // Never disabled: going round, there is always somewhere to go.
        ->not->toMatch('/\bdisabled[=}]/');
    expect(substr_count($source, 'onClick='))->toBe(2);
});

test('the left and right arrows change the photo, only with the focus inside the dialog', function () {
    $source = glbSource();

    expect($source)
        ->toMatch('/<DialogContent[^>]*?onKeyDown=\{onKeyDown\}/')
        ->toMatch("/if \\(event\\.key === 'ArrowLeft'\\) \\{\\s*event\\.preventDefault\\(\\);\\s*previous\\(\\);\\s*\\} else if \\(event\\.key === 'ArrowRight'\\) \\{\\s*event\\.preventDefault\\(\\);\\s*next\\(\\);\\s*\\}/")
        // Not with one photo, and not with a modifier (Alt+Left is "back" in the browser).
        ->toMatch('/if \(\s*!several \|\|\s*event\.altKey \|\|\s*event\.ctrlKey \|\|\s*event\.metaKey \|\|\s*event\.shiftKey\s*\) \{\s*return;\s*\}/')
        // No listener on the whole page.
        ->not->toContain('addEventListener')
        ->not->toContain('window.')
        ->not->toContain('document.');
});

test('a change is announced in a status line, short, and the photo changes without any effect', function () {
    $source = glbSource();

    expect($source)
        ->toMatch('/<p role="status" className="sr-only">\s*\{announcement\}\s*<\/p>/')
        ->toMatch('/const go = \(target: number\) => \{\s*onIndexChange\(target\);\s*setAnnouncement\(photoPosition\(t, target, total\)\);\s*\};/')
        // Emptied on closing: opening again reads the title, not an old change.
        ->toMatch("/if \\(!open\\) \\{\\s*setAnnouncement\\(''\\);\\s*onClose\\(\\);/");
    // Changed only by the person: no timer, no movement of its own, nothing sticky.
    foreach ([$source, glbSource('js/components/provider-mosaic.tsx')] as $code) {
        expect($code)
            ->not->toMatch('/\b(setInterval|setTimeout|requestAnimationFrame|autoPlay|autoplay)\b/')
            ->not->toMatch('/\b(animate-|transition|duration-|hover:scale|scale-)/')
            ->not->toContain('sticky');
    }
});

test('only the open photo is loaded, at once, as large as the screen allows and never out of it', function () {
    $source = glbSource();
    preg_match_all('/<img[\s\S]*?\/>/', $source, $images);

    expect($images[0])->toHaveCount(1);
    expect($images[0][0])
        ->toContain('key={photo.id}')
        ->toContain('src={fallbackSrc(photo.variants)}')
        ->toContain('srcSet={srcSet(photo.variants)}')
        ->toContain('sizes="100vw"')
        ->toContain('width={photo.width}')
        ->toContain('height={photo.height}')
        ->toContain('alt={alt}')
        ->toContain('loading="eager"')
        ->toContain('object-contain')
        ->toContain('max-h-[60dvh]')
        ->toContain('forced-colors:border')
        ->not->toContain('fetchPriority');
    // The dialog scrolls inside rather than going out of the screen (zoom at 200%).
    expect($source)->toContain('className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-4xl"')
        ->not->toContain('new Image')
        ->not->toContain('preload');
});

// ---------------------------------------------------------------------------------------------
// "Vedi tutte le foto"
// ---------------------------------------------------------------------------------------------

test('"Vedi tutte le foto" is a button of the kit, shown only when some photos are not in the mosaic', function () {
    expect(glbSource('js/components/provider-mosaic.tsx'))
        ->toContain('const seeAll = seeAllPhotos(photos.length);')
        ->toMatch("/\\{seeAll !== 'never' && \\(\\s*<Button\\s+type=\"button\"\\s+variant=\"outline\"\\s+aria-haspopup=\"dialog\"\\s+onClick=\\{\\(event\\) => open\\(event, 0\\)\\}\\s+className=\\{cn\\('mt-2', seeAll === 'narrow' && 'md:hidden'\\)\\}\\s*>\\s*\\{t\\('providers\\.show\\.see_all', \\{ count: photos\\.length \\}\\)\\}\\s*<\\/Button>\\s*\\)\\}/");
    // The kit's Button is 44px high on phones.
    expect(glbSource('js/components/ui/button.tsx'))->toContain('max-md:min-h-11 max-md:min-w-11');
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts of the zoom exist in Italian, French and English, with the same placeholders', function () {
    $json = fn (string $locale) => json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        sort($found[0]);

        return $found[0];
    };

    preg_match_all("/'(providers\\.show\\.(?:lightbox\\.[a-z_]+|open_photo|see_all))'/", glbSource().glbSource('js/components/provider-mosaic.tsx').glbSource('js/lib/gallery.ts'), $used);
    $keys = array_values(array_unique($used[1]));
    sort($keys);
    expect($keys)->toBe([
        'providers.show.lightbox.keys',
        'providers.show.lightbox.next',
        'providers.show.lightbox.previous',
        'providers.show.lightbox.title',
        'providers.show.open_photo',
        'providers.show.see_all',
    ]);

    foreach ($keys as $key) {
        foreach (['it', 'fr', 'en'] as $locale) {
            expect($json($locale))->toHaveKey($key)
                ->and($placeholders($json($locale)[$key]))->toBe($placeholders($json('it')[$key]), "{$locale} {$key}");
        }
        expect(preg_match('/ [:?!;](?![a-z])/', $json('fr')[$key]))->toBe(0, "fr {$key}");
    }

    expect(__('providers.show.lightbox.title', ['number' => 2, 'total' => 6], 'it'))->toBe('Foto 2 di 6')
        ->and(__('providers.show.lightbox.title', ['number' => 2, 'total' => 6], 'fr'))->toBe('Photo 2 sur 6')
        ->and(__('providers.show.see_all', ['count' => 9], 'it'))->toBe('Vedi tutte le foto (9)')
        ->and(__('providers.show.open_photo', ['number' => 2, 'total' => 6], 'it'))->toBe('(apri la foto 2 di 6 ingrandita)')
        ->and(__('providers.show.lightbox.previous', [], 'en'))->toBe('Previous photo')
        ->and(__('providers.show.lightbox.next', [], 'fr'))->toBe('Photo suivante');
});
