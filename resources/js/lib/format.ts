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

// "90.00" becomes 9000. Prices are added in whole cents: adding decimals as floating
// point numbers can give results such as 0.1 + 0.2 = 0.30000000000000004.
export function priceToCents(price: string): number {
    const [units, decimals = ''] = price.split('.');

    return Number(units) * 100 + Number(decimals.padEnd(2, '0').slice(0, 2));
}

// 9550 becomes "95.50", the same format the server sends.
export function centsToPrice(cents: number): string {
    return `${Math.trunc(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;
}

// The extras of an option that actually add something, for example ["+ 10,00 €", "+ 15 min"].
export function formatOptionExtras(
    t: TranslateFn,
    locale: string,
    option: { extra_price: string; extra_duration: number },
): string[] {
    const extras: string[] = [];

    if (priceToCents(option.extra_price) !== 0) {
        extras.push(
            t('providers.show.option_extra_price', {
                price: formatPrice(locale, option.extra_price),
            }),
        );
    }

    if (option.extra_duration !== 0) {
        extras.push(
            t('providers.show.option_extra_duration', {
                minutes: option.extra_duration,
            }),
        );
    }

    return extras;
}
