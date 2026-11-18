import { useEffect, useState } from 'react';
import type { RefObject } from 'react';
import { autoScrollRuns, autoScrollStep, maxScroll } from '@/lib/row-scroll';

// The slow automatic scroll of the row of the categories (docs/accessibility.md, "Movimento": the
// one exception to "no timer, nothing that moves by itself", for this row only).
// requestAnimationFrame moves the row by about 25 px a second (lib/row-scroll.ts), only when
// everything says it may:
// - "enabled" (the categories only) and no "reduce motion" (matchMedia, with a listener: it
//   stops as soon as the setting changes);
// - the row is wider than its box (overflow, measured by useScrollRow);
// - no pause asked by the person (paused, kept by ScrollRow: the button, the focus in the row, a
//   touch, a drag, an arrow), and the end of the row not reached: there it stops for good
//   (onEnd), and never starts again by itself;
// - the row on the screen (IntersectionObserver) and the tab in front (document.hidden);
// - the mouse not over the row: that pause ends when the mouse leaves.
// Until the effects have run, "reduce motion" is assumed: nothing moves before it is known.
export function useAutoScroll(
    ref: RefObject<HTMLElement | null>,
    {
        enabled,
        overflow,
        paused,
        onEnd,
    }: {
        enabled: boolean;
        overflow: boolean;
        paused: boolean;
        onEnd: () => void;
    },
) {
    const [reduceMotion, setReduceMotion] = useState(true);
    const [visible, setVisible] = useState(false);
    const [pageHidden, setPageHidden] = useState(false);
    const [hovered, setHovered] = useState(false);

    // "Reduce motion", now and when it changes.
    useEffect(() => {
        if (!enabled) {
            return;
        }

        if (typeof window.matchMedia !== 'function') {
            setReduceMotion(true);

            return;
        }

        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        const update = () => setReduceMotion(query.matches);
        update();
        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, [enabled]);

    // The row on the screen; without IntersectionObserver it counts as visible.
    useEffect(() => {
        const element = ref.current;

        if (!enabled || element === null) {
            return;
        }

        if (typeof window.IntersectionObserver !== 'function') {
            setVisible(true);

            return;
        }

        const observer = new IntersectionObserver(([entry]) =>
            setVisible(entry.isIntersecting),
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, [enabled, ref]);

    // The tab in the background: nothing moves.
    useEffect(() => {
        if (!enabled) {
            return;
        }

        const update = () => setPageHidden(document.hidden);
        update();
        document.addEventListener('visibilitychange', update);

        return () => document.removeEventListener('visibilitychange', update);
    }, [enabled]);

    const available = enabled && !reduceMotion && overflow;
    const runs = autoScrollRuns({
        enabled,
        reduceMotion,
        overflow,
        paused,
        visible,
        pageHidden,
        hovered,
    });

    // The frames. The position keeps its fractions; when someone else moved the row (a scroll of
    // the browser) it starts again from where the row is.
    useEffect(() => {
        const element = ref.current;

        if (!runs || element === null) {
            return;
        }

        let frame = 0;
        let last: number | null = null;
        let position = element.scrollLeft;

        const tick = (now: number) => {
            if (last !== null) {
                if (Math.abs(element.scrollLeft - position) > 1) {
                    position = element.scrollLeft;
                }

                const step = autoScrollStep(
                    position,
                    now - last,
                    maxScroll(element),
                );
                position = step.position;
                element.scrollLeft = position;

                if (step.ended) {
                    onEnd();

                    return;
                }
            }

            last = now;
            frame = window.requestAnimationFrame(tick);
        };

        frame = window.requestAnimationFrame(tick);

        return () => window.cancelAnimationFrame(frame);
    }, [runs, ref, onEnd]);

    return {
        // The automatic scroll exists here: the pause button is shown (otherwise invisible).
        available,
        // Whether the row moves by itself now, or could after the mouse leaves: no snapping then.
        active: available && !paused,
        // The pause while the mouse is over the row (a mouse only: a touch pauses for good).
        hover: {
            onPointerEnter: (event: { pointerType: string }) => {
                if (event.pointerType === 'mouse') {
                    setHovered(true);
                }
            },
            onPointerLeave: (event: { pointerType: string }) => {
                if (event.pointerType === 'mouse') {
                    setHovered(false);
                }
            },
        },
    };
}
