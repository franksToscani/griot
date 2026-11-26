import type { Page, SharedPageProps } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';

// The language of the page, outside the page components. <html lang> is written by the server on
// a full load only: after an Inertia visit (the language selector, for one) it would keep the old
// language, and screen readers would read French with an Italian voice. The Toaster lives outside
// the Inertia app, where usePage() has no page: it reads its label from here.

let notificationsLabel: string | undefined;
// The texts of the page shown, for the code outside the page components (lib/http-errors.ts).
let pageTranslations: Record<string, string> = {};
const listeners = new Set<() => void>();

// The language, the label and the texts of the page just received.
function follow(page: Page<SharedPageProps>): void {
    const { locale, translations } = page.props;

    document.documentElement.lang = locale.replace('_', '-');
    notificationsLabel = translations['common.notifications'];
    pageTranslations = translations;
    listeners.forEach((listener) => listener());
}

// Called once in app.tsx, before the app starts, so that the first "navigate" (the initial page)
// is not missed. Every page carries "locale" and "translations" (HandleInertiaRequests).
// "navigate" alone is not enough (audit #175): the language selector posts and comes back to the
// same page, and Inertia then sends "success" without "navigate". Both, with the same handler.
export function syncDocumentLanguage(): void {
    router.on('navigate', (event) => follow(event.detail.page));
    router.on('success', (event) => follow(event.detail.page));
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

// The translated name of the notifications region; undefined until the first page is shown.
export function useNotificationsLabel(): string | undefined {
    return useSyncExternalStore(subscribe, () => notificationsLabel);
}

// A text of lang/{locale}.json in the language of the page shown, outside React. As t(), a missing
// key is shown as the key itself.
export function pageText(key: string): string {
    return pageTranslations[key] ?? key;
}
