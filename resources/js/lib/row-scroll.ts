// The rows of the home page that scroll sideways (components/home/scroll-row.tsx, docs/home.md):
// the small rules of the arrows, of the drag with the mouse and of the slow automatic scroll of
// the categories, apart from React so that they can be tested with Node (HomeRowControlsTest).
// Nothing is imported: Node runs this file as it is.

// The speed of the automatic scroll of the categories: slow, about 25 px a second.
export const AUTO_SCROLL_SPEED = 25;

// The longest step of one frame, in milliseconds: after a tab in the background or a slow frame,
// the row never jumps.
export const AUTO_SCROLL_MAX_FRAME_MS = 100;

// How far the mouse has to move, pressed, before a press on the row is a drag and not a click.
export const DRAG_THRESHOLD = 6;

// A row is at an edge within one pixel: the browsers round the positions.
const EDGE_TOLERANCE = 1;

// An arrow moves the row by this part of what is visible: a card of the last view stays in sight.
const PAGE_RATIO = 0.8;

export type RowMeasures = {
    scrollLeft: number;
    scrollWidth: number;
    clientWidth: number;
};

// How far the row can scroll.
export function maxScroll({ scrollWidth, clientWidth }: RowMeasures): number {
    return Math.max(0, scrollWidth - clientWidth);
}

// Whether the row is wider than its box, so that it can scroll at all.
export function overflows(measures: RowMeasures): boolean {
    return maxScroll(measures) > EDGE_TOLERANCE;
}

// Whether the row is at its start, at its end.
export function rowEdges(measures: RowMeasures): {
    atStart: boolean;
    atEnd: boolean;
} {
    const max = maxScroll(measures);

    return {
        atStart: measures.scrollLeft <= EDGE_TOLERANCE,
        atEnd: measures.scrollLeft >= max - EDGE_TOLERANCE,
    };
}

// Where an arrow takes the row: by about one view (PAGE_RATIO), never past an edge. At once, never
// a smooth scroll (docs/accessibility.md).
export function arrowTarget(
    measures: RowMeasures,
    direction: 'previous' | 'next',
): number {
    const step = Math.max(1, Math.round(measures.clientWidth * PAGE_RATIO));
    const target =
        direction === 'next'
            ? measures.scrollLeft + step
            : measures.scrollLeft - step;

    return Math.min(maxScroll(measures), Math.max(0, target));
}

// Whether a press of the mouse that moved this far is a drag.
export function isDrag(distance: number): boolean {
    return Math.abs(distance) >= DRAG_THRESHOLD;
}

// Where a drag takes the row: it follows the mouse, never past an edge.
export function dragTarget(
    measures: RowMeasures,
    startScrollLeft: number,
    distance: number,
): number {
    return Math.min(
        maxScroll(measures),
        Math.max(0, startScrollLeft - distance),
    );
}

// Whether the automatic scroll may move the row now. Only for the categories (enabled), only
// without "reduce motion", only when the row is wider than its box, never after a pause asked by
// the person (or the end of the row), only while the row is on the screen and the tab in front,
// and not while the mouse is over the row (the pause that ends when the mouse leaves).
export function autoScrollRuns({
    enabled,
    reduceMotion,
    overflow,
    paused,
    visible,
    pageHidden,
    hovered,
}: {
    enabled: boolean;
    reduceMotion: boolean;
    overflow: boolean;
    paused: boolean;
    visible: boolean;
    pageHidden: boolean;
    hovered: boolean;
}): boolean {
    return (
        enabled &&
        !reduceMotion &&
        overflow &&
        !paused &&
        visible &&
        !pageHidden &&
        !hovered
    );
}

// One frame of the automatic scroll: the new position after elapsed milliseconds (at most
// AUTO_SCROLL_MAX_FRAME_MS), and whether the end is reached. The position keeps its fractions:
// the browser rounds what it shows.
export function autoScrollStep(
    position: number,
    elapsedMs: number,
    max: number,
): { position: number; ended: boolean } {
    const elapsed = Math.min(Math.max(elapsedMs, 0), AUTO_SCROLL_MAX_FRAME_MS);
    const next = Math.min(max, position + (AUTO_SCROLL_SPEED * elapsed) / 1000);

    return { position: next, ended: next >= max - EDGE_TOLERANCE };
}
