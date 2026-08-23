import { Head, Link, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useState } from 'react';

import AdminAttentionStrip from '@/components/admin/admin-attention-strip';
import AdminKpiStrip from '@/components/admin/admin-kpi-strip';
import AdminRecentOrders from '@/components/admin/admin-recent-orders';
import AdminRevenueChart from '@/components/admin/admin-revenue-chart';
import { cn } from '@/lib/utils';
import type { AdminOverviewPageProps } from '@/types/admin';

export default function AdminOverviewPage() {
    const { props } = usePage<AdminOverviewPageProps>();
    const [loadingDays, setLoadingDays] = useState<1 | 7 | 30 | null>(null);
    const copy = props.adminUi.overview;
    const dateFormatter = new Intl.DateTimeFormat(props.locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    const ordersUrl =
        props.adminNavigation.find((item) => item.key === 'orders')?.url ??
        (props.locale === 'en' ? '/en/admin/orders' : '/admin/orders');

    return (
        <article className="space-y-6" dir={props.direction}>
            <Head title={copy.headTitle} />

            {/* 1. Attention strip */}
            <AdminAttentionStrip
                dateFormatter={dateFormatter}
                locale={props.locale}
                ordersUrl={ordersUrl}
                overview={props.overview}
                statuses={props.adminUi.statuses}
                translations={copy}
            />

            {/* 2. Period toggle & Header */}
            <OverviewHeader
                copy={copy}
                loadingDays={loadingDays}
                locale={props.locale}
                onFinish={() => setLoadingDays(null)}
                onStart={setLoadingDays}
                rangeOptions={props.rangeOptions}
            />

            {/* 2x2 KPI grid */}
            <AdminKpiStrip
                locale={props.locale}
                overview={props.overview}
                translations={copy}
            />

            {/* 3. Revenue bar chart */}
            <AdminRevenueChart
                locale={props.locale}
                ordersUrl={ordersUrl}
                overview={props.overview}
                translations={copy}
            />

            {/* 4. Recent orders */}
            <AdminRecentOrders
                dateFormatter={dateFormatter}
                locale={props.locale}
                orders={props.overview.recentOrders}
                ordersUrl={ordersUrl}
                statuses={props.adminUi.statuses}
                translations={copy}
            />
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
    loadingDays: 1 | 7 | 30 | null;
    locale: 'ar' | 'en';
    onFinish: () => void;
    onStart: (days: 1 | 7 | 30) => void;
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
                                'inline-flex min-h-[44px] min-w-[44px] items-center gap-2 rounded-md border px-3.5 text-sm font-medium hover:bg-accent focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring',
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
