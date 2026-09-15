import { currencyLabel } from '@/lib/money';
import type { AccountMoney } from '@/types/account';

function localizedDigits(value: string, locale: string): string {
    const formatter = new Intl.NumberFormat(locale, { useGrouping: false });

    return [...value]
        .map((digit) => formatter.format(Number.parseInt(digit, 10)))
        .join('');
}

export function formatAccountMoney(
    money: AccountMoney,
    locale: 'ar' | 'en',
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
    const parts = formatter
        .formatToParts(wholeAmount)
        .map((part) =>
            part.type === 'currency'
                ? { ...part, value: currencyLabel(money.currency, locale) }
                : part,
        );

    if (minorDigits === 0) {
        return parts.map(currencyWithoutStop).join('');
    }

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

    return parts.map(currencyWithoutStop).join('');
}

/**
 * ICU abbreviates the riyal in Arabic as "ر.س." with a closing full stop. The
 * riyal typeface turns "ر.س" into the currency symbol, which leaves that stop
 * standing alone after the symbol. Drop it; the English "SAR" has none.
 */
function currencyWithoutStop(part: Intl.NumberFormatPart): string {
    return part.type === 'currency'
        ? part.value.replace(/\.$/, '')
        : part.value;
}
