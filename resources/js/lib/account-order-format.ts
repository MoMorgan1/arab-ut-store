import { DATE_LOCALE } from '@/lib/date-locale';

export function formatOrderNumber(orderNumber: string): string {
    if (orderNumber.startsWith('UT-') && orderNumber.length > 8) {
        return `#${orderNumber.slice(-6)}`;
    }

    return orderNumber;
}

export function formatOrderDate(placedAt: string): string {
    return new Intl.DateTimeFormat(DATE_LOCALE, {
        dateStyle: 'medium',
    }).format(new Date(placedAt));
}

const RELATIVE_STEPS: Array<[Intl.RelativeTimeFormatUnit, number]> = [
    ['year', 365 * 24 * 60 * 60],
    ['month', 30 * 24 * 60 * 60],
    ['week', 7 * 24 * 60 * 60],
    ['day', 24 * 60 * 60],
    ['hour', 60 * 60],
    ['minute', 60],
];

/**
 * "منذ ساعتين", "قبل شهرين", "yesterday": how long ago an order was placed,
 * in the customer's language. The list reads at a glance this way; the exact
 * timestamp stays on the order page (owner decision, 2026-09-10).
 */
export function formatOrderAge(
    placedAt: string,
    locale: 'ar' | 'en',
    now: Date = new Date(),
): string {
    // Clamped at "now": a client clock a little behind the server must never
    // announce that an order will be placed in two minutes.
    const seconds = Math.min(
        0,
        Math.round((new Date(placedAt).getTime() - now.getTime()) / 1000),
    );
    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    for (const [unit, size] of RELATIVE_STEPS) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(0, 'second');
}
