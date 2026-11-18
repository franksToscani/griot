import { useEffect, useRef, useState } from 'react';
import type {
    DragEvent,
    MouseEvent,
    PointerEvent,
    RefObject,
    WheelEvent,
} from 'react';
import {
    arrowTarget,
    dragTarget,
    isDrag,
    overflows,
    rowEdges,
} from '@/lib/row-scroll';

type Edges = { overflow: boolean; atStart: boolean; atEnd: boolean };

// A row of the home page that scrolls sideways (ScrollRow, both rows): where it is, the two arrows
// and the drag with the mouse. Every interaction calls onInteract, which pauses the automatic
// scroll of the categories for good. No timer and no smooth scroll: the row moves at once.
// - Edges: measured on scroll (a passive listener) and when the row or its cards change size
//   (ResizeObserver); the state changes only when a value does.
// - Arrows: by about one view, never past an edge (arrowTarget).
// - Drag: with the mouse only (a finger scrolls the row by itself). A press becomes a drag after
//   6 px (DRAG_THRESHOLD): only then the row takes the pointer, follows the mouse and the click at
//   the end is cancelled, so a card is not opened by a drag. A shorter press stays a click. The
//   browser's own drag of a link or a photo is cancelled. A press on the scrollbar (on the row
//   itself, not on a card) is left to the browser.
// - A touch, a pen or a sideways wheel on the row pause too.
export function useScrollRow(
    ref: RefObject<HTMLDivElement | null>,
    onInteract: () => void,
) {
    const [edges, setEdges] = useState<Edges>({
        overflow: false,
        atStart: true,
        atEnd: true,
    });
    const [dragging, setDragging] = useState(false);
    const press = useRef<{
        pointerId: number;
        startX: number;
        startScrollLeft: number;
        moved: boolean;
    } | null>(null);
    const cancelClick = useRef(false);

    useEffect(() => {
        const element = ref.current;

        if (element === null) {
            return;
        }

        const update = () => {
            const next = { overflow: overflows(element), ...rowEdges(element) };
            setEdges((current) =>
                current.overflow === next.overflow &&
                current.atStart === next.atStart &&
                current.atEnd === next.atEnd
                    ? current
                    : next,
            );
        };

        update();
        element.addEventListener('scroll', update, { passive: true });

        let observer: ResizeObserver | null = null;

        if (typeof window.ResizeObserver === 'function') {
            observer = new ResizeObserver(update);
            observer.observe(element);

            if (element.firstElementChild !== null) {
                observer.observe(element.firstElementChild);
            }
        }

        return () => {
            element.removeEventListener('scroll', update);
            observer?.disconnect();
        };
    }, [ref]);

    const scrollByArrow = (direction: 'previous' | 'next') => {
        const element = ref.current;
        onInteract();

        if (element !== null) {
            element.scrollLeft = arrowTarget(element, direction);
        }
    };

    const endPress = (event: PointerEvent<HTMLDivElement>) => {
        const current = press.current;

        if (current === null || event.pointerId !== current.pointerId) {
            return;
        }

        if (current.moved) {
            cancelClick.current = true;
            setDragging(false);

            if (event.currentTarget.hasPointerCapture(event.pointerId)) {
                event.currentTarget.releasePointerCapture(event.pointerId);
            }
        }

        press.current = null;
    };

    const handlers = {
        onPointerDown: (event: PointerEvent<HTMLDivElement>) => {
            cancelClick.current = false;

            if (event.pointerType !== 'mouse') {
                onInteract();

                return;
            }

            // The scrollbar: the row itself, not a card.
            if (event.target === event.currentTarget) {
                onInteract();

                return;
            }

            if (event.button === 0) {
                press.current = {
                    pointerId: event.pointerId,
                    startX: event.clientX,
                    startScrollLeft: event.currentTarget.scrollLeft,
                    moved: false,
                };
            }
        },
        onPointerMove: (event: PointerEvent<HTMLDivElement>) => {
            const current = press.current;

            if (current === null || event.pointerId !== current.pointerId) {
                return;
            }

            const distance = event.clientX - current.startX;

            if (!current.moved) {
                if (!isDrag(distance)) {
                    return;
                }

                current.moved = true;
                event.currentTarget.setPointerCapture(event.pointerId);
                window.getSelection()?.removeAllRanges();
                setDragging(true);
                onInteract();
            }

            event.currentTarget.scrollLeft = dragTarget(
                event.currentTarget,
                current.startScrollLeft,
                distance,
            );
        },
        onPointerUp: endPress,
        onPointerCancel: endPress,
        // The click at the end of a drag: neither the link nor its page.
        onClickCapture: (event: MouseEvent<HTMLDivElement>) => {
            if (cancelClick.current) {
                cancelClick.current = false;
                event.preventDefault();
                event.stopPropagation();
            }
        },
        onDragStart: (event: DragEvent<HTMLDivElement>) =>
            event.preventDefault(),
        onWheel: (event: WheelEvent<HTMLDivElement>) => {
            if (event.deltaX !== 0) {
                onInteract();
            }
        },
    };

    return { ...edges, dragging, scrollByArrow, handlers };
}
