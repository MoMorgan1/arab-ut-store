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

/**
 * Joins formatted parts into the figure the customer reads. Arabic amounts
 * put the label before the number — «ر.س 124.70», the owner's call on
 * 2026-09-15 — and wrap the pair in a left-to-right isolate so the bidi
 * algorithm never flips it back inside right-to-left copy, and a run of
 * per-digit spans never has to. English amounts keep Intl's own order. A
 * ledger sign leads the whole figure in both languages.
 */
export function joinMoneyParts(
    parts: Intl.NumberFormatPart[],
    currency: string,
    locale: 'ar' | 'en',
    fraction?: string,
    sign: '+' | '−' | '' = '',
): string {
    const value = (part: Intl.NumberFormatPart): string => {
        if (part.type === 'fraction' && fraction !== undefined) {
            return fraction;
        }

        if (part.type === 'currency') {
            return currencyLabel(currency, locale);
        }

        return part.value;
    };

    if (locale !== 'ar') {
        return `${sign}${parts.map(value).join('')}`;
    }

    const number = parts
        .filter(
            (part) =>
                part.type !== 'currency' &&
                part.type !== 'literal' &&
                part.value !== '\u200f',
        )
        .map(value)
        .join('');

    return `\u2066${sign}${currencyLabel(currency, locale)}\u00a0${number}\u2069`;
}

export function formatHalalah(
    amountHalalah: number,
    currency: string,
    locale: 'ar' | 'en',
): string {
    return joinMoneyParts(
        new Intl.NumberFormat(moneyLocale(locale), {
            currency,
            currencyDisplay: 'code',
            maximumFractionDigits: 2,
            minimumFractionDigits: 2,
            numberingSystem: 'latn',
            style: 'currency',
        }).formatToParts(amountHalalah / 100),
        currency,
        locale,
    );
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

    return joinMoneyParts(parts, currency, locale, fraction);
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
