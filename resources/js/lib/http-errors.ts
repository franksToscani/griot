import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { pageText } from '@/lib/document-language';

// How long the toast of an error stays: longer than the 4 s of the other toasts, so that there
// is time to read it. Sonner stops the count while the pointer or the focus is on the toasts.
export const ERROR_TOAST_DURATION = 10_000;

// The text of an error, by the status of the answer:
// - 419: the session has expired, and only reloading the page gives a new one;
// - 429: too many requests (the photo uploads have a limit), wait before trying again;
// - 503: the site is down for maintenance, try later;
// - every other one (500, 403, 404…): something went wrong, try again. During a visit a 403 or a
//   404 only comes from a page left open while something changed: no text of its own is worth it.
export function httpErrorKey(status: number): string {
    switch (status) {
        case 419:
            return 'errors.request.session_expired';
        case 429:
            return 'errors.429.message';
        case 503:
            return 'errors.503.message';
        default:
            return 'errors.request.failed';
    }
}

// An error during an Inertia visit (a link, a form) is not an Inertia page: by itself Inertia shows
// it in a window, an iframe whose sandbox blocks its links, so "Riprova" and "Torna alla home" do
// nothing. Here that window never opens: a toast says what happened, in the polite region of the
// Toaster, and the page and the fields of its forms stay as they were. Nothing reloads by itself.
// Called by app.tsx outside development only: there the window shows Laravel's error, to read it.
// A page opened directly (not a visit) still gets the full error page, whose links work.
export function handleHttpErrors(): void {
    router.on('httpException', (event) => {
        event.preventDefault();

        toast.error(pageText(httpErrorKey(event.detail.response.status)), {
            duration: ERROR_TOAST_DURATION,
        });
    });
}
