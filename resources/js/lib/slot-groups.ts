// The free times of a day by part of the day: morning (before 12:00), afternoon (12:00-17:59),
// evening (from 18:00). Only the local time the server already computed ("HH:mm", in the
// provider's time zone) is read: no time zone is worked out in the browser. The groups keep the
// order of the day, the times keep their order inside each group; an empty group is left out.

export type SlotPeriod = 'morning' | 'afternoon' | 'evening';

export type SlotGroup<T> = {
    period: SlotPeriod;
    slots: T[];
};

const PERIODS: SlotPeriod[] = ['morning', 'afternoon', 'evening'];

export function periodOf(time: string): SlotPeriod {
    const hour = Number(time.slice(0, 2));

    if (hour < 12) {
        return 'morning';
    }

    return hour < 18 ? 'afternoon' : 'evening';
}

export function groupSlotsByPeriod<T extends { time: string }>(
    slots: T[],
): SlotGroup<T>[] {
    return PERIODS.map((period) => ({
        period,
        slots: slots.filter((slot) => periodOf(slot.time) === period),
    })).filter((group) => group.slots.length > 0);
}
