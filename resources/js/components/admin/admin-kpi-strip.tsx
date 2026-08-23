import {
    ArrowDownRight,
    ArrowUpRight,
    CircleDollarSign,
    Clock3,
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

    const ordersInFlightCount =
        overview.orders.received +
        overview.orders.inProgress +
        overview.orders.waitingForCustomer;

    const ordersInFlightLabel =
        translations.ordersInFlight ?? 'Orders in flight';

    return (
        <dl
            aria-label={translations.title}
            className="admin-kpi-strip grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4"
        >
            {/* 1. Captured revenue */}
            <div className="flex flex-col justify-between gap-1.5 rounded-xl border border-border bg-card p-3.5 sm:p-4 md:p-5">
                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground sm:text-sm">
                    <CircleDollarSign
                        aria-hidden="true"
                        className="h-4 w-4 shrink-0 text-primary"
                    />
                    <span className="truncate">
                        {translations.capturedRevenue}
                    </span>
                </dt>
                <dd className="text-xl font-bold tracking-tight text-foreground tabular-nums sm:text-2xl lg:text-3xl">
                    {formatAdminMoney(overview.capturedRevenue, locale)}
                </dd>
                <div className="flex items-center text-[10px] text-muted-foreground sm:text-xs">
                    <ComparisonBadge comparison={revenueComparison} />
                </div>
            </div>

            {/* 2. Total orders */}
            <div className="flex flex-col justify-between gap-1.5 rounded-xl border border-border bg-card p-3.5 sm:p-4 md:p-5">
                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground sm:text-sm">
                    <ShoppingBag
                        aria-hidden="true"
                        className="h-4 w-4 shrink-0 text-primary"
                    />
                    <span className="truncate">{translations.totalOrders}</span>
                </dt>
                <dd className="text-xl font-bold tracking-tight text-foreground tabular-nums sm:text-2xl lg:text-3xl">
                    {numberFormatter.format(overview.totalOrders.current)}
                </dd>
                <div className="flex items-center text-[10px] text-muted-foreground sm:text-xs">
                    <ComparisonBadge comparison={ordersComparison} />
                </div>
            </div>

            {/* 3. Orders in flight */}
            <div className="flex flex-col justify-between gap-1.5 rounded-xl border border-border bg-card p-3.5 sm:p-4 md:p-5">
                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground sm:text-sm">
                    <Clock3
                        aria-hidden="true"
                        className="h-4 w-4 shrink-0 text-primary"
                    />
                    <span className="truncate">{ordersInFlightLabel}</span>
                </dt>
                <dd className="text-xl font-bold tracking-tight text-foreground tabular-nums sm:text-2xl lg:text-3xl">
                    {numberFormatter.format(ordersInFlightCount)}
                </dd>
                <div className="flex items-center text-[10px] text-muted-foreground sm:text-xs">
                    <span className="truncate">
                        {numberFormatter.format(overview.orders.received)}{' '}
                        {translations.receivedOrders}
                    </span>
                </div>
            </div>

            {/* 4. New customers */}
            <div className="flex flex-col justify-between gap-1.5 rounded-xl border border-border bg-card p-3.5 sm:p-4 md:p-5">
                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground sm:text-sm">
                    <Users
                        aria-hidden="true"
                        className="h-4 w-4 shrink-0 text-primary"
                    />
                    <span className="truncate">
                        {translations.newCustomers}
                    </span>
                </dt>
                <dd className="text-xl font-bold tracking-tight text-foreground tabular-nums sm:text-2xl lg:text-3xl">
                    {numberFormatter.format(overview.newCustomers.current)}
                </dd>
                <div className="flex items-center text-[10px] text-muted-foreground sm:text-xs">
                    <ComparisonBadge comparison={customersComparison} />
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
