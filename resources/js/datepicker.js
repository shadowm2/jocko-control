import { addMonths, daysInMonth, firstOfMonth, parseIso, toIso, toJalali, weekdayColumn } from './jalali';

const locale = 'fa-IR-u-ca-persian';
const digits = new Intl.NumberFormat('fa-IR', { useGrouping: false });
const monthName = new Intl.DateTimeFormat(locale, { timeZone: 'UTC', month: 'long' });
const fullDate = new Intl.DateTimeFormat(locale, { timeZone: 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' });

/**
 * A text field with a Jalali calendar popover. `value` is the Gregorian ISO
 * date, so the server never sees anything but what a native date input sends.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('jalaliDate', () => ({
        value: '',
        open: false,
        year: 0,
        month: 0,
        weekdays: ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'],

        init() {
            this.$watch('value', () => this.show());
            this.show();
        },

        show() {
            const date = parseIso(this.value) ?? new Date();
            const jalali = toJalali(date);

            this.year = jalali.year;
            this.month = jalali.month;
        },

        get display() {
            const date = parseIso(this.value);

            return date ? fullDate.format(date).replaceAll('‎', '') : '';
        },

        get title() {
            return `${monthName.format(firstOfMonth(this.year, this.month))} ${digits.format(this.year)}`;
        },

        get cells() {
            const lead = weekdayColumn(firstOfMonth(this.year, this.month));
            const cells = Array(lead).fill(null);

            for (let day = 1; day <= daysInMonth(this.year, this.month); day++) {
                const date = new Date(firstOfMonth(this.year, this.month).getTime() + (day - 1) * 86400000);

                cells.push({ day, label: digits.format(day), iso: toIso(date) });
            }

            return cells;
        },

        get today() {
            return toIso(new Date());
        },

        step(delta) {
            ({ year: this.year, month: this.month } = addMonths(this.year, this.month, delta));
        },

        pick(iso) {
            this.value = iso;
            this.open = false;
        },

        clear() {
            this.value = '';
            this.open = false;
        },
    }));
});
