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
        currencyDisplay: 'code',
        maximumFractionDigits: 2,
        minimumFractionDigits: 2,
        numberingSystem: 'latn',
        style: 'currency',
    }).format(amountHalalah / 100);
}

export function formatCoins(quantity: number, locale: 'ar' | 'en'): string {
    return new Intl.NumberFormat(moneyLocale(locale), {
        maximumFractionDigits: 0,
        numberingSystem: 'latn',
    }).format(quantity);
}

export function formatInteger(value: number, locale: 'ar' | 'en'): string {
    return new Intl.NumberFormat(moneyLocale(locale), {
        maximumFractionDigits: 0,
        numberingSystem: 'latn',
    }).format(value);
}

export function formatCompactCoins(
    quantity: number,
    locale: 'ar' | 'en' = 'en',
): string {
    if (!Number.isSafeInteger(quantity) || quantity <= 0) {
        throw new RangeError('Coins quantity must be a positive safe integer.');
    }

    if (quantity % 1_000_000 === 0) {
        return `${formatInteger(quantity / 1_000_000, locale)}M`;
    }

    return `${formatInteger(quantity / 1_000, locale)}K`;
}
