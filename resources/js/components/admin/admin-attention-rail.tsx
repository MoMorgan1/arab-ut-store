import {
    Activity,
    AlertOctagon,
    CircleAlert,
    CircleCheck,
    Clock3,
    CreditCard,
    Hourglass,
    ListChecks,
    RotateCcw,
} from 'lucide-react';

import AdminBadge from '@/components/admin/admin-badge';
import {
    getStatusVariant,
    statusIcons,
} from '@/components/admin/admin-order-status';
import { cn } from '@/lib/utils';
import type { AdminOverviewPageProps, AdminTranslations } from '@/types/admin';

export default function AdminAttentionRail({
    dateFormatter,
    locale,
    overview,
    statuses,
    translations,
}: {
    dateFormatter: Intl.DateTimeFormat;
    locale: 'ar' | 'en';
    overview: AdminOverviewPageProps['overview'];
    statuses: AdminOverviewPageProps['adminUi']['statuses'];
    translations: AdminTranslations['overview'];
}) {
    const numberFormatter = new Intl.NumberFormat(locale);

    return (
        <aside
            aria-label={translations.attentionRailTitle}
            className="flex flex-col gap-6 rounded-xl border border-border bg-card p-4 md:p-6"
        >
            <div className="flex flex-col gap-3">
                <header className="flex items-center gap-2">
                    <AlertOctagon
                        aria-hidden="true"
                        className="h-4 w-4 shrink-0 text-muted-foreground"
                    />
                    <h2 className="text-base font-semibold text-card-foreground">
                        {translations.attentionRailTitle}
                    </h2>
                </header>

                <dl className="divide-y divide-border/60 text-xs">
                    <div className="flex items-center justify-between py-2">
                        <dt className="flex items-center gap-1.5 font-medium text-muted-foreground">
                            <CreditCard
                                aria-hidden="true"
                                className={cn(
                                    'h-3.5 w-3.5 shrink-0',
                                    overview.payments.failed > 0 &&
                                        'text-status-danger',
                                )}
                            />
                            <span
                                className={cn(
                                    overview.payments.failed > 0 &&
                                        'font-semibold text-status-danger',
                                )}
                            >
                                {translations.failedPayments}
                            </span>
                        </dt>
                        <dd
                            className={cn(
                                'font-bold tabular-nums',
                                overview.payments.failed > 0
                                    ? 'text-status-danger'
                                    : 'text-foreground',
                            )}
                        >
                            {numberFormatter.format(overview.payments.failed)}
                        </dd>
                    </div>

                    <div className="flex items-center justify-between py-2">
                        <dt className="flex items-center gap-1.5 font-medium text-muted-foreground">
                            <RotateCcw
                                aria-hidden="true"
                                className={cn(
                                    'h-3.5 w-3.5 shrink-0',
                                    overview.refunds.failed > 0 &&
                                        'text-status-danger',
                                )}
                            />
                            <span
                                className={cn(
                                    overview.refunds.failed > 0 &&
                                        'font-semibold text-status-danger',
                                )}
                            >
                                {translations.failedRefunds}
                            </span>
                        </dt>
                        <dd
                            className={cn(
                                'font-bold tabular-nums',
                                overview.refunds.failed > 0
                                    ? 'text-status-danger'
                                    : 'text-foreground',
                            )}
                        >
                            {numberFormatter.format(overview.refunds.failed)}
                        </dd>
                    </div>

                    <div className="flex items-center justify-between py-2">
                        <dt className="flex items-center gap-1.5 font-medium text-muted-foreground">
                            <Hourglass
                                aria-hidden="true"
                                className={cn(
                                    'h-3.5 w-3.5 shrink-0',
                                    overview.orders.waitingForCustomer > 0 &&
                                        'text-status-warning',
                                )}
                            />
                            <span
                                className={cn(
                                    overview.orders.waitingForCustomer > 0 &&
                                        'font-semibold text-status-warning',
                                )}
                            >
                                {translations.waitingForCustomer}
                            </span>
                        </dt>
                        <dd
                            className={cn(
                                'font-bold tabular-nums',
                                overview.orders.waitingForCustomer > 0
                                    ? 'text-status-warning'
                                    : 'text-foreground',
                            )}
                        >
                            {numberFormatter.format(
                                overview.orders.waitingForCustomer,
                            )}
                        </dd>
                    </div>

                    <div className="flex items-center justify-between py-2">
                        <dt className="flex items-center gap-1.5 font-medium text-muted-foreground">
                            <Clock3
                                aria-hidden="true"
                                className="h-3.5 w-3.5 shrink-0"
                            />
                            <span>{translations.pendingPayments}</span>
                        </dt>
                        <dd className="font-bold text-foreground tabular-nums">
                            {numberFormatter.format(overview.payments.pending)}
                        </dd>
                    </div>

                    <div className="flex items-center justify-between py-2">
                        <dt className="flex items-center gap-1.5 font-medium text-muted-foreground">
                            <ListChecks
                                aria-hidden="true"
                                className="h-3.5 w-3.5 shrink-0"
                            />
                            <span>{translations.receivedOrders}</span>
                        </dt>
                        <dd className="font-bold text-foreground tabular-nums">
                            {numberFormatter.format(overview.orders.received)}
                        </dd>
                    </div>

                    <div className="flex items-center justify-between py-2">
                        <dt className="flex items-center gap-1.5 font-medium text-muted-foreground">
                            <Clock3
                                aria-hidden="true"
                                className="h-3.5 w-3.5 shrink-0"
                            />
                            <span>{translations.inProgressOrders}</span>
                        </dt>
                        <dd className="font-bold text-foreground tabular-nums">
                            {numberFormatter.format(overview.orders.inProgress)}
                        </dd>
                    </div>
                </dl>
            </div>

            <div className="flex flex-col gap-2.5 border-t border-border/60 pt-4">
                <div className="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground">
                    <Clock3
                        aria-hidden="true"
                        className="h-3.5 w-3.5 shrink-0"
                    />
                    <h3>{translations.oldestUnresolved}</h3>
                </div>

                {overview.oldestUnresolvedOrder === null ? (
                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                        <CircleCheck
                            aria-hidden="true"
                            className="h-3.5 w-3.5 shrink-0 text-status-success"
                        />
                        <span>{translations.noUnresolved}</span>
                    </p>
                ) : (
                    <div className="flex flex-col gap-1.5 rounded-lg border border-border/60 bg-background/40 p-3">
                        <div className="flex items-center justify-between gap-2">
                            <span
                                className="font-bold text-foreground tabular-nums"
                                translate="no"
                            >
                                <bdi dir="ltr">
                                    {overview.oldestUnresolvedOrder.number}
                                </bdi>
                            </span>
                            <AdminBadge
                                icon={
                                    statusIcons[
                                        overview.oldestUnresolvedOrder.status
                                    ] ?? CircleAlert
                                }
                                variant={getStatusVariant(
                                    overview.oldestUnresolvedOrder.status,
                                )}
                            >
                                {statuses[
                                    overview.oldestUnresolvedOrder.status
                                ] ?? overview.oldestUnresolvedOrder.status}
                            </AdminBadge>
                        </div>
                        <time
                            className="text-[11px] text-muted-foreground tabular-nums"
                            dateTime={overview.oldestUnresolvedOrder.placedAt}
                        >
                            {dateFormatter.format(
                                new Date(
                                    overview.oldestUnresolvedOrder.placedAt,
                                ),
                            )}
                        </time>
                    </div>
                )}
            </div>

            {overview.recentAuditEvents !== null ? (
                <div className="flex flex-col gap-2.5 border-t border-border/60 pt-4">
                    <div className="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground">
                        <Activity
                            aria-hidden="true"
                            className="h-3.5 w-3.5 shrink-0"
                        />
                        <h3>{translations.recentAudit}</h3>
                    </div>

                    {overview.recentAuditEvents.length === 0 ? (
                        <p className="flex items-center gap-2 text-xs text-muted-foreground">
                            <Activity
                                aria-hidden="true"
                                className="h-3.5 w-3.5 shrink-0 text-muted-foreground/50"
                            />
                            <span>{translations.noAudit}</span>
                        </p>
                    ) : (
                        <ol className="divide-y divide-border/60 text-xs">
                            {overview.recentAuditEvents.map((event) => (
                                <li
                                    key={event.id}
                                    className="flex flex-col gap-0.5 py-2 first:pt-0 last:pb-0"
                                >
                                    <span
                                        className="font-medium text-foreground"
                                        translate="no"
                                    >
                                        {event.action}
                                    </span>
                                    <time
                                        className="text-[11px] text-muted-foreground tabular-nums"
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
                </div>
            ) : null}
        </aside>
    );
}
