import { Link } from '@inertiajs/react';
import { Clock3, WalletCards } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

type AccountMetricKind = 'open' | 'wallet';

const metricIcons: Record<AccountMetricKind, LucideIcon> = {
    open: Clock3,
    wallet: WalletCards,
};

/**
 * One number the customer cares about, with a one-line note under it and
 * the page it opens. Two of these lead the overview.
 */
export default function AccountMetric({
    accent = false,
    href,
    kind,
    label,
    note,
    value,
}: {
    accent?: boolean;
    href?: string;
    kind: AccountMetricKind;
    label: string;
    note?: string;
    value: string;
}) {
    const Icon = metricIcons[kind];
    const body = (
        <>
            <div className="account-metric__header">
                <span aria-hidden="true" className="account-metric__icon">
                    <Icon />
                </span>
                <dt>{label}</dt>
            </div>
            <dd>
                <span>{value}</span>
                {note ? <small>{note}</small> : null}
            </dd>
        </>
    );

    return (
        <div
            className={cn(
                'account-metric',
                `account-metric--${kind}`,
                accent && 'account-metric--accent',
            )}
        >
            {href ? (
                <Link className="account-metric__link" href={href}>
                    {body}
                </Link>
            ) : (
                body
            )}
        </div>
    );
}
