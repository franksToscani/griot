<?php

use Illuminate\Support\Facades\Process;

// The upload of several photos, PR 2 of #53 (docs/gallery.md): a queue in the browser, one photo
// at a time towards the route that already takes one, one consent for the group, the files over
// the free places refused before anything is sent; and the photo of the profile, larger. The
// server does not change (GalleryUploadTest). The queue's rules run with Node, the page is read
// in its source.

function guqSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * Runs a script with Node against lib/upload-queue.ts, with t() reading lang/it.json, and gives
 * back what it printed.
 *
 * @return array<string, mixed>
 */
function guqNode(string $body): array
{
    $script = sys_get_temp_dir().'/griot-guq-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path('js/lib/upload-queue.ts');
    $lang = lang_path('it.json');
    file_put_contents($script, "import * as q from '{$helper}';
import { readFileSync } from 'node:fs';
const texts = JSON.parse(readFileSync('{$lang}', 'utf8'));
const t = (key, r = {}) => Object.entries(r).reduce((text, [k, v]) => text.split(':' + k).join(String(v)), texts[key] ?? key);
{$body}
");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------------------------
// The queue, with Node
// ---------------------------------------------------------------------------------------------

test('the queue sends one file at a time, in the order chosen; the saved leave, the refused stay with their reason', function () {
    $out = guqNode("
let id = 0;
const chosen = q.addFiles([], [{ name: 'a.jpg' }, { name: 'pesante.jpg' }, { name: 'c.jpg' }], 15, () => ++id);
let inFlight = 0, most = 0;
const order = [], seen = [];
const send = async (item, onProgress) => {
    inFlight++; most = Math.max(most, inFlight); order.push(item.file.name);
    onProgress(50);
    await new Promise((resolve) => setTimeout(resolve, 5));
    inFlight--;
    return item.file.name === 'pesante.jpg' ? { ok: false, error: 'La foto è troppo pesante: al massimo 4 MB.' } : { ok: true };
};
let last = chosen.items;
const summary = await q.runQueue(chosen.items, send, (items) => { last = items; seen.push(items.map((i) => i.file.name + ':' + i.state + ':' + i.progress).join(' ')); });
const retried = q.retryItem(last, last[0].id);
console.log(JSON.stringify({
    ids: chosen.items.map((i) => i.id), states: chosen.items.map((i) => i.state),
    order, most, summary, seen,
    left: last.map((i) => ({ name: i.file.name, state: i.state, error: i.error })),
    retried: retried.map((i) => i.state + ':' + i.error),
    next: q.nextQueued(retried)?.file.name,
    removed: q.removeItem(retried, retried[0].id).length,
}));
");

    expect($out['ids'])->toBe([1, 2, 3])
        ->and($out['states'])->toBe(['queued', 'queued', 'queued'])
        // Never two at once, in the order of the choice.
        ->and($out['most'])->toBe(1)
        ->and($out['order'])->toBe(['a.jpg', 'pesante.jpg', 'c.jpg'])
        ->and($out['summary'])->toBe(['added' => 2, 'failed' => 1])
        // Each step: sending, its progress, then saved (gone) or refused (with its reason).
        ->and($out['seen'])->toBe([
            'a.jpg:uploading:0 pesante.jpg:queued:0 c.jpg:queued:0',
            'a.jpg:uploading:50 pesante.jpg:queued:0 c.jpg:queued:0',
            'pesante.jpg:queued:0 c.jpg:queued:0',
            'pesante.jpg:uploading:0 c.jpg:queued:0',
            'pesante.jpg:uploading:50 c.jpg:queued:0',
            'pesante.jpg:error:0 c.jpg:queued:0',
            'pesante.jpg:error:0 c.jpg:uploading:0',
            'pesante.jpg:error:0 c.jpg:uploading:50',
            'pesante.jpg:error:0',
        ])
        ->and($out['left'])->toBe([['name' => 'pesante.jpg', 'state' => 'error', 'error' => 'La foto è troppo pesante: al massimo 4 MB.']])
        // « Riprova »: back in the queue without its error, and the next to go.
        ->and($out['retried'])->toBe(['queued:null'])
        ->and($out['next'])->toBe('pesante.jpg')
        ->and($out['removed'])->toBe(0);
});

test('the free places: the files over them are refused before anything is sent', function () {
    $out = guqNode("
let id = 0;
const files = [1, 2, 3, 4, 5].map((n) => ({ name: n + '.jpg' }));
const free = q.freeSlots(15, 12, []);
const chosen = q.addFiles([], files, free, () => ++id);
const waiting = q.updateItem(chosen.items, 1, { state: 'error' });
console.log(JSON.stringify({
    free,
    kept: chosen.items.map((i) => i.file.name),
    refused: chosen.refused.map((f) => f.name),
    // A refused file still holds its place until it is sent again or taken away.
    afterChoice: q.freeSlots(15, 12, waiting),
    afterSaved: q.freeSlots(15, 13, q.removeItem(waiting, 2)),
    full: q.freeSlots(15, 15, []),
    over: q.freeSlots(15, 16, []),
    none: q.addFiles(waiting, [{ name: 'x.jpg' }], 0, () => ++id).refused.length,
    refusedOne: q.refusedText(t, 1, 15),
    refusedMany: q.refusedText(t, 2, 15),
}));
");

    expect($out)->toBe([
        'free' => 3,
        'kept' => ['1.jpg', '2.jpg', '3.jpg'],
        'refused' => ['4.jpg', '5.jpg'],
        'afterChoice' => 0,
        'afterSaved' => 0,
        'full' => 0,
        'over' => 0,
        'none' => 1,
        'refusedOne' => "1 foto non aggiunta all'elenco: la galleria ne tiene al massimo 15.",
        'refusedMany' => "2 foto non aggiunte all'elenco: la galleria ne tiene al massimo 15.",
    ]);
});

test('the summary at the end, with the plural of the project, and the reason of each file from the server', function () {
    $out = guqNode("
console.log(JSON.stringify({
    mixed: q.summaryText(t, { added: 7, failed: 2 }),
    oneEach: q.summaryText(t, { added: 1, failed: 1 }),
    added: q.summaryText(t, { added: 3, failed: 0 }),
    failed: q.summaryText(t, { added: 0, failed: 1 }),
    nothing: q.summaryText(t, { added: 0, failed: 0 }),
    photo: q.uploadError({ photo: 'Troppo pesante', consent: 'Consenso' }, 'Riprova'),
    alt: q.uploadError({ alt_text: 'Troppo lunga' }, 'Riprova'),
    consent: q.uploadError({ consent: 'Consenso' }, 'Riprova'),
    none: q.uploadError({}, 'Non salvata'),
}));
");

    expect($out)->toBe([
        'mixed' => '7 foto aggiunte, 2 foto non caricate.',
        'oneEach' => '1 foto aggiunta, 1 foto non caricata.',
        'added' => '3 foto aggiunte.',
        'failed' => '1 foto non caricata.',
        'nothing' => null,
        'photo' => 'Troppo pesante',
        'alt' => 'Troppo lunga',
        'consent' => 'Consenso',
        'none' => 'Non salvata',
    ]);
});

// ---------------------------------------------------------------------------------------------
// The form, in its source
// ---------------------------------------------------------------------------------------------

test('several files at once, each with its description, its state and a native progress bar', function () {
    $source = guqSource('js/components/gallery-form.tsx');

    expect($source)
        ->toMatch('/<Input\s+id="gallery-photo"\s+type="file"\s+multiple\s+accept="image\/jpeg,image\/png,image\/webp"/')
        ->toContain('const result = addFiles(items, chosen, free, () => ++lastId.current);')
        ->toMatch('/<Label htmlFor=\{`upload-\$\{item\.id\}-alt`\}>\s*\{t\(\'provider\.gallery\.queue\.alt_label\', \{\s*name: item\.file\.name,?\s*\}\)\}/')
        ->toMatch('/<Input\s+id=\{`upload-\$\{item\.id\}-alt`\}\s+maxLength=\{150\}/')
        // The file's name is the native label of its progress bar.
        ->toMatch('/<label\s+htmlFor=\{`upload-\$\{item\.id\}-progress`\}[^>]*>\s*\{item\.file\.name\}\s*<\/label>/')
        ->toMatch('/<progress\s+id=\{`upload-\$\{item\.id\}-progress`\}\s+value=\{item\.progress\}\s+max=\{100\}/')
        ->toContain('{stateText(t, item)}')
        ->toMatch("/\\{item\\.state === 'error' && \\(\\s*<Button[\\s\\S]*?onClick=\\{\\(\\) => retry\\(item\\.id\\)\\}/")
        ->toMatch('/removeItem\(items, item\.id\)/');
});

test('one at a time: the queue of lib/upload-queue.ts, one request per photo, never several together', function () {
    $source = guqSource('js/components/gallery-form.tsx');
    $queue = guqSource('js/lib/upload-queue.ts');

    expect($source)
        ->toMatch('/summary = await runQueue\(\s*list,/')
        ->not->toContain('Promise.all')
        ->not->toContain('Promise.allSettled')
        ->and(substr_count($source, 'router.post('))->toBe(1);
    // Inside the queue: the next file only after the one before has finished.
    expect($queue)
        ->toMatch('/for \(\s*let item = nextQueued\(current\);\s*item !== undefined;\s*item = nextQueued\(current\)\s*\) \{/')
        ->toContain('const result = await send(item,')
        ->not->toContain('Promise.all');
    // The route of one photo, as before: made smaller first, the page kept.
    expect($source)
        ->toMatch('/return resizeImage\(item\.file\)\.then\(/')
        ->toMatch('/router\.post\(\s*storePhoto\.url\(\),\s*\{ photo, alt_text: item\.alt, consent: true \},/')
        ->toContain('preserveState: true,')
        ->toContain('forceFormData: true,');
});

test('one consent for the group, required before sending, and sent with every photo', function () {
    $source = guqSource('js/components/gallery-form.tsx');

    expect(substr_count($source, '<Checkbox'))->toBe(1)
        ->and(substr_count($source, 'id="gallery-consent"'))->toBe(1);
    expect($source)
        ->toMatch("/if \\(!consent\\) \\{\\s*setConsentError\\(t\\('provider\\.gallery\\.queue\\.consent_required'\\)\\);\\s*focusFirstInvalid\\('gallery-form'\\);\\s*return;\\s*\\}/")
        ->toMatch("/<Label htmlFor=\"gallery-consent\"[^>]*>\\s*\\{t\\('provider\\.photos\\.consent'\\)\\}/")
        ->toContain('{ photo, alt_text: item.alt, consent: true }');
    // The box is outside the list: never one per photo.
    preg_match('/\{items\.map\(\(item\) => \([\s\S]*?<\/li>/', $source, $row);
    expect($row[0])->not->toContain('consent');
});

test('at the end: the focus on the title of the card, one summary, the photos\' own toasts left out meanwhile', function () {
    $source = guqSource('js/components/gallery-form.tsx');

    expect($source)
        ->toMatch("/silenceFlashToast\\(t\\('provider\\.photos\\.saved'\\)\\);[\\s\\S]*?\\} finally \\{\\s*silenceFlashToast\\(null\\);\\s*setRunning\\(false\\);\\s*\\}/")
        ->toMatch('/const text = summaryText\(t, summary\);\s*setStatus\(text \?\? \'\'\);[\s\S]*?toast\.error\(text\);[\s\S]*?toast\.success\(text\);[\s\S]*?focusTitle\(\);\s*\};/')
        // The title takes the focus without a ring, as the other titles reached by script.
        ->toMatch('/<h2\s+id=\{TITLE_ID\}\s+tabIndex=\{-1\}\s+className=\{`text-xl leading-none font-semibold \$\{CARD_TITLE_FOCUS\}`\}/')
        ->toMatch('/<p role="status" className="text-sm text-muted-foreground">\\s*\\{status\\}\\s*<\\/p>/');
    // Only the photos' own message: the other toasts (a profile saved meanwhile) still show.
    expect(guqSource('js/hooks/use-flash-toast.ts'))
        ->toContain('if (!data || data.message === silenced) {')
        ->toContain('export function silenceFlashToast(message: string | null): void {');
    // The highlight after a save (useFlashFocus) is not touched by the gallery.
    expect($source)->not->toContain('useFlashFocus')->not->toContain('flashFocus');
});

// ---------------------------------------------------------------------------------------------
// The photo of the profile, larger
// ---------------------------------------------------------------------------------------------

test('the photo of the profile opens larger from a native button, in the kit\'s Dialog, with the focus back on closing', function () {
    $page = guqSource('js/pages/provider/edit.tsx');

    preg_match('/function AvatarPreview\([\s\S]*?\n\}\n/', $page, $preview);
    expect($preview)->toHaveCount(1);
    expect($preview[0])
        // Only when there is a photo.
        ->toMatch('/if \(avatarUrl === null\) \{\s*return preview;\s*\}/')
        // A Trigger of its own: Radix gives the focus back to it, Esc and the X close.
        ->toMatch('/<DialogTrigger asChild>\s*<button\s+type="button"/')
        ->toMatch("/\\{t\\('provider\\.avatar\\.zoom'\\)\\}\\s*<\\/span>\\s*<\\/button>\\s*<\\/DialogTrigger>/")
        ->toMatch("/<DialogTitle>\\s*\\{t\\('provider\\.avatar\\.alt', \\{ name \\}\\)\\}\\s*<\\/DialogTitle>/")
        ->toMatch('/<img\s+src=\{avatarUrl\}\s+alt=\{t\(\'provider\.avatar\.alt\', \{ name \}\)\}/')
        ->toContain('focus-visible:ring-[3px]')
        ->not->toContain('onClick')
        ->not->toMatch('/aria-label(?!ledby)/');
    expect($page)->toContain('<AvatarPreview name={name} avatarUrl={avatarUrl} />')
        ->toMatch("/import \\{\\s*Dialog,\\s*DialogContent,\\s*DialogDescription,\\s*DialogHeader,\\s*DialogTitle,\\s*DialogTrigger,?\\s*\\} from '@\\/components\\/ui\\/dialog';/");

    // Nothing new to install: the same packages as before.
    $package = json_decode((string) file_get_contents(base_path('package.json')), true);
    expect(array_keys($package['dependencies']))->not->toContain('react-medium-image-zoom', 'yet-another-react-lightbox', 'bootstrap');
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the new texts in the three languages, with the French spaces and the group\'s consent', function () {
    $nb = "\u{00A0}";
    $json = fn (string $locale) => json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    $keys = array_values(array_filter(array_keys($json('it')), fn (string $key) => str_starts_with($key, 'provider.gallery.queue.')));
    $keys = [...$keys, 'provider.avatar.zoom', 'provider.avatar.zoom_description', 'provider.photos.consent', 'provider.gallery.photo_label', 'provider.gallery.submit'];
    expect($keys)->toHaveCount(21);

    foreach ($keys as $key) {
        preg_match_all('/:[a-z_]+/', $json('it')[$key], $it);
        foreach (['fr', 'en'] as $locale) {
            expect($json($locale))->toHaveKey($key);
            preg_match_all('/:[a-z_]+/', $json($locale)[$key], $other);
            expect($other[0])->toEqualCanonicalizing($it[0], "{$locale} {$key}");
        }
        expect(preg_match('/ [:?!;](?![a-z_])/', $json('fr')[$key]))->toBe(0, "fr {$key}");
    }

    expect($json('fr')['provider.gallery.queue.state_error'])->toBe("Erreur{$nb}: :reason")
        ->and($json('it')['provider.photos.consent'])->toBe('Le persone riconoscibili in queste foto hanno accettato che vengano pubblicate.')
        ->and($json('it')['provider.gallery.photo_label'])->toBe('Scegli una o più foto');
});
