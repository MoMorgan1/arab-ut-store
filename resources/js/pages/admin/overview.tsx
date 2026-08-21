import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    CircleAlert,
    CircleCheck,
    Clock3,
    Hourglass,
    LoaderCircle,
    ShieldAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import type { AdminBadgeVariant } from '@/components/admin/admin-badge';
import AdminKpiStrip from '@/components/admin/admin-kpi-strip';
import AdminWorkQueue from '@/components/admin/admin-work-queue';
import { cn } from '@/lib/utils';
import type { AdminOverviewPageProps } from '@/types/admin';

const statusIcons: Record<string, LucideIcon> = {
    cancelled: CircleAlert,
    completed: CircleCheck,
    failed: ShieldAlert,
    in_progress: Clock3,
    pending: Clock3,
    pending_payment: Clock3,
    received: CircleAlert,
    refunded: CircleCheck,
    waiting_for_customer: Hourglass,
};

function getStatusVariant(status: string): AdminBadgeVariant {
    switch (status) {
        case 'completed':
        case 'refunded':
            return 'success';
        case 'received':
        case 'in_progress':
            return 'info';
        case 'waiting_for_customer':
        case 'pending_payment':
        case 'pending':
            return 'warning';
        case 'failed':
        case 'cancelled':
            return 'danger';
        default:
            return 'neutral';
    }
}

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
        <article className="space-y-5" dir={props.direction}>
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

            <OperationalQueues
                copy={copy}
                dateFormatter={dateFormatter}
                overview={props.overview}
                statuses={props.adminUi.statuses}
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

function OperationalQueues({
    copy,
    dateFormatter,
    overview,
    statuses,
}: {
    copy: AdminOverviewPageProps['adminUi']['overview'];
    dateFormatter: Intl.DateTimeFormat;
    overview: AdminOverviewPageProps['overview'];
    statuses: AdminOverviewPageProps['adminUi']['statuses'];
}) {
    return (
        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 md:items-start">
            <AdminWorkQueue icon={Clock3} title={copy.oldestUnresolved}>
                {overview.oldestUnresolvedOrder === null ? (
                    <p className="flex min-h-16 items-center gap-2.5 text-sm text-muted-foreground">
                        <CircleCheck
                            aria-hidden="true"
                            className="h-4 w-4 shrink-0 text-muted-foreground"
                        />
                        <span>{copy.noUnresolved}</span>
                    </p>
                ) : (
                    <OldestOrder
                        dateFormatter={dateFormatter}
                        order={overview.oldestUnresolvedOrder}
                        statusLabel={
                            statuses[overview.oldestUnresolvedOrder.status] ??
                            overview.oldestUnresolvedOrder.status
                        }
                    />
                )}
            </AdminWorkQueue>
            <AuditQueue
                copy={copy}
                dateFormatter={dateFormatter}
                events={overview.recentAuditEvents}
            />
        </div>
    );
}

function AuditQueue({
    copy,
    dateFormatter,
    events,
}: {
    copy: AdminOverviewPageProps['adminUi']['overview'];
    dateFormatter: Intl.DateTimeFormat;
    events: AdminOverviewPageProps['overview']['recentAuditEvents'];
}) {
    if (events === null) {
        return null;
    }

    return (
        <AdminWorkQueue icon={Activity} title={copy.recentAudit}>
            {events.length === 0 ? (
                <p className="flex min-h-16 items-center gap-2.5 text-sm text-muted-foreground">
                    <Activity
                        aria-hidden="true"
                        className="h-4 w-4 shrink-0 text-muted-foreground"
                    />
                    <span>{copy.noAudit}</span>
                </p>
            ) : (
                <ol className="divide-y divide-border">
                    {events.map((event) => (
                        <li key={event.id} className="grid gap-1 py-3">
                            <span
                                className="font-medium text-foreground"
                                translate="no"
                            >
                                {event.action}
                            </span>
                            <time
                                className="text-xs text-muted-foreground tabular-nums"
                                dateTime={event.createdAt}
                            >
                                {dateFormatter.format(
                                    new Date(event.createdAt),
                                )}
                            </time>
                        </li>
                    ))}
                </ol>
            )}
        </AdminWorkQueue>
    );
}

function OldestOrder({
    dateFormatter,
    order,
    statusLabel,
}: {
    dateFormatter: Intl.DateTimeFormat;
    order: NonNullable<
        AdminOverviewPageProps['overview']['oldestUnresolvedOrder']
    >;
    statusLabel: string;
}) {
    const StatusIcon = statusIcons[order.status] ?? CircleAlert;
    const variant = getStatusVariant(order.status);

    return (
        <div className="grid gap-2.5">
            <strong
                className="text-base font-bold text-foreground tabular-nums"
                translate="no"
            >
                {order.number}
            </strong>
            <div>
                <AdminBadge icon={StatusIcon} variant={variant}>
                    {statusLabel}
                </AdminBadge>
            </div>
            <time
                className="text-xs text-muted-foreground tabular-nums"
                dateTime={order.placedAt}
            >
                {dateFormatter.format(new Date(order.placedAt))}
            </time>
        </div>
    );
}
