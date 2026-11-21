import { useSyncExternalStore } from 'react';

// The width from which the provider's services and hours show their "add" form in a column that
// stays in view (components/add-panel.tsx): the same 80rem (1280 px) as Tailwind's "xl:", so the
// CSS and this hook always agree. Read at once, before the first paint: no flash of the other
// layout. No server rendering in this app.
export const WIDE_LAYOUT_QUERY = '(width >= 80rem)';

const mql =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia(WIDE_LAYOUT_QUERY);

function subscribe(callback: () => void): () => void {
    mql?.addEventListener('change', callback);

    return () => mql?.removeEventListener('change', callback);
}

export function useWideLayout(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => mql?.matches ?? false,
        () => false,
    );
}
