import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CircleAlert,
    CircleCheck,
    Clock3,
} from 'lucide-react';

import AdminBadge from '@/components/admin/admin-badge';
import {
    getStatusVariant,
    statusIcons,
} from '@/components/admin/admin-order-status';
import { cn } from '@/lib/utils';
import type { AdminOverviewPageProps, AdminTranslations } from '@/types/admin';

export default function AdminAttentionStrip({
    dateFormatter,
    locale,
    ordersUrl,
    overview,
    statuses,
    translations,
}: {
    dateFormatter: Intl.DateTimeFormat;
    locale: 'ar' | 'en';
    ordersUrl: string;
    overview: AdminOverviewPageProps['overview'];
    statuses: AdminOverviewPageProps['adminUi']['statuses'];
    translations: AdminTranslations['overview'];
}) {
    const numberFormatter = new Intl.NumberFormat(locale);
    const hasUrgent =
        overview.payments.failed > 0 || overview.refunds.failed > 0;
    const hasAttention = overview.attentionCount > 0;
    const oldestOrder = overview.oldestUnresolvedOrder;

    const filteredOrdersUrl = oldestOrder
        ? `${ordersUrl}?status=${encodeURIComponent(oldestOrder.status)}`
        : ordersUrl;

    const attentionLabel =
        translations.attentionStripTitle ??
        translations.needsAttention ??
        'Needs attention';

    return (
        <aside
            aria-label={attentionLabel}
            className={cn(
                'flex flex-col gap-3 rounded-xl border p-3.5 sm:flex-row sm:items-center sm:justify-between sm:p-4',
                hasUrgent
                    ? 'border-status-danger/40 bg-status-danger/5'
                    : hasAttention
                      ? 'border-status-warning/40 bg-status-warning/5'
                      : 'border-border bg-card',
            )}
        >
            <div className="flex flex-wrap items-center gap-2.5 sm:gap-3">
                <div className="flex items-center gap-2">
                    {hasUrgent ? (
                        <AlertTriangle
                            aria-hidden="true"
                            className="h-4 w-4 shrink-0 text-status-danger"
                        />
                    ) : hasAttention ? (
                        <AlertTriangle
                            aria-hidden="true"
                            className="h-4 w-4 shrink-0 text-status-warning"
                        />
                    ) : (
                        <CircleCheck
                            aria-hidden="true"
                            className="h-4 w-4 shrink-0 text-status-success"
                        />
                    )}
                    <span
                        className={cn(
                            'text-sm font-semibold',
                            hasUrgent
                                ? 'text-status-danger'
                                : hasAttention
                                  ? 'text-foreground'
                                  : 'text-foreground',
                        )}
                    >
                        {attentionLabel}
                    </span>
                    <span
                        className={cn(
                            'inline-flex min-h-[22px] min-w-[22px] items-center justify-center rounded-full px-2 text-xs font-bold tabular-nums',
                            hasUrgent
                                ? 'bg-status-danger/15 text-status-danger'
                                : hasAttention
                                  ? 'bg-status-warning/15 text-status-warning'
                                  : 'bg-muted text-muted-foreground',
                        )}
                    >
                        {numberFormatter.format(overview.attentionCount)}
                    </span>
                </div>

                {oldestOrder !== null ? (
                    <div className="flex flex-wrap items-center gap-2 border-border/60 text-xs sm:border-s sm:ps-3">
                        <span className="text-muted-foreground">
                            {translations.oldestUnresolved}:
                        </span>
                        <span
                            className="font-bold text-foreground tabular-nums"
                            translate="no"
                        >
                            <bdi dir="ltr">{oldestOrder.number}</bdi>
                        </span>
                        <AdminBadge
                            icon={
                                statusIcons[oldestOrder.status] ?? CircleAlert
                            }
                            variant={getStatusVariant(oldestOrder.status)}
                        >
                            {statuses[oldestOrder.status] ?? oldestOrder.status}
                        </AdminBadge>
                        <time
                            className="flex items-center gap-1 text-[11px] text-muted-foreground tabular-nums"
                            dateTime={oldestOrder.placedAt}
                        >
                            <Clock3
                                aria-hidden="true"
                                className="h-3 w-3 shrink-0"
                            />
                            <span>
                                {dateFormatter.format(
                                    new Date(oldestOrder.placedAt),
                                )}
                            </span>
                        </time>
                    </div>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        {translations.noUnresolved}
                    </p>
                )}
            </div>

            {oldestOrder !== null ? (
                <div className="flex shrink-0 items-center sm:self-center">
                    <Link
                        className="inline-flex min-h-[44px] items-center gap-1.5 rounded-lg border border-border/80 bg-card px-3 py-2 text-xs font-semibold text-foreground shadow-sm hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
                        href={filteredOrdersUrl}
                    >
                        <span>{translations.viewUnresolvedOrders}</span>
                        <ArrowRight
                            aria-hidden="true"
                            className="h-3.5 w-3.5 rtl:rotate-180"
                        />
                    </Link>
                </div>
            ) : null}
        </aside>
    );
}
