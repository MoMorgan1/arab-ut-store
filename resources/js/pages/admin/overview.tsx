import { Head, Link, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useState } from 'react';

import AdminAttentionRail from '@/components/admin/admin-attention-rail';
import AdminKpiStrip from '@/components/admin/admin-kpi-strip';
import AdminOrderStatusChart from '@/components/admin/admin-order-status-chart';
import AdminRecentOrders from '@/components/admin/admin-recent-orders';
import AdminRevenueChart from '@/components/admin/admin-revenue-chart';
import { cn } from '@/lib/utils';
import type { AdminOverviewPageProps } from '@/types/admin';

export default function AdminOverviewPage() {
    const { props } = usePage<AdminOverviewPageProps>();
    const [loadingDays, setLoadingDays] = useState<7 | 30 | null>(null);
    const copy = props.adminUi.overview;
    const dateFormatter = new Intl.DateTimeFormat(props.locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    return (
        <article className="space-y-6" dir={props.direction}>
            <Head title={copy.headTitle} />
            <OverviewHeader
                copy={copy}
                loadingDays={loadingDays}
                locale={props.locale}
                onFinish={() => setLoadingDays(null)}
                onStart={setLoadingDays}
                rangeOptions={props.rangeOptions}
            />

            <AdminKpiStrip
                locale={props.locale}
                overview={props.overview}
                translations={copy}
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                <div className="order-2 lg:order-1 lg:col-span-8">
                    <AdminRevenueChart
                        locale={props.locale}
                        overview={props.overview}
                        translations={copy}
                    />
                </div>
                <div className="order-3 lg:order-2 lg:col-span-4">
                    <AdminOrderStatusChart
                        locale={props.locale}
                        overview={props.overview}
                        statuses={props.adminUi.statuses}
                        translations={copy}
                    />
                </div>
                <div className="order-4 lg:order-3 lg:col-span-8">
                    <AdminRecentOrders
                        dateFormatter={dateFormatter}
                        locale={props.locale}
                        orders={props.overview.recentOrders}
                        statuses={props.adminUi.statuses}
                        translations={copy}
                    />
                </div>
                <div className="order-1 lg:order-4 lg:col-span-4">
                    <AdminAttentionRail
                        dateFormatter={dateFormatter}
                        locale={props.locale}
                        overview={props.overview}
                        statuses={props.adminUi.statuses}
                        translations={copy}
                    />
                </div>
            </div>
        </article>
    );
}

function OverviewHeader({
    copy,
    loadingDays,
    locale,
    onFinish,
    onStart,
    rangeOptions,
}: {
    copy: AdminOverviewPageProps['adminUi']['overview'];
    loadingDays: 7 | 30 | null;
    locale: 'ar' | 'en';
    onFinish: () => void;
    onStart: (days: 7 | 30) => void;
    rangeOptions: AdminOverviewPageProps['rangeOptions'];
}) {
    const rangeLabel = locale === 'ar' ? 'نطاق التاريخ' : 'Date range';

    return (
        <header className="flex flex-col gap-4 border-b border-border pb-5">
            <div className="space-y-1">
                <h1 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">
                    {copy.title}
                </h1>
                <p className="max-w-prose text-sm text-muted-foreground">
                    {copy.description}
                </p>
            </div>
            <nav
                aria-busy={loadingDays !== null}
                aria-label={rangeLabel}
                className="flex flex-wrap items-center gap-2"
            >
                {rangeOptions.map((option) => {
                    const isLoading = loadingDays === option.days;

                    return (
                        <Link
                            aria-current={option.active ? 'page' : undefined}
                            className={cn(
                                'inline-flex min-h-[44px] items-center gap-2 rounded-md border px-3 text-sm font-medium hover:bg-accent focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring',
                                option.active
                                    ? 'border-transparent bg-primary text-primary-foreground'
                                    : 'border-border bg-card text-muted-foreground',
                                isLoading && 'opacity-70',
                            )}
                            data-loading={isLoading ? 'true' : undefined}
                            href={option.url}
                            key={option.days}
                            onFinish={onFinish}
                            onStart={() => onStart(option.days)}
                            preserveScroll
                        >
                            {isLoading ? (
                                <LoaderCircle
                                    aria-hidden="true"
                                    className="h-3.5 w-3.5 animate-spin motion-reduce:hidden"
                                />
                            ) : null}
                            <span>{option.label}</span>
                        </Link>
                    );
                })}
            </nav>
        </header>
    );
}
