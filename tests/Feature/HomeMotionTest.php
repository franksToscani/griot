<?php

use Illuminate\Support\Facades\Process;

// The motion of the home page (#144 step 10, docs/accessibility.md "Movimento"): the reveal on
// scroll, the entrance of the band, the cross-fade of the carousel, the cards under the mouse or
// the focus. Read in the sources and in app.css; the rules of lib/motion.ts run with Node.

function hmSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * Every file of the home page that may move, and the hook.
 */
function hmSources(): string
{
    $files = [
        'js/pages/home.tsx', 'js/components/home/home-hero.tsx', 'js/components/home/home-search.tsx',
        'js/components/home/home-carousel.tsx', 'js/components/home/category-card.tsx',
        'js/components/home/featured-provider-card.tsx', 'js/components/home/scroll-row.tsx',
        'js/lib/home-hero.ts', 'js/lib/motion.ts', 'js/hooks/use-reveal.ts',
    ];

    return implode("\n", array_map(fn (string $file) => hmSource($file), $files));
}

/**
 * The block of app.css for who has not asked for less motion.
 */
function hmMotionCss(): string
{
    expect(preg_match('/@media \(prefers-reduced-motion: no-preference\) \{([\s\S]*?)\n\}/', hmSource('css/app.css'), $match))->toBe(1);

    return $match[1];
}

/**
 * Run lib/motion.ts with Node and give back what the calls printed.
 *
 * @return array<mixed>
 */
