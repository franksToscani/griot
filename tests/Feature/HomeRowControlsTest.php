<?php

use Illuminate\Support\Facades\Process;

// The controls of the rows of the home page (ScrollRow, docs/home.md, docs/accessibility.md): two
// arrows beside the row from 768 px, the drag with the mouse, and for the categories only the slow
// automatic scroll with its pause button. The rules of lib/row-scroll.ts run with Node; the rest
// is read from the sources (there is no JavaScript runner).

function hrcSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

/**
 * Run lib/row-scroll.ts with Node and give back what the calls printed.
 */
function hrcRun(string $calls): mixed
{
    $script = sys_get_temp_dir().'/griot-row-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path('js/lib/row-scroll.ts');
    file_put_contents($script, "import * as r from '{$helper}';
const m = (scrollLeft, scrollWidth = 1000, clientWidth = 400) => ({ scrollLeft, scrollWidth, clientWidth });
console.log(JSON.stringify({$calls}));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------------------------
// The rules (lib/row-scroll.ts)
// ---------------------------------------------------------------------------------------------

test('the edges of a row, within a pixel; a row that fits does not scroll', function () {
    expect(hrcRun('[r.rowEdges(m(0)), r.rowEdges(m(300)), r.rowEdges(m(599.5)), r.overflows(m(0)), r.overflows(m(0, 400, 400)), r.maxScroll(m(0))]'))
        ->toBe([
            ['atStart' => true, 'atEnd' => false],
            ['atStart' => false, 'atEnd' => false],
            ['atStart' => false, 'atEnd' => true],
            true,
            false,
            600,
        ]);
});

test('an arrow moves the row by most of a view, never past an edge', function () {
    expect(hrcRun("[r.arrowTarget(m(0), 'next'), r.arrowTarget(m(500), 'next'), r.arrowTarget(m(500), 'previous'), r.arrowTarget(m(100), 'previous')]"))
        ->toBe([320, 600, 180, 0]);
});

test('a press becomes a drag after 6 px, and the row follows the mouse within its edges', function () {
    expect(hrcRun('[r.DRAG_THRESHOLD, r.isDrag(5), r.isDrag(-6), r.isDrag(6), r.dragTarget(m(0), 200, -50), r.dragTarget(m(0), 200, 500), r.dragTarget(m(0), 590, -50)]'))
        ->toBe([6, false, true, true, 250, 0, 600]);
});

test('the automatic scroll: about 25 px a second, at most 100 ms a frame, and it ends at the end', function () {
    expect(hrcRun('[r.AUTO_SCROLL_SPEED, r.AUTO_SCROLL_MAX_FRAME_MS, r.autoScrollStep(0, 1000, 600), r.autoScrollStep(0, 5000, 600), r.autoScrollStep(10, -5, 600), r.autoScrollStep(598, 100, 600)]'))
        ->toBe([
            25,
            100,
            ['position' => 2.5, 'ended' => false],
            ['position' => 2.5, 'ended' => false],
            ['position' => 10, 'ended' => false],
            ['position' => 600, 'ended' => true],
        ]);
});

test('the automatic scroll runs only when every condition says so', function (string $change, bool $runs) {
    $all = '{ enabled: true, reduceMotion: false, overflow: true, paused: false, visible: true, pageHidden: false, hovered: false }';

    expect(hrcRun("r.autoScrollRuns({ ...{$all}, {$change} })"))->toBe($runs);
})->with([
    'everything says yes' => ['enabled: true', true],
    'not the categories' => ['enabled: false', false],
    '"reduce motion"' => ['reduceMotion: true', false],
    'the row fits' => ['overflow: false', false],
    'paused by the person or at the end' => ['paused: true', false],
    'out of the screen' => ['visible: false', false],
    'the tab in the background' => ['pageHidden: true', false],
    'the mouse over the row' => ['hovered: true', false],
]);

// ---------------------------------------------------------------------------------------------
// The arrows and the pause button
// ---------------------------------------------------------------------------------------------

test('two arrows beside the row, outside the part that scrolls, from 768 px only', function () {
    $row = hrcSource('components/home/scroll-row.tsx');

    expect($row)
        // Previous, the row, next: in this order, in the same flex line.
        ->toMatch('/<div className="flex items-center gap-2" \{\.\.\.auto\.hover\}>\s*<RowArrow\s+direction="previous"[\s\S]*?\/>\s*<div\s+role="region"[\s\S]*?<\/ul>\s*<\/div>\s*<RowArrow\s+direction="next"[\s\S]*?\/>\s*<\/div>/')
        // Hidden on phones, where a finger scrolls the row; their place kept when the row fits.
        ->toMatch("/'hidden shrink-0 md:inline-flex',\\s*CONTROL_SIZE,\\s*AT_EDGE,\\s*!shown && 'invisible',/")
        ->toContain("const CONTROL_SIZE = 'size-11 md:size-9 [&_svg]:size-5';")
        // At an edge: aria-disabled, never disabled (it keeps the focus), and it does nothing.
        ->toContain('aria-disabled={atEdge ? true : undefined}')
        ->toMatch('/onClick=\{\(\) => \{\s*if \(!atEdge\) \{\s*onClick\(\);\s*\}\s*\}\}/')
        ->toContain("const AT_EDGE = 'aria-disabled:cursor-not-allowed aria-disabled:opacity-50';")
        ->not->toMatch('/(?<![\w-])disabled=\{/')
        // Their names: sr-only texts with the title of the section.
        ->toContain("label={t('home.row.previous', { section: title })}")
        ->toContain("label={t('home.row.next', { section: title })}")
        ->toContain('<span className="sr-only">{label}</span>')
        ->toContain('<Icon aria-hidden="true" />');
});

test('the pause button: before the row, always on the page, its name says what it does', function () {
    $row = hrcSource('components/home/scroll-row.tsx');

    expect($row)
        // In the line of the title, before the row in the order of the Tab key.
        ->toMatch('/<SectionTitle id=\{titleId\}>\{title\}<\/SectionTitle>\s*\{autoScroll && \(\s*<Button/')
        // 44px on phones too: never hidden there.
        ->toMatch("/'shrink-0',\\s*CONTROL_SIZE,\\s*AT_EDGE,\\s*!auto\\.available && 'invisible',/")
        // The name changes, never aria-pressed.
        ->toMatch("/\\{t\\(paused \\? 'home\\.row\\.play' : 'home\\.row\\.pause', \\{\\s*section: title,?\\s*\\}\\)\\}/")
        ->toMatch('/\{paused \? \(\s*<Play aria-hidden="true" \/>\s*\) : \(\s*<Pause aria-hidden="true" \/>\s*\)\}/')
        // At the end of the row there is nothing to start again.
        ->toContain('const resumeAtEnd = paused && row.atEnd;')
        ->toContain('aria-disabled={resumeAtEnd ? true : undefined}')
        ->not->toMatch('/aria-pressed|aria-label(?!ledby)|aria-live/');
});

test('the pause for good: the focus in the row, a touch, a drag, an arrow, the end; the mouse over it only while there', function () {
    $row = hrcSource('components/home/scroll-row.tsx');
    $controls = hrcSource('hooks/use-scroll-row.ts');
    $auto = hrcSource('hooks/use-auto-scroll.ts');

    expect($row)
        ->toContain('const pause = useCallback(() => setPaused(true), []);')
        ->toContain('const row = useScrollRow(rowRef, pause);')
        ->toContain('onEnd: pause,')
        ->toContain('onFocus={pause}');
    // A touch or a pen, the scrollbar, a drag past the threshold, an arrow, a sideways wheel.
    expect($controls)
        ->toMatch("/if \\(event\\.pointerType !== 'mouse'\\) \\{\\s*onInteract\\(\\);\\s*return;\\s*\\}/")
        ->toMatch('/if \(event\.target === event\.currentTarget\) \{\s*onInteract\(\);\s*return;\s*\}/')
        ->toMatch('/current\.moved = true;[\s\S]*?onInteract\(\);/')
        ->toMatch('/const scrollByArrow = \(direction: \'previous\' \| \'next\'\) => \{\s*const element = ref\.current;\s*onInteract\(\);/')
        ->toMatch('/if \(event\.deltaX !== 0\) \{\s*onInteract\(\);\s*\}/');
    // The mouse over the row: a pause that ends when it leaves, for a mouse only.
    expect($auto)
        ->toMatch("/onPointerEnter: [^\\n]*\\n\\s*if \\(event\\.pointerType === 'mouse'\\) \\{\\s*setHovered\\(true\\);/")
        ->toMatch("/onPointerLeave: [^\\n]*\\n\\s*if \\(event\\.pointerType === 'mouse'\\) \\{\\s*setHovered\\(false\\);/");
});

test('the drag: a mouse only, after 6 px, then no click and no drag of a link or a photo', function () {
    $controls = hrcSource('hooks/use-scroll-row.ts');

    expect($controls)
        ->toMatch('/if \(!isDrag\(distance\)\) \{\s*return;\s*\}/')
        // The row takes the pointer only once it is a drag: a short press stays a click on the card.
        ->toMatch('/current\.moved = true;\s*event\.currentTarget\.setPointerCapture\(event\.pointerId\);/')
        ->and(substr_count($controls, 'setPointerCapture'))->toBe(1)
        ->and($controls)
        ->toContain('cancelClick.current = true;')
        ->toMatch('/onClickCapture: \(event: MouseEvent<HTMLDivElement>\) => \{\s*if \(cancelClick\.current\) \{\s*cancelClick\.current = false;\s*event\.preventDefault\(\);\s*event\.stopPropagation\(\);/')
        ->toContain('event.preventDefault(),')
        ->toMatch('/onDragStart: \(event: DragEvent<HTMLDivElement>\) =>\s*event\.preventDefault\(\),/')
        // No timer, never a smooth scroll: the row moves at once.
        ->not->toMatch('/\b(setInterval|setTimeout|requestAnimationFrame)\b|behavior:|scrollTo\(|scrollBy\(/');

    expect(hrcSource('components/home/scroll-row.tsx'))
        ->toContain('{...row.handlers}')
        ->toContain('pointer-fine:cursor-grab')
        ->toContain('data-dragging:cursor-grabbing')
        ->toContain('data-dragging:select-none');
});

test('both rows have the arrows and the drag; only the categories scroll by themselves', function () {
    $page = hrcSource('pages/home.tsx');

    expect(substr_count($page, '<ScrollRow'))->toBe(2)
        ->and(substr_count($page, 'autoScroll'))->toBe(1)
        ->and(hrcSource('components/home/scroll-row.tsx'))->toContain('autoScroll = false,');
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the four names exist in Italian, French and English, with the section, the French spaces right', function () {
    $json = fn (string $locale) => json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

    expect(array_intersect_key($json('it'), array_flip(['home.row.previous', 'home.row.next', 'home.row.pause', 'home.row.play'])))->toBe([
        'home.row.previous' => 'Scorri indietro: :section',
        'home.row.next' => 'Scorri avanti: :section',
        'home.row.pause' => 'Metti in pausa lo scorrimento: :section',
        'home.row.play' => 'Riprendi lo scorrimento: :section',
    ]);

    foreach (['home.row.previous', 'home.row.next', 'home.row.pause', 'home.row.play'] as $key) {
        foreach (['fr', 'en'] as $locale) {
            expect($json($locale)[$key] ?? null)->toBeString()->toEndWith(': :section');
        }
        // A no-break space before the colon in French.
        expect($json('fr')[$key])->toEndWith("\u{00A0}: :section");
    }
});
