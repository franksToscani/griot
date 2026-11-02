import type { TranslateFn } from '@/hooks/use-translation';

// The language of the site as Intl reads it. The one place where a language becomes a regional
// variant: "en" is British English, as Carbon writes it on the server ("Thursday 29 October 2026",
// "€90.00"). The other languages stay as they are.
export function intlLocale(locale: string): string {
    return locale === 'en' ? 'en-GB' : locale;
}

// A local day "Y-m-d" written in full, the same text as LocalTime::longDate() on the server:
// "giovedì 29 ottobre 2026", "jeudi 29 octobre 2026", "Thursday 29 October 2026". The parts are
// joined with spaces, since Intl adds a comma after the English weekday that Carbon does not write.
// The day is read at midnight UTC and written in UTC, so the browser's time zone cannot move it.
export function formatLongDate(locale: string, day: string): string {
    return new Intl.DateTimeFormat(intlLocale(locale), {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    })
        .formatToParts(new Date(`${day}T00:00:00Z`))
        .filter((part) => part.type !== 'literal')
        .map((part) => part.value)
        .join(' ');
}

// "90.00" becomes "90,00 €" for the "it" locale, "€90.00" for "en".
export function formatPrice(locale: string, price: string): string {
    return new Intl.NumberFormat(intlLocale(locale), {
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

// "25.50" from the server becomes "25,50" in an input, as an Italian provider would write it.
// The server reads the comma back (ServiceRules::preparePrice).
export function priceForInput(price: string): string {
    return price.replace('.', ',');
}
