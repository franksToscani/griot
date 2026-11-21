import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';

// After a save, the server names the element to show with the "focus" flash (for example
// "service-12", the title of a service's card). The page brings it into view, moves the focus there
// and highlights its card, so whoever saved, with the mouse or the keyboard, sees where the item is
// (docs/accessibility.md, « Evidenziazione dopo il salvataggio »).

// The attribute of the highlighted card: JUST_SAVED (components/add-panel.tsx) draws its outline.
export const JUST_SAVED_ATTRIBUTE = 'data-just-saved';

// The id from the flash data, only if it looks like one of ours.
export function flashFocusId(flash: unknown): string | null {
    const value = (flash as Record<string, unknown> | null | undefined)?.focus;

    return typeof value === 'string' && /^[a-z]+(?:-[a-z0-9]+)+$/.test(value)
        ? value
        : null;
}

// The highlight on the page, at most one: its removal.
let clearCurrent: (() => void) | null = null;

// Remove the highlight, if any (a new save, the page that changes).
export function clearJustSaved(): void {
    clearCurrent?.();
}

// The card of a target (the title of a service or of a day), or the target itself when it has no
// card (a closed day, one line). Marked until the person goes on: the focus leaves the card, or a
// pointer or a key is pressed outside it. No timer: it never vanishes before it is seen.
function highlight(target: HTMLElement): void {
    clearJustSaved();

    const card = target.closest<HTMLElement>('[data-slot="card"]') ?? target;
    // One signal for the three listeners: the first that fires removes them all.
    const listeners = new AbortController();
    const outside = (node: EventTarget | null) =>
        !(node instanceof Node) || !card.contains(node);

    const clear = () => {
        listeners.abort();
        card.removeAttribute(JUST_SAVED_ATTRIBUTE);

        if (clearCurrent === clear) {
            clearCurrent = null;
        }
    };

    card.setAttribute(JUST_SAVED_ATTRIBUTE, '');
    card.addEventListener(
        'focusout',
        (event) => {
            if (outside(event.relatedTarget)) {
                clear();
            }
        },
        { signal: listeners.signal },
    );

    for (const type of ['pointerdown', 'keydown'] as const) {
        document.addEventListener(
            type,
            (event) => {
                if (outside(event.target)) {
                    clear();
                }
            },
            { capture: true, signal: listeners.signal },
        );
    }

    clearCurrent = clear;
}

// Into view at once ("instant": never a smooth scroll, whatever the motion preference), then the
// focus without a second scroll, then the highlight of the card, only if the focus is really there
// (an open dialog keeps it inside: the dialog does all this again once closed). A plain click on a
// card never gets here. False when the element is not on the page.
export function revealAndFocus(id: string): boolean {
    const element = document.getElementById(id);

    if (!element) {
        return false;
    }

    element.scrollIntoView({ block: 'start', behavior: 'instant' });
    element.focus({ preventScroll: true });

    if (document.activeElement === element) {
        highlight(element);
    }

    return true;
}

// The pages with a "focus" flash call it once. A dialog that is closing does the same itself, after
// its own focus handling (components/add-panel.tsx).
export function useFlashFocus(): void {
    const { flash } = usePage();

    useEffect(() => {
        const id = flashFocusId(flash);

        if (id) {
            revealAndFocus(id);
        }
    }, [flash]);

    // The page changes: no highlight and no listener left behind.
    useEffect(() => clearJustSaved, []);
}
