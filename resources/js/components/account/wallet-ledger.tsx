import { Link } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    RefreshCcw,
    RotateCcw,
    SlidersHorizontal,
    Sparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { formatAccountMoney } from '@/lib/account-money';
import { DATE_LOCALE } from '@/lib/date-locale';
import type { AccountTranslations, AccountWalletEntry } from '@/types/account';

type WalletLedgerProps = {
    entries: AccountWalletEntry[];
    locale: 'ar' | 'en';
    translations: AccountTranslations['wallet'];
};

const icons: Record<AccountWalletEntry['type'], LucideIcon> = {
    adjustment: SlidersHorizontal,
    credit: ArrowDownLeft,
    debit: ArrowUpRight,
    refund: RefreshCcw,
    cashback: Sparkles,
    cashback_reversal: RotateCcw,
};

export default function WalletLedger({
    entries,
    locale,
    translations,
}: WalletLedgerProps) {
    const dateFormatter = new Intl.DateTimeFormat(DATE_LOCALE, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    return (
        <section
            aria-label={translations.ledger_title}
            className="account-wallet-ledger"
        >
            <h3>{translations.ledger_title}</h3>
            <ol>
                {entries.map((entry) => {
                    const Icon = icons[entry.type];
                    const amount = formatAccountMoney(entry.amount, locale);
                    const signedAmount =
                        entry.effect === 'credit'
                            ? `+${amount}`
                            : entry.effect === 'debit'
                              ? `−${amount}`
                              : amount;

                    return (
                        <li data-effect={entry.effect} key={entry.id}>
                            <span
                                aria-hidden="true"
                                className="account-wallet-ledger__icon"
                            >
                                <Icon />
                            </span>
                            <div className="account-wallet-ledger__main">
                                <div>
                                    <strong>{translations[entry.type]}</strong>
                                    <bdi>{signedAmount}</bdi>
                                </div>
                                <p>
                                    {entry.createdAt === null ? null : (
                                        <time dateTime={entry.createdAt}>
                                            {dateFormatter.format(
                                                new Date(entry.createdAt),
                                            )}
                                        </time>
                                    )}
                                    {entry.order === null ? null : (
                                        <Link href={entry.order.url}>
                                            {translations.related_order.replace(
                                                ':number',
                                                entry.order.number,
                                            )}
                                        </Link>
                                    )}
                                </p>
                            </div>
                            <div className="account-wallet-ledger__balance">
                                <span>{translations.balance_after}</span>
                                <bdi>
                                    {formatAccountMoney(
                                        entry.balanceAfter,
                                        locale,
                                    )}
                                </bdi>
                            </div>
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}