function hmRun(string $calls): array
{
    $script = sys_get_temp_dir().'/griot-motion-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path('js/lib/motion.ts');
    file_put_contents($script, "import * as h from '{$helper}';\nconsole.log(JSON.stringify({$calls}));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------------------------
// What starts a motion
// ---------------------------------------------------------------------------------------------

test('no timer anywhere in the home page or the hook: only the scroll, the mouse, the focus or a click start a motion', function () {
    expect(hmSources())
        ->not->toMatch('/\b(setInterval|setTimeout|requestAnimationFrame|autoPlay|autoplay)\b/')
        ->not->toMatch('/scroll-smooth|scroll-behavior|behavior:\s*[\'"]smooth/');
    // Nothing loops: no infinite animation in the motion of the home page.
    expect(hmMotionCss())->not->toMatch('/infinite|alternate/');
});

test('the reveal uses IntersectionObserver once per element, and lets it go when the page goes', function () {
    expect(hmSource('js/hooks/use-reveal.ts'))
        ->toContain('observer = new IntersectionObserver((entries) => {')
        ->toMatch("/if \\(entry\\.isIntersecting\\) \\{\\s*element\\.dataset\\.reveal = 'shown';\\s*observer\\.unobserve\\(entry\\.target\\);\\s*\\}/")
        ->toContain('observer.observe(element);')
        ->toMatch('/return \(\) => \{\s*observer\.disconnect\(\);\s*\};/')
        ->toMatch('/useEffect\(\(\) => \{[\s\S]*\}, \[\]\);/');
});

test('hidden only after the mount, only out of the screen, only when something will show it again', function () {
    $hook = hmSource('js/hooks/use-reveal.ts');

    // In the effect, after the check and after the observer exists; never in the markup.
    expect($hook)
        ->toMatch("/if \\(!hidden\\) \\{\\s*return;\\s*\\}\\s*let observer: IntersectionObserver;\\s*try \\{[\\s\\S]*observer\\.observe\\(element\\);\\s*\\} catch \\{[\\s\\S]*?return;\\s*\\}[\\s\\S]*element\\.dataset\\.reveal = 'hidden';/")
        ->toContain("hasObserver: typeof window.IntersectionObserver === 'function',")
        ->toContain("window.matchMedia('(prefers-reduced-motion: reduce)').matches")
        ->and(substr_count($hook, "dataset.reveal = 'hidden'"))->toBe(1);
    // Never in the markup (the comments may name it).
    $code = (string) preg_replace(['#\{/\*.*?\*/\}#s', '#/\*.*?\*/#s', '#^\s*//.*$#m'], '', hmSources());
    expect($code)->not->toMatch('/data-reveal=|dataReveal|reveal-hidden/');

    $base = 'hasObserver: true, reduceMotion: false, viewportHeight: 800';
    expect(hmRun("[
        h.startsHidden({ {$base}, top: 900, bottom: 1200 }),
        h.startsHidden({ {$base}, top: -600, bottom: -10 }),
        h.startsHidden({ {$base}, top: 700, bottom: 1000 }),
        h.startsHidden({ {$base}, top: -100, bottom: 50 }),
        h.startsHidden({ {$base}, top: 800, bottom: 1000 }),
        h.startsHidden({ hasObserver: false, reduceMotion: false, viewportHeight: 800, top: 900, bottom: 1200 }),
        h.startsHidden({ hasObserver: true, reduceMotion: true, viewportHeight: 800, top: 900, bottom: 1200 }),
    ]"))->toBe([true, true, false, false, true, false, false]);
});

test('the stagger: 60 ms a step, at most 5 steps, never negative', function () {
    expect(hmRun("[0, 1, 2, 5, 6, 40, -3, 2.7].map((i) => h.revealDelay(i)['--reveal-delay'])"))
        ->toBe(['0ms', '60ms', '120ms', '300ms', '300ms', '300ms', '0ms', '120ms']);
    expect(hmSource('js/lib/motion.ts'))
        ->toContain('export const REVEAL_STEP_MS = 60;')
        ->toContain('export const REVEAL_MAX_STEPS = 5;');
    expect(hmMotionCss())->toContain('transition-delay: var(--reveal-delay, 0ms);');
});

// ---------------------------------------------------------------------------------------------
// How it moves
// ---------------------------------------------------------------------------------------------

test('every animation and transition exists only without "reduce motion"', function () {
    $css = hmSource('css/app.css');
    // Outside the two blocks on motion, app.css declares no animation, transition or keyframes.
    $outside = (string) preg_replace(['/@media \(prefers-reduced-motion: (no-preference|reduce)\) \{[\s\S]*?\n\}/', '#/\*.*?\*/#s'], '', $css);
    expect($outside)->not->toMatch('/\banimation|\btransition|@keyframes/');

    // In the sources, every transition, duration, animation, move, scale on press or under the
    // mouse is a motion-safe: class.
    // The comments name what moves: only the code counts.
    $code = (string) preg_replace(['#\{/\*.*?\*/\}#s', '#/\*.*?\*/#s', '#^\s*//.*$#m'], '', hmSources());
    expect(withoutMotionSafe($code))
        ->not->toMatch('/\b(transition|duration-|ease-|animate-|delay-)/')
        ->not->toMatch('/(?<![\w-])(?:[\w-]+:)*-?(translate|scale)-(?!x-200\b)/');
    // The rule for "reduce motion" is still there, with its delays zeroed.
    expect($css)->toMatch('/@media \(prefers-reduced-motion: reduce\) \{\s*\*,\s*::before,\s*::after \{\s*animation-duration: 0\.01ms !important;\s*animation-delay: 0s !important;/');
});

test('only opacity and transform move, from 150 to 400 ms; never all, a size, a margin or a place', function () {
    $sources = hmSources();
    $css = hmMotionCss();

    expect($sources.$css)
        ->not->toContain('transition-all')
        ->not->toMatch('/transition-\[[^\]]*\b(all|width|height|margin|padding|top|left|right|bottom|inset)\b/')
        ->not->toMatch('/transition(-property)?:[^;]*\b(all|width|height|margin|padding|top|left)\b/');
    // The keyframes and the transitions of app.css name opacity and transform only.
    preg_match_all('/@keyframes [\w-]+ \{([\s\S]*?)\n    \}/', $css, $frames);
    expect($frames[1])->toHaveCount(2);
    foreach ($frames[1] as $frame) {
        preg_match_all('/([a-z-]+):/', $frame, $properties);
        expect(array_values(array_diff(array_unique($properties[1]), ['opacity', 'transform'])))->toBe([]);
    }
    preg_match_all('/(\d+)ms/', $css.$sources, $durations);
    foreach (array_map('intval', $durations[1]) as $ms) {
        expect($ms === 0 || $ms === 60 || $ms === 120 || ($ms >= 150 && $ms <= 400))->toBeTrue("{$ms}ms");
    }
    preg_match_all('/motion-safe:duration-(\d+)/', $sources, $classes);
    foreach (array_map('intval', $classes[1]) as $ms) {
        expect($ms)->toBeGreaterThanOrEqual(150)->toBeLessThanOrEqual(400);
    }
});

test('the cards rise and their photo grows under the mouse and the focus alike, with the classes they share', function () {
    expect(hmSource('js/lib/motion.ts'))
        ->toContain("card: 'group hover:shadow-md focus-visible:shadow-md motion-safe:transition-[translate,box-shadow] motion-safe:duration-200 motion-safe:ease-out motion-safe:hover:-translate-y-0.5 motion-safe:focus-visible:-translate-y-0.5',")
        ->toContain("frame: 'overflow-hidden',")
        ->toContain("photo: 'motion-safe:transition-transform motion-safe:duration-300 motion-safe:ease-out motion-safe:group-hover:scale-105 motion-safe:group-focus-visible:scale-105',");

    foreach (['category-card', 'featured-provider-card'] as $card) {
        $source = hmSource("js/components/home/{$card}.tsx");
        expect($source)
            ->toContain("import { CARD_MOTION } from '@/lib/motion';")
            ->toContain('CARD_MOTION.card,')
            ->toContain('CARD_MOTION.frame')
            ->toContain('CARD_MOTION.photo,')
            // Never written again in a card.
            ->not->toMatch('/translate-y|scale-10\d|shadow-md/');
    }
    // hover: is in @media (hover: hover) in Tailwind 4: a touch never leaves a card raised.
    $package = json_decode(hmSource('../package.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($package['devDependencies']['tailwindcss'] ?? $package['dependencies']['tailwindcss'] ?? '')->toStartWith('^4');
    expect(hmMotionCss())->not->toContain(':hover');
});

test('the focus is never cut: the row keeps 8px around a card that rises 2px with a 3px ring', function () {
    expect(hmSource('js/components/home/scroll-row.tsx'))->toContain('className="flex snap-x gap-3 p-2 pb-4"');
    // The ring is on the card itself: it moves with it, nothing covers it.
    foreach (['category-card', 'featured-provider-card'] as $card) {
        expect(hmSource("js/components/home/{$card}.tsx"))
            ->toMatch('/outline-none[^\']*focus-visible:ring-\[3px\][^\']*focus-visible:outline-hidden/');
    }
    // Every outline-none of the home page comes with focus-visible:outline-hidden.
    $sources = hmSources();
    expect(substr_count($sources, 'outline-none'))->toBe(substr_count($sources, 'focus-visible:outline-hidden'));
});

// ---------------------------------------------------------------------------------------------
// The band
// ---------------------------------------------------------------------------------------------

test('the band and its first photo are never hidden: the h1 only rises, the photo does not move', function () {
    $css = hmMotionCss();

    expect($css)
        ->toMatch('/@keyframes home-rise \{\s*from \{\s*transform: translateY\(0\.5rem\);\s*\}\s*\}/')
        ->toMatch('/\[data-home-enter\] > h1 \{\s*animation: home-rise 350ms ease-out both;\s*\}/')
        ->toMatch('/\[data-home-enter\] > p \{\s*animation: home-enter 350ms ease-out 60ms both;\s*\}/')
        ->toMatch('/\[data-home-enter\] > form \{\s*animation: home-enter 350ms ease-out 120ms both;\s*\}/')
        ->and(substr_count($css, '[data-home-enter]'))->toBe(3);
    // Nothing of the band appears on scroll; the carousel and its photo have no entrance.
    $band = hmSource('js/components/home/home-hero.tsx').hmSource('js/components/home/home-search.tsx').hmSource('js/components/home/home-carousel.tsx');
    expect($band)->not->toMatch('/useReveal|reveal-item|data-home-enter=\{[^}]*\}[^>]*>\s*<HomeCarousel/');
    expect(hmSource('js/components/home/home-carousel.tsx'))->not->toMatch('/<img[^>]*(animate|opacity|scale)/');
    // Only at the first visit: a page loaded by the browser, not a visit inside the site.
    expect(hmSource('js/components/home/home-hero.tsx'))
        ->toContain('let shownOnce = false;')
        ->toContain('const [enter] = useState(() => !shownOnce);')
        ->toMatch('/useEffect\(\(\) => \{\s*shownOnce = true;\s*\}, \[\]\);/')
        ->toContain("data-home-enter={enter ? '' : undefined}");
});

test('the buttons of the band shrink when pressed; the select eases its focus', function () {
    expect(hmSource('js/lib/home-hero.ts'))
        ->toContain("const PRESS =\n    'motion-safe:transition-[color,box-shadow,scale] motion-safe:duration-150 motion-safe:active:scale-[0.98]';")
        ->toContain('${PRESS}`,')
        ->toContain('press: PRESS,');
    expect(hmSource('js/components/home/home-carousel.tsx'))->toContain('HERO.press,');
    expect(hmSource('js/components/home/home-search.tsx'))->toContain('motion-safe:transition-[color,box-shadow] motion-safe:duration-200');
    // The kit's Button and Input are left as they are.
    expect(hmSource('js/components/ui/button.tsx').hmSource('js/components/ui/input.tsx'))->not->toContain('motion-safe');
});

// ---------------------------------------------------------------------------------------------
// The carousel and the rows
// ---------------------------------------------------------------------------------------------

test('the slides cross-fade in 300 ms; a slide that left is hidden from the assistive technologies', function () {
    $carousel = hmSource('js/components/home/home-carousel.tsx');

    expect($carousel)
        ->toContain("const HIDDEN = 'invisible opacity-0';")
        ->toContain("'motion-safe:transition-[opacity,visibility] motion-safe:duration-300 motion-safe:ease-out';")
        ->and(substr_count($carousel, 'position !== current && HIDDEN'))->toBe(2)
        // The current dot grows with a transform, never with its size.
        ->and($carousel)->toContain("'scale-x-200',")
        ->not->toContain("'size-3.5'")
        // The change is still announced.
        ->toMatch('/<p role="status" className="sr-only">\s*\{announcement\}\s*<\/p>/');
});

test('the rows and the steps appear on scroll, one item after the other', function () {
    expect(hmSource('js/components/home/scroll-row.tsx'))
        ->toContain('const reveal = useReveal<HTMLUListElement>();')
        ->toContain('<ul ref={reveal}')
        ->toMatch('/className="reveal-item shrink-0 snap-start"\s+style=\{revealDelay\(index\)\}/');
    expect(hmSource('js/pages/home.tsx'))
        ->toContain('const steps = useReveal<HTMLOListElement>();')
        ->toContain('<ol ref={steps}')
        ->toMatch('/className="reveal-item [^"]*"\s+style=\{revealDelay\(index\)\}/');
    expect(hmMotionCss())
        ->toMatch("/\\[data-reveal='hidden'\\] \\.reveal-item \\{\\s*opacity: 0;\\s*transform: translateY\\(0\\.75rem\\);\\s*\\}/")
        ->toMatch("/\\[data-reveal='shown'\\] \\.reveal-item \\{\\s*transition:\\s*opacity 400ms ease-out,\\s*transform 400ms ease-out;/");
});

test('no real contact in the motion of the home page', function () {
    expect(hmSources().hmMotionCss())
        ->not->toMatch('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/')
        ->not->toMatch('/(\+|00)\d[\d ]{7,}/')
        ->not->toContain('mailto:')
        ->not->toContain('wa.me/');
});
