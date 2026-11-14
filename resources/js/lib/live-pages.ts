import { router } from '@inertiajs/react';

// The pages that show the state of a booking, and the props that hold it (see the controllers:
// ProviderBookingIndexController, ProviderBookingShowController, BookingConfirmationController).
// Only these props are asked again: the translations and the other shared props stay as they are.
export const LIVE_PAGES: Record<string, string[]> = {
    'provider/bookings/index': ['bookings'],
    'provider/bookings/show': ['booking'],
    'bookings/show': ['booking', 'can_cancel'],
};

// The component of the page shown, kept from the last "navigate": "pageshow" has no page of its own.
let currentComponent: string | undefined;

function reloadIfLive(): void {
    const only =
        currentComponent === undefined
            ? undefined
            : LIVE_PAGES[currentComponent];

    if (only !== undefined) {
        router.reload({ only });
    }
}

// Back and Forward show a page again from the browser's history: Inertia takes its props from the
// history entry, saved when the page was left, without asking the server (also when the browser
// loads the document again after another site). A booking confirmed or cancelled in the meantime
// would still look as it was. Here the pages above ask the server again for the booking's props.
// - "navigate" with type "history": Back or Forward inside the site, or a page loaded again from
//   the history after another site.
// - "pageshow" with "persisted": the whole page restored from the browser's back/forward cache,
//   which runs no script and fires no "navigate".
// Called once in app.tsx, before the app starts: global and not in the pages, because on Back the
// page is mounted again and could miss the event fired while it mounts.
export function refreshLivePagesOnHistory(): void {
    router.on('navigate', (event) => {
        currentComponent = event.detail.page.component;

        if (event.detail.type === 'history') {
            reloadIfLive();
        }
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            reloadIfLive();
        }
    });
}
