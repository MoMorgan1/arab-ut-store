import { Clock, ShieldAlert } from 'lucide-react';
import React from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import {
    getStatusVariant,
    statusIcons,
} from '@/components/admin/admin-order-status';
import type { AdminOrderDetail, AdminTranslations } from '@/types/admin';

export type AdminOrderHistoryProps = {
    adminUi: AdminTranslations;
    order: AdminOrderDetail;
    locale: 'ar' | 'en';
};

export default function AdminOrderHistory({
    adminUi,
    order,
    locale,
}: AdminOrderHistoryProps) {
    const copy = adminUi.orderDetail;
    const statuses = adminUi.statuses;
    const dateFormatter = new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    return (
        <div className="flex flex-col gap-6">
            <section
                aria-labelledby="history-section-heading"
                className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 text-card-foreground shadow-xs"
            >
                <div className="flex items-center gap-2 border-b border-border/60 pb-3">
                    <Clock
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                    />
                    <h3
                        className="text-base font-semibold text-foreground"
                        id="history-section-heading"
                    >
                        {copy.historySection}
                    </h3>
                </div>

                {order.statusHistory.length === 0 ? (
                    <p className="py-2 text-xs text-muted-foreground">
                        {copy.noHistory}
                    </p>
                ) : (
                    <ol className="relative flex flex-col gap-4 border-s border-border ps-4 text-xs">
                        {order.statusHistory.map((entry) => {
                            const Icon = statusIcons[entry.status];
                            const statusLabel =
                                statuses[entry.status] ?? entry.status;

                            return (
                                <li
                                    className="relative flex flex-col gap-1"
                                    key={entry.id}
                                >
                                    <span
                                        aria-hidden="true"
                                        className="absolute -start-[21px] top-1 size-2.5 rounded-full border-2 border-card bg-primary"
                                    />
                                    <div className="flex flex-wrap items-center gap-2">
                                        <AdminBadge
                                            icon={Icon}
                                            variant={getStatusVariant(
                                                entry.status,
                                            )}
                                        >
                                            {statusLabel}
                                        </AdminBadge>
                                        <span className="text-muted-foreground tabular-nums">
                                            <bdi>
                                                {dateFormatter.format(
                                                    new Date(entry.createdAt),
                                                )}
                                            </bdi>
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2 text-muted-foreground">
                                        {entry.actor ? (
                                            <span>
                                                {entry.actor.name} (
                                                {entry.actor.role})
                                            </span>
                                        ) : null}
                                        {entry.source ? (
                                            <span className="rounded-sm bg-secondary px-1 py-0.5 text-[10px] text-secondary-foreground">
                                                {entry.source}
                                            </span>
                                        ) : null}
                                        {entry.previousStatus &&
                                        entry.newStatus ? (
                                            <span>
                                                {statuses[
                                                    entry.previousStatus
                                                ] ?? entry.previousStatus}{' '}
                                                &rarr;{' '}
                                                {statuses[entry.newStatus] ??
                                                    entry.newStatus}
                                            </span>
                                        ) : null}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                )}
            </section>

            {order.auditContext !== null ? (
                <section
                    aria-labelledby="audit-section-heading"
                    className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 text-card-foreground shadow-xs"
                >
                    <div className="flex items-center gap-2 border-b border-border/60 pb-3">
                        <ShieldAlert
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                        <h3
                            className="text-base font-semibold text-foreground"
                            id="audit-section-heading"
                        >
                            {copy.auditSection}
                        </h3>
                    </div>

                    {order.auditContext.length === 0 ? (
                        <p className="py-2 text-xs text-muted-foreground">
                            {copy.noAudit}
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-3 text-xs">
                            {order.auditContext.map((log) => (
                                <li
                                    className="flex flex-col gap-1 rounded-sm border border-border/40 bg-muted/20 p-2.5"
                                    key={log.id}
                                >
                                    <div className="flex items-center justify-between gap-2 font-mono text-[11px] font-semibold text-foreground">
                                        <span>{log.action}</span>
                                        <span className="font-sans text-xs font-normal text-muted-foreground tabular-nums">
                                            <bdi>
                                                {dateFormatter.format(
                                                    new Date(log.createdAt),
                                                )}
                                            </bdi>
                                        </span>
                                    </div>
                                    {log.actor ? (
                                        <span className="text-muted-foreground">
                                            {log.actor.name} ({log.actor.role})
                                        </span>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            ) : null}
        </div>
    );
}
