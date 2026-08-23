import {
    CheckCircle2,
    Clock3,
    Lock,
    PackageCheck,
    WalletCards,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

type AccountMetricKind = 'orders' | 'open' | 'completed' | 'wallet';

const metricIcons: Record<AccountMetricKind, LucideIcon> = {
    orders: PackageCheck,
    open: Clock3,
    completed: CheckCircle2,
    wallet: WalletCards,
};

export default function AccountMetric({
    accent = false,
    kind,
    label,
    locked = false,
    value,
}: {
    accent?: boolean;
    kind: AccountMetricKind;
    label: string;
    locked?: boolean;
    value: string;
}) {
    const Icon = metricIcons[kind];

    return (
        <div
            className={cn(
                'account-metric',
                kind === 'wallet' && 'account-metric--wallet',
                (accent || kind === 'open') && 'account-metric--accent',
                locked && 'account-metric--locked',
            )}
        >
            <div className="account-metric__header">
                <span aria-hidden="true" className="account-metric__icon">
                    <Icon />
                </span>
                <dt>{label}</dt>
            </div>
            <dd>
                <span>{value}</span>
                {locked ? (
                    <Lock aria-hidden="true" className="account-metric__lock" />
                ) : null}
            </dd>
        </div>
    );
}
