import type { AdminMoney } from '@/types/admin';

export function parseSarToHalalah(
    sar: string | number | null | undefined,
): number {
    if (sar === null || sar === undefined) {
        return 0;
    }

    const str = String(sar).trim();

    if (!str) {
        return 0;
    }

    const isNegative = str.startsWith('-');
    const clean = str.replace(/^[+-]/, '').trim();

    if (!clean) {
        return 0;
    }

    const [wholePart = '0', fracPart = ''] = clean.split('.');
    const cleanWhole = wholePart.replace(/\D/g, '');
    const cleanFrac = fracPart.replace(/\D/g, '');
    const whole = parseInt(cleanWhole || '0', 10);
    const fracStr = cleanFrac.padEnd(2, '0').slice(0, 2);
    const frac = parseInt(fracStr || '0', 10);
    const halalah = whole * 100 + frac;

    return isNegative ? -halalah : halalah;
}

export function formatHalalahToSar(
    halalah: number | string | bigint | null | undefined,
): string {
    if (halalah === null || halalah === undefined || halalah === '') {
        return '0.00';
    }

    const val =
        typeof halalah === 'bigint'
            ? halalah
            : BigInt(Math.trunc(Number(halalah)));
    const isNegative = val < 0n;
    const absVal = isNegative ? -val : val;
    const whole = absVal / 100n;
    const frac = absVal % 100n;
    const fracStr = frac.toString().padStart(2, '0');
    const formatted = `${whole.toString()}.${fracStr}`;

    return isNegative ? `-${formatted}` : formatted;
}

export function formatAdminMoneyFromHalalah(
    halalah: number | string | bigint | null | undefined,
    locale: 'ar' | 'en' = 'en',
    currency = 'SAR',
): string {
    const minor =
        halalah === null || halalah === undefined ? '0' : String(halalah);

    return formatAdminMoney({ amountMinor: minor, currency }, locale);
}

export function formatAdminMoney(
    money: AdminMoney<string>,
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
