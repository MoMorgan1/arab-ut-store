import { CircleAlert, Package } from 'lucide-react';

import AdminBadge from '@/components/admin/admin-badge';
import { formatAdminMoney } from '@/components/admin/admin-money';
import {
    getStatusVariant,
    statusIcons,
} from '@/components/admin/admin-order-status';
import type { AdminOverviewPageProps, AdminTranslations } from '@/types/admin';

export default function AdminRecentOrders({
    dateFormatter,
    locale,
    orders,
    statuses,
    translations,
}: {
    dateFormatter: Intl.DateTimeFormat;
    locale: 'ar' | 'en';
    orders: AdminOverviewPageProps['overview']['recentOrders'];
    statuses: AdminOverviewPageProps['adminUi']['statuses'];
    translations: AdminTranslations['overview'];
}) {
    return (
        <section
            aria-label={translations.recentOrdersTitle}
            className="flex flex-col rounded-xl border border-border bg-card p-4 md:p-6"
        >
            <header className="flex flex-col gap-1 pb-4">
                <div className="flex items-center gap-2">
                    <Package
                        aria-hidden="true"
                        className="h-4 w-4 shrink-0 text-muted-foreground"
                    />
                    <h2 className="text-base font-semibold text-card-foreground">
                        {translations.recentOrdersTitle}
                    </h2>
                </div>
                <p className="text-xs text-muted-foreground">
                    {translations.recentOrdersDescription}
                </p>
            </header>

            {orders.length === 0 ? (
                <div className="flex min-h-36 flex-col items-center justify-center gap-2 text-center text-muted-foreground">
                    <Package
                        aria-hidden="true"
                        className="h-8 w-8 text-muted-foreground/50"
                    />
                    <p className="text-sm font-medium">
                        {translations.noRecentOrders}
                    </p>
                </div>
            ) : (
                <>
                    <div className="hidden overflow-x-auto md:block">
                        <table className="w-full text-sm">
                            <thead className="border-b border-border/80 text-xs text-muted-foreground">
                                <tr>
                                    <th
                                        className="pe-4 pb-3 text-start font-medium"
                                        scope="col"
                                    >
                                        {translations.orderNumber}
                                    </th>
                                    <th
                                        className="pe-4 pb-3 text-start font-medium"
                                        scope="col"
                                    >
                                        {translations.status}
                                    </th>
                                    <th
                                        className="pe-4 pb-3 text-start font-medium"
                                        scope="col"
                                    >
                                        {translations.orderPlacedAt}
                                    </th>
                                    <th
                                        className="pb-3 text-end font-medium"
                                        scope="col"
                                    >
                                        {translations.orderTotal}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border/60">
                                {orders.map((order) => {
                                    const StatusIcon =
                                        statusIcons[order.status] ??
                                        CircleAlert;
                                    const variant = getStatusVariant(
                                        order.status,
                                    );
                                    const statusLabel =
                                        statuses[order.status] ?? order.status;

                                    return (
                                        <tr key={order.id} className="group">
                                            <td className="py-3.5 pe-4 text-start font-bold text-foreground tabular-nums">
                                                <bdi dir="ltr">
                                                    {order.number}
                                                </bdi>
                                            </td>
                                            <td className="py-3.5 pe-4 text-start">
                                                <AdminBadge
                                                    className="shrink-0"
                                                    icon={StatusIcon}
                                                    variant={variant}
                                                >
                                                    {statusLabel}
                                                </AdminBadge>
                                            </td>
                                            <td className="py-3.5 pe-4 text-start">
                                                <bdi dir="ltr">
                                                    <time
                                                        className="text-xs text-muted-foreground tabular-nums"
                                                        dateTime={
                                                            order.placedAt
                                                        }
                                                    >
                                                        {dateFormatter.format(
                                                            new Date(
                                                                order.placedAt,
                                                            ),
                                                        )}
                                                    </time>
                                                </bdi>
                                            </td>
                                            <td className="py-3.5 text-end font-bold text-foreground tabular-nums">
                                                <bdi dir="ltr">
                                                    {formatAdminMoney(
                                                        order.total,
                                                        locale,
                                                    )}
                                                </bdi>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex flex-col divide-y divide-border/60 md:hidden">
                        {orders.map((order) => {
                            const StatusIcon =
                                statusIcons[order.status] ?? CircleAlert;
                            const variant = getStatusVariant(order.status);
                            const statusLabel =
                                statuses[order.status] ?? order.status;

                            return (
                                <div
                                    className="flex flex-col gap-2 py-3"
                                    key={order.id}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="min-w-0 font-bold break-all text-foreground tabular-nums">
                                            <bdi dir="ltr">{order.number}</bdi>
                                        </span>
                                        <AdminBadge
                                            className="shrink-0"
                                            icon={StatusIcon}
                                            variant={variant}
                                        >
                                            {statusLabel}
                                        </AdminBadge>
                                    </div>
                                    <div className="flex items-center justify-between text-xs">
                                        <bdi dir="ltr">
                                            <time
                                                className="text-muted-foreground tabular-nums"
                                                dateTime={order.placedAt}
                                            >
                                                {dateFormatter.format(
                                                    new Date(order.placedAt),
                                                )}
                                            </time>
                                        </bdi>
                                        <span className="font-bold text-foreground tabular-nums">
                                            <bdi dir="ltr">
                                                {formatAdminMoney(
                                                    order.total,
                                                    locale,
                                                )}
                                            </bdi>
                                        </span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </>
            )}
        </section>
    );
}
