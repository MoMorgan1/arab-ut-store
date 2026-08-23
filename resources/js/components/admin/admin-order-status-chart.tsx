import { BarChart3, Package } from 'lucide-react';
import {
    Bar,
    BarChart,
    Cell,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import { getStatusCssColor } from '@/components/admin/admin-order-status';
import type { AdminOverviewPageProps, AdminTranslations } from '@/types/admin';

export default function AdminOrderStatusChart({
    locale,
    overview,
    statuses,
    translations,
}: {
    locale: 'ar' | 'en';
    overview: AdminOverviewPageProps['overview'];
    statuses: AdminOverviewPageProps['adminUi']['statuses'];
    translations: AdminTranslations['overview'];
}) {
    const totalPlaced = overview.orderStatusDistribution.reduce(
        (sum, item) => sum + item.count,
        0,
    );

    const isAllZero = totalPlaced === 0;

    const chartData = overview.orderStatusDistribution.map((item) => ({
        status: item.status,
        label: statuses[item.status] ?? item.status,
        count: item.count,
        percentage:
            totalPlaced > 0
                ? ((item.count / totalPlaced) * 100).toFixed(0)
                : '0',
    }));

    const numberFormatter = new Intl.NumberFormat(locale);
    const barRadius: [number, number, number, number] =
        locale === 'ar' ? [4, 0, 0, 4] : [0, 4, 4, 0];

    return (
        <section
            aria-label={translations.orderDistributionTitle}
            className="flex h-full flex-col rounded-xl border border-border bg-card p-4 md:p-6"
        >
            <header className="flex flex-col gap-1 pb-4">
                <div className="flex items-center gap-2">
                    <BarChart3
                        aria-hidden="true"
                        className="h-4 w-4 shrink-0 text-muted-foreground"
                    />
                    <h2 className="text-base font-semibold text-card-foreground">
                        {translations.orderDistributionTitle}
                    </h2>
                </div>
                <p className="text-xs text-muted-foreground">
                    {translations.orderDistributionDescription}
                </p>
            </header>

            <div className="sr-only">
                <table>
                    <caption>{translations.orderDistributionTitle}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{translations.status}</th>
                            <th scope="col">{translations.count}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {overview.orderStatusDistribution.map((item) => (
                            <tr key={item.status}>
                                <td>{statuses[item.status] ?? item.status}</td>
                                <td>{numberFormatter.format(item.count)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="flex flex-1 flex-col justify-between gap-4">
                {isAllZero ? (
                    <div className="flex h-64 flex-col items-center justify-center gap-2 text-center text-muted-foreground">
                        <Package
                            aria-hidden="true"
                            className="h-8 w-8 text-muted-foreground/50"
                        />
                        <p className="text-sm font-medium">
                            {translations.noOrdersInPeriod}
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="h-40 w-full md:h-[140px]">
                            <ResponsiveContainer
                                height="100%"
                                initialDimension={{ width: 300, height: 140 }}
                                width="100%"
                            >
                                <BarChart
                                    accessibilityLayer
                                    data={chartData}
                                    layout="vertical"
                                    margin={{
                                        top: 4,
                                        right: 12,
                                        left: -20,
                                        bottom: 4,
                                    }}
                                >
                                    <XAxis
                                        allowDecimals={false}
                                        axisLine={false}
                                        className="text-[11px] text-muted-foreground"
                                        tickLine={false}
                                        type="number"
                                    />
                                    <YAxis
                                        axisLine={false}
                                        className="text-[11px] text-muted-foreground"
                                        dataKey="label"
                                        hide
                                        type="category"
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
                                                .payload as (typeof chartData)[number];

                                            return (
                                                <div className="rounded-lg border border-border bg-popover px-3 py-2 text-popover-foreground shadow-md">
                                                    <p className="text-xs text-muted-foreground">
                                                        {item.label}
                                                    </p>
                                                    <p className="text-sm font-bold text-foreground tabular-nums">
                                                        {numberFormatter.format(
                                                            item.count,
                                                        )}{' '}
                                                        ({item.percentage}%)
                                                    </p>
                                                </div>
                                            );
                                        }}
                                    />
                                    <Bar
                                        dataKey="count"
                                        isAnimationActive={false}
                                        radius={barRadius}
                                    >
                                        {chartData.map((entry) => (
                                            <Cell
                                                fill={getStatusCssColor(
                                                    entry.status,
                                                )}
                                                key={`cell-${entry.status}`}
                                            />
                                        ))}
                                    </Bar>
                                </BarChart>
                            </ResponsiveContainer>
                        </div>

                        <div className="divide-y divide-border/60 border-t border-border/60 pt-2 text-xs">
                            {overview.orderStatusDistribution
                                .filter((item) => item.count > 0)
                                .map((item) => (
                                    <div
                                        className="flex items-center justify-between py-1.5"
                                        key={item.status}
                                    >
                                        <div className="flex items-center gap-2">
                                            <span
                                                aria-hidden="true"
                                                className="h-2 w-2 rounded-full"
                                                style={{
                                                    backgroundColor:
                                                        getStatusCssColor(
                                                            item.status,
                                                        ),
                                                }}
                                            />
                                            <span className="font-medium text-foreground">
                                                {statuses[item.status] ??
                                                    item.status}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className="font-bold text-foreground tabular-nums">
                                                {numberFormatter.format(
                                                    item.count,
                                                )}
                                            </span>
                                            <span className="text-[11px] text-muted-foreground tabular-nums">
                                                (
                                                {totalPlaced > 0
                                                    ? (
                                                          (item.count /
                                                              totalPlaced) *
                                                          100
                                                      ).toFixed(0)
                                                    : '0'}
                                                %)
                                            </span>
                                        </div>
                                    </div>
                                ))}
                        </div>
                    </>
                )}
            </div>
        </section>
    );
}
