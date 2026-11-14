// The provider's local time is Europe/Rome (CLAUDE.md, LocalTime on the server). The customer's
// page gets a booking's start as a local day "Y-m-d" and a local time "H:i", never in UTC.
//
// Without Intl (only lib/format.ts uses it, for the texts in the page's language): Rome is UTC+1,
// and UTC+2 in summer time, which in the European Union runs from 01:00 UTC on the last Sunday of
// March to 01:00 UTC on the last Sunday of October.

const HOUR = 3_600_000;

// 01:00 UTC on the last Sunday of a month (0-based), when the clocks change.
function lastSundayAtOne(year: number, month: number): number {
    const lastDay = new Date(Date.UTC(year, month + 1, 0));

    return Date.UTC(year, month, lastDay.getUTCDate() - lastDay.getUTCDay(), 1);
}

// The instant (ms since 1970, UTC) of a day and a time in Rome, whatever the browser's time zone.
export function romeInstant(day: string, time: string): number {
    const [year, month, date] = day.split('-').map(Number);
    const [hour, minute] = time.split(':').map(Number);
    const asUtc = Date.UTC(year, month - 1, date, hour, minute);
    const summer = asUtc - 2 * HOUR;

    return summer >= lastSundayAtOne(year, 2) &&
        summer < lastSundayAtOne(year, 9)
        ? summer
        : asUtc - HOUR;
}

// Whether a booking has started, from its local day and start time in Rome.
export function hasStarted(day: string, time: string): boolean {
    return Date.now() >= romeInstant(day, time);
}
