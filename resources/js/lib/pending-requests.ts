import type { TranslateFn } from '@/hooks/use-translation';

// "1 richiesta in attesa", ":count richieste in attesa": the requests that wait for an answer
// (Booking::awaitingAnswer()), for the sidebar's number.
export function pendingRequestsText(t: TranslateFn, count: number): string {
    return count === 1
        ? t('provider.nav.pending_one')
        : t('provider.nav.pending', { count });
}
