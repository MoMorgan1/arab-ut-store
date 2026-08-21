import {
    AlertTriangle,
    ArrowDownRight,
    ArrowUpRight,
    CheckCircle2,
    CircleDollarSign,
    Minus,
    ShoppingBag,
    Users,
} from 'lucide-react';

import { formatAdminMoney } from '@/components/admin/admin-money';
import { cn } from '@/lib/utils';
import type { AdminOverviewPageProps, AdminTranslations } from '@/types/admin';

export default function AdminKpiStrip({
    locale,
    overview,
    translations,
}: {
    locale: 'ar' | 'en';
    overview: AdminOverviewPageProps['overview'];
    translations: AdminTranslations['overview'];
}) {
    const numberFormatter = new Intl.NumberFormat(locale);
    const revenueComparison = calculateBigIntComparison(
        BigInt(overview.capturedRevenue.amountMinor),
        BigInt(overview.previousCapturedRevenue.amountMinor),
        translations,
    );
    const ordersComparison = calculateBigIntComparison(
        BigInt(overview.totalOrders.current),
        BigInt(overview.totalOrders.previous),
        translations,
    );
    const customersComparison = calculateBigIntComparison(
        BigInt(overview.newCustomers.current),
        BigInt(overview.newCustomers.previous),
        translations,
    );

    const hasUrgentAttention =
        overview.payments.failed > 0 || overview.refunds.failed > 0;

    return (
        <dl
            aria-label={translations.title}
            className="admin-kpi-strip grid grid-cols-1 gap-6 rounded-xl border border-border bg-card p-4 sm:grid-cols-3 md:p-6 lg:grid-cols-12 lg:items-center lg:gap-6"
        >
            <div className="flex flex-col gap-2 sm:col-span-3 lg:col-span-5 lg:border-e lg:border-border/60 lg:pe-6">
                <dt className="flex items-center gap-1.5 text-sm font-medium text-muted-foreground">
                    <CircleDollarSign
                        aria-hidden="true"
                        className="h-4 w-4 shrink-0 text-primary"
                    />
                    <span>{translations.capturedRevenue}</span>
                </dt>
                <dd className="text-2xl font-bold tracking-tight text-foreground tabular-nums sm:text-3xl lg:text-4xl">
                    {formatAdminMoney(overview.capturedRevenue, locale)}
                </dd>
                <div className="flex items-center text-xs text-muted-foreground">
                    <ComparisonBadge comparison={revenueComparison} />
                </div>
            </div>

            <div className="flex flex-col gap-1 sm:ps-0 lg:col-span-2">
                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                    <ShoppingBag
                        aria-hidden="true"
                        className="h-3.5 w-3.5 shrink-0"
                    />
                    <span>{translations.totalOrders}</span>
                </dt>
                <dd className="text-xl font-bold tracking-tight text-foreground tabular-nums md:text-2xl">
                    {numberFormatter.format(overview.totalOrders.current)}
                </dd>
                <div className="flex items-center text-[11px] text-muted-foreground">
                    <ComparisonBadge comparison={ordersComparison} />
                </div>
            </div>

            <div className="flex flex-col gap-1 sm:border-s sm:border-border/60 sm:ps-4 lg:col-span-2">
                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                    <Users
                        aria-hidden="true"
                        className="h-3.5 w-3.5 shrink-0"
                    />
                    <span>{translations.newCustomers}</span>
                </dt>
                <dd className="text-xl font-bold tracking-tight text-foreground tabular-nums md:text-2xl">
                    {numberFormatter.format(overview.newCustomers.current)}
                </dd>
                <div className="flex items-center text-[11px] text-muted-foreground">
                    <ComparisonBadge comparison={customersComparison} />
                </div>
            </div>

            <div className="flex flex-col gap-1 sm:border-s sm:border-border/60 sm:ps-4 lg:col-span-3">
                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                    {hasUrgentAttention ? (
                        <AlertTriangle
                            aria-hidden="true"
                            className="h-3.5 w-3.5 shrink-0 text-status-danger"
                        />
                    ) : overview.attentionCount > 0 ? (
                        <AlertTriangle
                            aria-hidden="true"
                            className="h-3.5 w-3.5 shrink-0 text-status-warning"
                        />
                    ) : (
                        <CheckCircle2
                            aria-hidden="true"
                            className="h-3.5 w-3.5 shrink-0 text-status-success"
                        />
                    )}
                    <span
                        className={cn(
                            hasUrgentAttention && 'text-status-danger',
                        )}
                    >
                        {translations.needsAttention}
                    </span>
                </dt>
                <dd
                    className={cn(
                        'text-xl font-bold tracking-tight tabular-nums md:text-2xl',
                        hasUrgentAttention
                            ? 'text-status-danger'
                            : overview.attentionCount > 0
                              ? 'text-status-warning'
                              : 'text-foreground',
                    )}
                >
                    {numberFormatter.format(overview.attentionCount)}
                </dd>
                <div className="text-[11px] text-muted-foreground">
                    {overview.attentionCount === 0 ? (
                        <span className="text-status-success">
                            {translations.noUnresolved}
                        </span>
                    ) : (
                        <span>
                            {overview.orders.waitingForCustomer}{' '}
                            {translations.waitingForCustomer}
                        </span>
                    )}
                </div>
            </div>
        </dl>
    );
}

type ComparisonResult = {
    type: 'increase' | 'decrease' | 'new' | 'none';
    label: string;
};

function ComparisonBadge({ comparison }: { comparison: ComparisonResult }) {
    if (comparison.type === 'new') {
        return (
            <span className="inline-flex items-center font-medium text-primary">
                {comparison.label}
            </span>
        );
    }

    if (comparison.type === 'none') {
        return (
            <span className="inline-flex items-center gap-1 text-muted-foreground">
                <Minus aria-hidden="true" className="h-3 w-3" />
                <span>{comparison.label}</span>
            </span>
        );
    }

    const isPositive = comparison.type === 'increase';

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 font-medium tabular-nums',
                isPositive ? 'text-status-success' : 'text-muted-foreground',
            )}
        >
            {isPositive ? (
                <ArrowUpRight
                    aria-hidden="true"
                    className="h-3.5 w-3.5 shrink-0"
                />
            ) : (
                <ArrowDownRight
                    aria-hidden="true"
                    className="h-3.5 w-3.5 shrink-0"
                />
            )}
            <span>{comparison.label}</span>
        </span>
    );
}

function calculateBigIntComparison(
    currentMinor: bigint,
    previousMinor: bigint,
    translations: AdminTranslations['overview'],
): ComparisonResult {
    if (previousMinor === 0n) {
        if (currentMinor === 0n) {
            return { type: 'none', label: translations.noChange };
        }

        return { type: 'new', label: translations.newThisPeriod };
    }

    const diff = currentMinor - previousMinor;
    const absDiff = diff < 0n ? -diff : diff;
    const absPrev = previousMinor < 0n ? -previousMinor : previousMinor;
    const tenths = (absDiff * 1000n + absPrev / 2n) / absPrev;
    const quotient = tenths / 10n;
    const remainder = tenths % 10n;
    const sign = diff >= 0n ? '+' : '-';
    const percentageText = `${sign}${quotient}.${remainder}%`;

    if (diff > 0n) {
        return {
            type: 'increase',
            label: `${percentageText} ${translations.previousPeriod}`,
        };
    }

    if (diff < 0n) {
        return {
            type: 'decrease',
            label: `${percentageText} ${translations.previousPeriod}`,
        };
    }

    return { type: 'none', label: translations.noChange };
}
