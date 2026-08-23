export function formatOrderNumber(orderNumber: string): string {
    if (orderNumber.startsWith('UT-') && orderNumber.length > 8) {
        return `#${orderNumber.slice(-6)}`;
    }

    return orderNumber;
}

export function formatOrderDate(placedAt: string, locale: 'ar' | 'en'): string {
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SA' : 'en-GB', {
        dateStyle: 'medium',
    }).format(new Date(placedAt));
}
