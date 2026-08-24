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
