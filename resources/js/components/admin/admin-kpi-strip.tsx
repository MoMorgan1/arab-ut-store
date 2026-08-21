import {
    CircleDollarSign,
    CircleX,
    Clock3,
    CreditCard,
    Hourglass,
    ListChecks,
    RotateCcw,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';
import type {
    AdminMoney,
    AdminOverviewPageProps,
    AdminTranslations,
} from '@/types/admin';

type Kpi = {
    key: string;
    label: string;
    value: string;
    icon: LucideIcon;
    tone: 'attention' | 'danger' | 'neutral' | 'revenue';
};

export default function AdminKpiStrip({
    locale,
    overview,
    translations,
}: {
    locale: 'ar' | 'en';
    overview: AdminOverviewPageProps['overview'];
    translations: AdminTranslations['overview'];
}) {
    const metrics = buildAdminKpis(locale, overview, translations);

    return (
        <dl className="admin-kpi-strip grid grid-cols-2 gap-4 lg:grid-cols-4">
            {metrics.map((metric) => {
                const Icon = metric.icon;

                return (
                    <div
                        className="flex flex-col gap-2 rounded-xl border border-border bg-card p-4"
                        data-tone={metric.tone}
                        key={metric.key}
                    >
                        <dt
                            className={cn(
                                'flex items-center gap-2 text-sm font-medium',
                                metric.tone === 'danger'
                                    ? 'text-status-danger'
                                    : 'text-muted-foreground',
                            )}
                        >
                            <Icon
                                aria-hidden="true"
                                className="h-4 w-4 shrink-0"
                            />
                            <span>{metric.label}</span>
                        </dt>
                        <dd
                            className={cn(
                                'text-xl font-bold tracking-tight tabular-nums',
                                metric.tone === 'revenue'
                                    ? 'text-primary'
                                    : 'text-foreground',
                            )}
                        >
                            {metric.value}
                        </dd>
                    </div>
                );
            })}
        </dl>
    );
}

function buildAdminKpis(
    locale: 'ar' | 'en',
    overview: AdminOverviewPageProps['overview'],
    translations: AdminTranslations['overview'],
): Kpi[] {
    const number = new Intl.NumberFormat(locale);

    return [
        {
            key: 'received',
            label: translations.receivedOrders,
            value: number.format(overview.orders.received),
            icon: ListChecks,
            tone: 'attention',
        },
        {
            key: 'in-progress',
            label: translations.inProgressOrders,
            value: number.format(overview.orders.inProgress),
            icon: Clock3,
            tone: 'attention',
        },
        {
            key: 'waiting',
            label: translations.waitingForCustomer,
            value: number.format(overview.orders.waitingForCustomer),
            icon: Hourglass,
            tone: 'attention',
        },
        {
            key: 'failed-payments',
            label: translations.failedPayments,
            value: number.format(overview.payments.failed),
            icon: CircleX,
            tone: 'danger',
        },
        {
            key: 'failed-refunds',
            label: translations.failedRefunds,
            value: number.format(overview.refunds.failed),
            icon: RotateCcw,
            tone: 'danger',
        },
        {
            key: 'pending-payments',
            label: translations.pendingPayments,
            value: number.format(overview.payments.pending),
            icon: CreditCard,
            tone: 'neutral',
        },
        {
            key: 'captured-revenue',
            label: translations.capturedRevenue,
            value: formatAdminMoney(overview.capturedRevenue, locale),
            icon: CircleDollarSign,
            tone: 'revenue',
        },
    ];
}

function formatAdminMoney(money: AdminMoney, locale: 'ar' | 'en'): string {
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
