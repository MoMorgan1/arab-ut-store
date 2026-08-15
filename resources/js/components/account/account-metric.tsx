import { CheckCircle2, Clock3, PackageCheck, WalletCards } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

type AccountMetricKind = 'orders' | 'open' | 'completed' | 'wallet';

const metricIcons: Record<AccountMetricKind, LucideIcon> = {
    orders: PackageCheck,
    open: Clock3,
    completed: CheckCircle2,
    wallet: WalletCards,
};

export default function AccountMetric({
    kind,
    label,
    value,
}: {
    kind: AccountMetricKind;
    label: string;
    value: string;
}) {
    const Icon = metricIcons[kind];

    return (
        <div className="account-metric">
            <span aria-hidden="true" className="account-metric__icon">
                <Icon />
            </span>
            <div>
                <dt>{label}</dt>
                <dd>{value}</dd>
            </div>
        </div>
    );
}
