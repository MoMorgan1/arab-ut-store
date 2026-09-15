export function moneyLocale(locale: 'ar' | 'en'): string {
    return locale === 'ar' ? 'ar-SA' : 'en-SA';
}

/**
 * The label that follows an amount. Arabic riyal amounts carry the letters
 * «ر.س»: the Thmanyah fonts ligate that pair into the riyal sign (glyph
 * `rial.rig`), so the customer sees the symbol wherever the store's type
 * is loaded and readable letters everywhere else. Intl's own Arabic symbol
 * is «ر.س.» with a trailing full stop that would sit next to the sign, so
 * the label is spelled here rather than asked of the formatter. Other
 * currencies and the English interface keep the ISO code.
 */
export function currencyLabel(currency: string, locale: 'ar' | 'en'): string {
    return locale === 'ar' && currency === 'SAR' ? 'ر.س' : currency;
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
    })
        .formatToParts(amountHalalah / 100)
        .map((part) =>
            part.type === 'currency'
                ? currencyLabel(currency, locale)
                : part.value,
        )
        .join('');
}

export function formatMinorUnits(
    amountMinor: number,
    currency: string,
    locale: 'ar' | 'en',
): string {
    if (!Number.isSafeInteger(amountMinor) || amountMinor < 0) {
        throw new RangeError(
            'Minor units must be a non-negative safe integer.',
        );
    }

    const amount = BigInt(amountMinor);
    const major = amount / 100n;
    const fraction = (amount % 100n).toString().padStart(2, '0');
    const parts = new Intl.NumberFormat(moneyLocale(locale), {
        currency,
        currencyDisplay: 'code',
        maximumFractionDigits: 2,
        minimumFractionDigits: 2,
        numberingSystem: 'latn',
        style: 'currency',
    }).formatToParts(major);

    return parts
        .map((part) => {
            if (part.type === 'fraction') {
                return fraction;
            }

            if (part.type === 'currency') {
                return currencyLabel(currency, locale);
            }

            return part.value;
        })
        .join('');
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
