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

import AdminKpiStrip from '@/components/admin/admin-kpi-strip';
import AdminWorkQueue from '@/components/admin/admin-work-queue';
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
        <article className="admin-overview" dir={props.direction}>
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
        <header className="admin-overview__header">
            <div>
                <h1>{copy.title}</h1>
                <p>{copy.description}</p>
            </div>
            <nav
                aria-busy={loadingDays !== null}
                aria-label={rangeLabel}
                className="admin-range-navigation"
            >
                {rangeOptions.map((option) => (
                    <Link
                        aria-current={option.active ? 'page' : undefined}
                        className="admin-range-navigation__link"
                        data-loading={
                            loadingDays === option.days ? 'true' : undefined
                        }
                        href={option.url}
                        key={option.days}
                        onFinish={onFinish}
                        onStart={() => onStart(option.days)}
                        preserveScroll
                    >
                        {loadingDays === option.days ? (
                            <LoaderCircle
                                aria-hidden="true"
                                className="admin-range-navigation__spinner"
                            />
                        ) : null}
                        <span>{option.label}</span>
                    </Link>
                ))}
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
        <div className="admin-overview__queues">
            <AdminWorkQueue icon={Clock3} title={copy.oldestUnresolved}>
                {overview.oldestUnresolvedOrder === null ? (
                    <p className="admin-work-queue__empty">
                        <CircleCheck aria-hidden="true" />
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
                <p className="admin-work-queue__empty">
                    <Activity aria-hidden="true" />
                    <span>{copy.noAudit}</span>
                </p>
            ) : (
                <ol className="admin-audit-list">
                    {events.map((event) => (
                        <li key={event.id}>
                            <span translate="no">{event.action}</span>
                            <time dateTime={event.createdAt}>
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

    return (
        <div className="admin-oldest-order">
            <strong translate="no">{order.number}</strong>
            <span className="admin-status" data-status={order.status}>
                <StatusIcon aria-hidden="true" />
                <span>{statusLabel}</span>
            </span>
            <time dateTime={order.placedAt}>
                {dateFormatter.format(new Date(order.placedAt))}
            </time>
        </div>
    );
}
