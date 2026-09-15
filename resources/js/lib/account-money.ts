import { joinMoneyParts } from '@/lib/money';
import type { AccountMoney } from '@/types/account';

function localizedDigits(value: string, locale: string): string {
    const formatter = new Intl.NumberFormat(locale, { useGrouping: false });

    return [...value]
        .map((digit) => formatter.format(Number.parseInt(digit, 10)))
        .join('');
}

/**
 * Formats an account amount the way the store does (see `joinMoneyParts`):
 * Arabic reads «ر.س 129.99», English «SAR 129.99». A ledger sign travels
 * inside the figure so it never ends up on the wrong side of the label.
 */
export function formatAccountMoney(
    money: AccountMoney,
    locale: 'ar' | 'en',
    sign: '+' | '−' | '' = '',
): string {
    const currencyOptions = new Intl.NumberFormat(locale, {
        currency: money.currency,
        style: 'currency',
    }).resolvedOptions();
    const minorDigits = currencyOptions.maximumFractionDigits ?? 2;
    const divisor = 10n ** BigInt(minorDigits);
    const minorAmount = BigInt(money.amountMinor);
    const wholeAmount = minorAmount / divisor;
    const fraction = (minorAmount % divisor)
        .toString()
        .padStart(minorDigits, '0');
    const formatter = new Intl.NumberFormat(locale, {
        currency: money.currency,
        maximumFractionDigits: 0,
        minimumFractionDigits: 0,
        style: 'currency',
    });
    const parts: Intl.NumberFormatPart[] = formatter.formatToParts(wholeAmount);

    if (minorDigits > 0) {
        const decimal =
            new Intl.NumberFormat(locale, {
                maximumFractionDigits: 1,
                minimumFractionDigits: 1,
                useGrouping: false,
            })
                .formatToParts(0.1)
                .find((part) => part.type === 'decimal')?.value ?? '.';
        const lastNumberPart = parts.findLastIndex(
            (part) => part.type === 'integer' || part.type === 'group',
        );

        parts.splice(
            lastNumberPart + 1,
            0,
            { type: 'decimal', value: decimal },
            { type: 'fraction', value: localizedDigits(fraction, locale) },
        );
    }

    return joinMoneyParts(parts, money.currency, locale, undefined, sign);
}
