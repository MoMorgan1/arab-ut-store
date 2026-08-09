export function moneyLocale(locale: 'ar' | 'en'): string {
    return locale === 'ar' ? 'ar-SA' : 'en-SA';
}

export function formatHalalah(
    amountHalalah: number,
    currency: string,
    locale: 'ar' | 'en',
): string {
    return new Intl.NumberFormat(moneyLocale(locale), {
        currency,
        maximumFractionDigits: 2,
        minimumFractionDigits: 2,
        style: 'currency',
    }).format(amountHalalah / 100);
}

export function formatCoins(quantity: number, locale: 'ar' | 'en'): string {
    return new Intl.NumberFormat(moneyLocale(locale), {
        maximumFractionDigits: 0,
    }).format(quantity);
}

export function formatCompactCoins(quantity: number): string {
    if (!Number.isSafeInteger(quantity) || quantity <= 0) {
        throw new RangeError('Coins quantity must be a positive safe integer.');
    }

    if (quantity % 1_000_000 === 0) {
        return `${quantity / 1_000_000}M`;
    }

    return `${quantity / 1_000}K`;
}
