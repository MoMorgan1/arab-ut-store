import { Link } from '@inertiajs/react';
import { ArrowRight, CircleDollarSign, TrendingUp } from 'lucide-react';
import {
    Bar,
    BarChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import { formatAdminMoney } from '@/components/admin/admin-money';
import type {
    AdminOverviewPageProps,
    AdminRevenueTrendPoint,
    AdminTranslations,
} from '@/types/admin';

export default function AdminRevenueChart({
    locale,
    ordersUrl,
    overview,
    translations,
}: {
    locale: 'ar' | 'en';
    ordersUrl: string;
    overview: AdminOverviewPageProps['overview'];
    translations: AdminTranslations['overview'];
}) {
    const isAllZero = overview.revenueTrend.every(
        (point) => point.amountMinor === '0' || point.amountMinor === '',
    );

    const maxSafeInteger = BigInt(Number.MAX_SAFE_INTEGER);
    const maxMagnitude = overview.revenueTrend.reduce((max, point) => {
        const minor = BigInt(point.amountMinor || '0');
        const abs = minor < 0n ? -minor : minor;

        return abs > max ? abs : max;
    }, 0n);

    const divisor =
        maxMagnitude <= maxSafeInteger
            ? 1n
            : (maxMagnitude + maxSafeInteger - 1n) / maxSafeInteger;

    const chartData = overview.revenueTrend.map((point) => {
        const minor = BigInt(point.amountMinor || '0');

        return {
            date: point.date,
            amountMinor: point.amountMinor,
            currency: point.currency,
            displayValue: Number(minor / divisor),
        };
    });

    const shortDateFormatter = new Intl.DateTimeFormat(locale, {
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    });

    const fullDateFormatter = new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeZone: 'UTC',
    });

    return (
        <section
            aria-label={translations.revenueTrendTitle}
            className="flex h-full flex-col rounded-xl border border-border bg-card p-4 md:p-6"
        >
            <header className="flex flex-col gap-2 pb-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-col gap-1">
                    <div className="flex items-center gap-2">
                        <TrendingUp
                            aria-hidden="true"
                            className="h-4 w-4 shrink-0 text-primary"
                        />
                        <h2 className="text-base font-semibold text-card-foreground">
                            {translations.revenueTrendTitle}
                        </h2>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {translations.revenueTrendDescription}
                    </p>
                </div>

                <Link
                    className="inline-flex min-h-[44px] items-center gap-1.5 text-xs font-semibold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                    href={ordersUrl}
                >
                    <span>
                        {translations.viewAllOrders ?? 'View all orders'}
                    </span>
                    <ArrowRight
                        aria-hidden="true"
                        className="h-3.5 w-3.5 rtl:rotate-180"
                    />
                </Link>
            </header>

            <div className="sr-only">
                <table>
                    <caption>{translations.revenueTableAria}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{translations.date}</th>
                            <th scope="col">{translations.revenue}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {overview.revenueTrend.map((point) => (
                            <tr key={point.date}>
                                <td>
                                    {fullDateFormatter.format(
                                        new Date(`${point.date}T00:00:00Z`),
                                    )}
                                </td>
                                <td>{formatAdminMoney(point, locale)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="h-48 w-full md:h-[280px] lg:h-[320px]">
                {isAllZero ? (
                    <div className="flex h-full flex-col items-center justify-center gap-2 text-center text-muted-foreground">
                        <CircleDollarSign
                            aria-hidden="true"
                            className="h-8 w-8 text-muted-foreground/50"
                        />
                        <p className="text-sm font-medium">
                            {translations.noRevenue}
                        </p>
                    </div>
                ) : (
                    <ResponsiveContainer
                        height="100%"
                        initialDimension={{ width: 500, height: 320 }}
                        width="100%"
                    >
                        <BarChart
                            accessibilityLayer
                            data={chartData}
                            margin={{
                                top: 12,
                                right: 12,
                                left: 8,
                                bottom: 0,
                            }}
                        >
                            <XAxis
                                axisLine={false}
                                className="text-[11px] text-muted-foreground"
                                dataKey="date"
                                minTickGap={24}
                                stroke="currentColor"
                                tickFormatter={(val: string) =>
                                    shortDateFormatter.format(
                                        new Date(`${val}T00:00:00Z`),
                                    )
                                }
                                tickLine={false}
                            />
                            <YAxis
                                axisLine={false}
                                className="text-[11px] text-muted-foreground"
                                stroke="currentColor"
                                tickFormatter={(val: number) => {
                                    const approxMinor =
                                        BigInt(Math.round(val)) * divisor;
                                    const approxSar = Number(approxMinor) / 100;

                                    return new Intl.NumberFormat(locale, {
                                        style: 'currency',
                                        currency: 'SAR',
                                        notation: 'compact',
                                        maximumFractionDigits: 1,
                                    }).format(approxSar);
                                }}
                                tickLine={false}
                                width={64}
                            />
                            <Tooltip
                                content={({ active, payload }) => {
                                    if (
                                        !active ||
                                        !payload ||
                                        !payload.length
                                    ) {
                                        return null;
                                    }

                                    const item = payload[0]
                                        .payload as AdminRevenueTrendPoint;
                                    const dateLabel = fullDateFormatter.format(
                                        new Date(`${item.date}T00:00:00Z`),
                                    );

                                    return (
                                        <div className="rounded-lg border border-border bg-popover px-3 py-2 text-popover-foreground shadow-md">
                                            <p className="text-xs text-muted-foreground">
                                                {dateLabel}
                                            </p>
                                            <p className="text-sm font-bold text-primary tabular-nums">
                                                {formatAdminMoney(item, locale)}
                                            </p>
                                        </div>
                                    );
                                }}
                            />
                            <Bar
                                dataKey="displayValue"
                                fill="var(--primary)"
                                isAnimationActive={false}
                                radius={[4, 4, 0, 0]}
                            />
                        </BarChart>
                    </ResponsiveContainer>
                )}
            </div>
        </section>
    );
}
