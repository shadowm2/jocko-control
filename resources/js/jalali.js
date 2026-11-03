/**
 * Jalali (Persian) calendar helpers built on Intl, so no library is needed.
 * Dates are exchanged as ISO strings (YYYY-MM-DD); everything runs at UTC noon
 * so daylight-saving changes can never move a date.
 */

const parts = new Intl.DateTimeFormat('en-u-ca-persian-nu-latn', {
    timeZone: 'UTC',
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
});

const DAY = 86400000;

export function parseIso(iso) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '');

    return match ? new Date(Date.UTC(+match[1], +match[2] - 1, +match[3], 12)) : null;
}

export function toIso(date) {
    return date.toISOString().slice(0, 10);
}

/** @returns {{year: number, month: number, day: number}} */
export function toJalali(date) {
    const found = {};

    for (const part of parts.formatToParts(date)) {
        if (part.type === 'year' || part.type === 'relatedYear') {
            found.year = +part.value;
        } else if (part.type === 'month' || part.type === 'day') {
            found[part.type] = +part.value;
        }
    }

    return found;
}

/** The Gregorian date of the first day of a Jalali month. */
export function firstOfMonth(year, month) {
    const newYear = Date.UTC(year + 621, 2, 21, 12);
    const guess = newYear + (month <= 6 ? (month - 1) * 31 : 186 + (month - 7) * 30) * DAY;

    for (let offset = -3; offset <= 3; offset++) {
        const candidate = new Date(guess + offset * DAY);
        const jalali = toJalali(candidate);

        if (jalali.year === year && jalali.month === month && jalali.day === 1) {
            return candidate;
        }
    }

    throw new Error(`Cannot place Jalali month ${year}/${month}`);
}

export function daysInMonth(year, month) {
    const next = month === 12 ? firstOfMonth(year + 1, 1) : firstOfMonth(year, month + 1);

    return Math.round((next - firstOfMonth(year, month)) / DAY);
}

/** Saturday is column 0, as in the Iranian week. */
export function weekdayColumn(date) {
    return (date.getUTCDay() + 1) % 7;
}

export function addMonths(year, month, delta) {
    const index = year * 12 + (month - 1) + delta;

    return { year: Math.floor(index / 12), month: (index % 12) + 1 };
}
