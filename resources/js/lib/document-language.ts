import { router } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';

// The language of the page, outside the page components. <html lang> is written by the server on
// a full load only: after an Inertia visit (the language selector, for one) it would keep the old
// language, and screen readers would read French with an Italian voice. The Toaster lives outside
// the Inertia app, where usePage() has no page: it reads its label from here.

let notificationsLabel: string | undefined;
const listeners = new Set<() => void>();

// Called once in app.tsx, before the app starts, so that the first "navigate" (the initial page)
// is not missed. Every page carries "locale" and "translations" (HandleInertiaRequests).
export function syncDocumentLanguage(): void {
    router.on('navigate', (event) => {
        const { locale, translations } = event.detail.page.props;

        document.documentElement.lang = locale.replace('_', '-');
        notificationsLabel = translations['common.notifications'];
        listeners.forEach((listener) => listener());
    });
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

// The translated name of the notifications region; undefined until the first page is shown.
export function useNotificationsLabel(): string | undefined {
    return useSyncExternalStore(subscribe, () => notificationsLabel);
}
