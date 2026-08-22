'use no memo'; // TanStack Table exposes mutable row objects.

import { Link, usePage } from '@inertiajs/react';
import type { Row } from '@tanstack/react-table';

import AdminBadge from '@/components/admin/admin-badge';
import { formatAdminMoney } from '@/components/admin/admin-money';
import {
    getStatusVariant,
    statusIcons,
} from '@/components/admin/admin-order-status';
import { Checkbox } from '@/components/ui/checkbox';
import type { AdminOrderRow, AdminTranslations } from '@/types/admin';

export type AdminOrdersMobileCardProps = {
    adminUi: AdminTranslations;
    dateFormatter: Intl.DateTimeFormat;
    locale: 'ar' | 'en';
    row: Row<AdminOrderRow>;
};

export default function AdminOrdersMobileCard({
    adminUi,
    dateFormatter,
    locale,
    row,
}: AdminOrdersMobileCardProps) {
    const { url } = usePage();
    const isLocalized = url.startsWith('/en/admin');
    const basePath = isLocalized ? '/en/admin/orders' : '/admin/orders';
    const copy = adminUi.orders;
    const order = row.original;
    const detailUrl = `${basePath}/${order.id}`;
    const paymentStatus = order.latestPaymentStatus;

    return (
        <article
            className="flex flex-col gap-2 rounded-lg border border-border bg-card p-3 text-card-foreground transition-colors data-[selected=true]:border-primary/50 data-[selected=true]:bg-muted/40 motion-reduce:transition-none"
            data-selected={row.getIsSelected() ? 'true' : undefined}
            role="listitem"
        >
            <div className="flex items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2">
                    <label
                        className="-ms-2 flex min-h-11 min-w-11 cursor-pointer items-center justify-center"
                        htmlFor={`select-mobile-order-${order.id}`}
                    >
                        <Checkbox
                            aria-label={`${copy.selectRow} ${order.orderNumber}`}
                            checked={row.getIsSelected()}
                            id={`select-mobile-order-${order.id}`}
                            onCheckedChange={(checked) =>
                                row.toggleSelected(Boolean(checked))
                            }
                        />
                    </label>
                    <Link
                        className="text-sm font-bold whitespace-nowrap text-foreground tabular-nums underline decoration-border underline-offset-4 transition-colors hover:text-primary hover:decoration-primary focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                        href={detailUrl}
                    >
                        <bdi>{order.orderNumber}</bdi>
                    </Link>
                </div>
                <AdminBadge
                    className="shrink-0"
                    icon={statusIcons[order.status]}
                    variant={getStatusVariant(order.status)}
                >
                    {adminUi.statuses[order.status] ?? order.status}
                </AdminBadge>
            </div>

            <div className="flex items-center justify-between gap-2 ps-9">
                <span className="truncate text-sm font-semibold text-foreground">
                    {order.customer.name || '—'}
                </span>
                <strong className="shrink-0 text-sm font-bold text-foreground tabular-nums">
                    <bdi>{formatAdminMoney(order.total, locale)}</bdi>
                </strong>
            </div>

            <div className="flex flex-wrap items-center gap-1.5 ps-9 text-xs">
                {order.serviceTypes.map((service) => (
                    <span
                        className="rounded-sm bg-secondary px-1.5 py-0.5 text-[11px] text-secondary-foreground"
                        key={service}
                    >
                        {copy.services[service] ?? service}
                    </span>
                ))}
                {order.platforms.map((platform) => (
                    <span
                        className="rounded-sm border border-border px-1.5 py-0.5 text-[11px] text-muted-foreground"
                        key={platform}
                    >
                        {copy.platforms[platform] ?? platform}
                    </span>
                ))}
                {paymentStatus ? (
                    <AdminBadge
                        className="shrink-0 text-[11px]"
                        icon={statusIcons[paymentStatus]}
                        variant={getStatusVariant(paymentStatus)}
                    >
                        {adminUi.statuses[paymentStatus] ?? paymentStatus}
                    </AdminBadge>
                ) : null}
                <span className="ms-auto text-[11px] text-muted-foreground tabular-nums">
                    <bdi>
                        {order.placedAt
                            ? dateFormatter.format(new Date(order.placedAt))
                            : '—'}
                    </bdi>
                </span>
            </div>
        </article>
    );
}
