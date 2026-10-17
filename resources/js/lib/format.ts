import type { TranslateFn } from '@/hooks/use-translation';

// "90.00" becomes "90,00 €" for the "it" locale.
export function formatPrice(locale: string, price: string): string {
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: 'EUR',
    }).format(Number(price));
}

// "da 30 a 40 min", or only "30 min" when the duration is fixed.
export function formatDuration(
    t: TranslateFn,
    min: number,
    max: number,
): string {
    return min === max
        ? t('providers.show.duration_fixed', { minutes: min })
        : t('providers.show.duration_range', { from: min, to: max });
}
