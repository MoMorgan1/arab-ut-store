import type { AdminMoney } from '@/types/admin';

export function formatAdminMoney(
    money: AdminMoney,
    locale: 'ar' | 'en',
): string {
    const minorUnits = BigInt(money.amountMinor);
    const isNegative = minorUnits < 0n;
    const absoluteMinorUnits = isNegative ? -minorUnits : minorUnits;
    const wholeUnits = absoluteMinorUnits / 100n;
    const fractionalUnits = absoluteMinorUnits % 100n;
    const whole = new Intl.NumberFormat(locale, {
        maximumFractionDigits: 0,
    }).format(wholeUnits);
    const fraction = new Intl.NumberFormat(locale, {
        maximumFractionDigits: 0,
        minimumIntegerDigits: 2,
        useGrouping: false,
    }).format(fractionalUnits);
    const template = new Intl.NumberFormat(locale, {
        currency: money.currency,
        maximumFractionDigits: 2,
        minimumFractionDigits: 2,
        style: 'currency',
    }).formatToParts(isNegative ? -0 : 0);

    return template
        .map((part) => {
            if (part.type === 'integer') {
                return whole;
            }

            if (part.type === 'fraction') {
                return fraction;
            }

            return part.value;
        })
        .join('');
}
