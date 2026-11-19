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

// Minutes as people say them: 45 is "45 min", 180 "3 h", 210 "3 h 30", 5 "5 min", 720 "12 h"; the
// minutes after the hours always with two digits ("1 h 05"). The same in it, fr and en, with
// non-breaking spaces inside, so "3 h 30" never splits across two lines.
export function formatMinutes(t: TranslateFn, minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return t('duration.minutes', { minutes: rest });
    }

    return rest === 0
        ? t('duration.hours', { hours })
        : t('duration.hours_minutes', {
              hours,
              minutes: String(rest).padStart(2, '0'),
          });
}

// "di solito da 2 h a 3 h", or one duration only, "45 min", when the shortest and the longest are
// the same (an empty shortest duration is saved equal to the longest one).
export function formatDuration(
    t: TranslateFn,
    min: number,
    max: number,
): string {
    return min === max
        ? formatMinutes(t, max)
        : t('providers.show.duration_range', {
              from: formatMinutes(t, min),
              to: formatMinutes(t, max),
          });
}

// A time of a day written from its minutes after midnight: 540 is "09:00", 1260 "21:00". Only for
// the example of the service form, a time on its own, with no day and no time zone: the times of
// the bookings come from the server.
export function clockTime(minutes: number): string {
    const hours = Math.floor(minutes / 60) % 24;

    return `${String(hours).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
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

// 9550 becomes "95,50 €" for the "it" locale: the prices saved on a booking come in whole cents.
export function formatCents(locale: string, cents: number): string {
    return formatPrice(locale, centsToPrice(cents));
}

// The extras of an option that actually add something, for example ["+ 10,00 €", "+ 15 min"] or
// ["+ 1 h 30"].
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
                duration: formatMinutes(t, option.extra_duration),
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
