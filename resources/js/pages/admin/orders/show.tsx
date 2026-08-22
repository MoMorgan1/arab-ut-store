import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CreditCard,
    Package,
    Receipt,
    User as UserIcon,
} from 'lucide-react';
import React, { useState } from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import { formatAdminMoney } from '@/components/admin/admin-money';
import {
    getStatusVariant,
    statusIcons,
} from '@/components/admin/admin-order-status';
import AdminOrderHistory from '@/components/admin/orders/admin-order-history';
import AdminOrderTransitionControls from '@/components/admin/orders/admin-order-transition-controls';
import type {
    AdminOrderDetail,
    AdminOrderDetailPageProps,
} from '@/types/admin';

export default function AdminOrderDetailPage() {
    const { props, url } = usePage<AdminOrderDetailPageProps>();
    const [order, setOrder] = useState<AdminOrderDetail>(props.order);
    const copy = props.adminUi.orderDetail;
    const ordersCopy = props.adminUi.orders;
    const statuses = props.adminUi.statuses;

    const pathname = new URL(url, window.location.origin).pathname;
    const isLocalized = pathname.startsWith('/en/admin');
    const ordersListUrl = isLocalized ? '/en/admin/orders' : '/admin/orders';

    const dateFormatter = new Intl.DateTimeFormat(props.locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    const statusBadgeIcon = statusIcons[order.status];
    const statusBadgeVariant = getStatusVariant(order.status);

    return (
        <article className="space-y-6" dir={props.direction}>
            <Head
                title={copy.headTitle.replace(':number', order.orderNumber)}
            />

            <header className="flex flex-col gap-4 border-b border-border pb-5">
                <div>
                    <Link
                        className="inline-flex min-h-11 items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                        href={ordersListUrl}
                    >
                        <ArrowLeft aria-hidden="true" className="size-3.5" />
                        <span>{copy.backToOrders}</span>
                    </Link>
                </div>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-1">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-xl font-bold tracking-tight text-foreground tabular-nums md:text-2xl">
                                <bdi>
                                    {copy.title.replace(
                                        ':number',
                                        order.orderNumber,
                                    )}
                                </bdi>
                            </h1>
                            <AdminBadge
                                icon={statusBadgeIcon}
                                variant={statusBadgeVariant}
                            >
                                {statuses[order.status] ?? order.status}
                            </AdminBadge>
                        </div>
                        <p className="text-xs [overflow-wrap:anywhere] text-muted-foreground">
                            <bdi>{order.id}</bdi>
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
                        {order.placedAt ? (
                            <div className="flex flex-col">
                                <span className="font-medium text-foreground">
                                    {copy.placedAt}
                                </span>
                                <span className="tabular-nums">
                                    <bdi>
                                        {dateFormatter.format(
                                            new Date(order.placedAt),
                                        )}
                                    </bdi>
                                </span>
                            </div>
                        ) : null}
                        {order.paidAt ? (
                            <div className="flex flex-col">
                                <span className="font-medium text-foreground">
                                    {copy.paidAt}
                                </span>
                                <span className="tabular-nums">
                                    <bdi>
                                        {dateFormatter.format(
                                            new Date(order.paidAt),
                                        )}
                                    </bdi>
                                </span>
                            </div>
                        ) : null}
                        {order.completedAt ? (
                            <div className="flex flex-col">
                                <span className="font-medium text-foreground">
                                    {copy.completedAt}
                                </span>
                                <span className="tabular-nums">
                                    <bdi>
                                        {dateFormatter.format(
                                            new Date(order.completedAt),
                                        )}
                                    </bdi>
                                </span>
                            </div>
                        ) : null}
                        {order.cancelledAt ? (
                            <div className="flex flex-col">
                                <span className="font-medium text-foreground">
                                    {copy.cancelledAt}
                                </span>
                                <span className="tabular-nums">
                                    <bdi>
                                        {dateFormatter.format(
                                            new Date(order.cancelledAt),
                                        )}
                                    </bdi>
                                </span>
                            </div>
                        ) : null}
                    </div>
                </div>
            </header>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="flex flex-col gap-6 lg:col-span-2">
                    <section
                        aria-labelledby="customer-info-heading"
                        className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 text-card-foreground shadow-xs"
                    >
                        <div className="flex items-center gap-2 border-b border-border/60 pb-3">
                            <UserIcon
                                aria-hidden="true"
                                className="size-4 text-muted-foreground"
                            />
                            <h2
                                className="text-base font-semibold text-foreground"
                                id="customer-info-heading"
                            >
                                {copy.customerSection}
                            </h2>
                        </div>
                        <div className="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2">
                            <div className="flex flex-col gap-0.5">
                                <span className="text-muted-foreground">
                                    {copy.customerName}
                                </span>
                                <span className="font-semibold text-foreground">
                                    {order.customer.name || '—'}
                                </span>
                            </div>
                            <div className="flex flex-col gap-0.5">
                                <span className="text-muted-foreground">
                                    {copy.customerEmail}
                                </span>
                                <span className="font-semibold [overflow-wrap:anywhere] text-foreground">
                                    {order.customer.email}
                                </span>
                            </div>
                            <div className="flex flex-col gap-0.5">
                                <span className="text-muted-foreground">
                                    {copy.customerPhone}
                                </span>
                                <span className="font-semibold text-foreground tabular-nums">
                                    <bdi>{order.customer.phone || '—'}</bdi>
                                </span>
                            </div>
                            <div className="flex flex-col gap-0.5">
                                <span className="text-muted-foreground">
                                    ID
                                </span>
                                <span className="[overflow-wrap:anywhere] text-muted-foreground tabular-nums">
                                    <bdi>{order.customer.id}</bdi>
                                </span>
                            </div>
                        </div>
                    </section>

                    <section
                        aria-labelledby="order-items-heading"
                        className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 text-card-foreground shadow-xs"
                    >
                        <div className="flex items-center justify-between border-b border-border/60 pb-3">
                            <div className="flex items-center gap-2">
                                <Package
                                    aria-hidden="true"
                                    className="size-4 text-muted-foreground"
                                />
                                <h2
                                    className="text-base font-semibold text-foreground"
                                    id="order-items-heading"
                                >
                                    {copy.itemsSection}
                                </h2>
                            </div>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {order.items.length} {ordersCopy.items}
                            </span>
                        </div>

                        {order.items.length === 0 ? (
                            <p className="py-2 text-xs text-muted-foreground">
                                {copy.noItems}
                            </p>
                        ) : (
                            <div className="flex flex-col divide-y divide-border/60">
                                {order.items.map((item) => (
                                    <div
                                        className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
                                        key={item.id}
                                    >
                                        <div className="flex flex-col gap-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-semibold text-foreground">
                                                    {item.name}
                                                </span>
                                                <AdminBadge
                                                    icon={
                                                        statusIcons[item.status]
                                                    }
                                                    variant={getStatusVariant(
                                                        item.status,
                                                    )}
                                                >
                                                    {statuses[item.status] ??
                                                        item.status}
                                                </AdminBadge>
                                            </div>
                                            <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                                <span className="rounded-sm bg-secondary px-1.5 py-0.5 text-secondary-foreground">
                                                    {ordersCopy.services[
                                                        item.serviceType
                                                    ] ?? item.serviceType}
                                                </span>
                                                <span className="rounded-sm border border-border px-1.5 py-0.5">
                                                    {ordersCopy.platforms[
                                                        item.platform
                                                    ] ?? item.platform}
                                                </span>
                                                <span>
                                                    &times; {item.quantity}
                                                </span>
                                            </div>
                                        </div>

                                        <div className="flex flex-col text-start text-xs sm:text-end">
                                            <span className="font-bold text-foreground tabular-nums">
                                                <bdi>
                                                    {formatAdminMoney(
                                                        item.total,
                                                        props.locale,
                                                    )}
                                                </bdi>
                                            </span>
                                            <span className="text-muted-foreground tabular-nums">
                                                <bdi>
                                                    {formatAdminMoney(
                                                        item.unitPrice,
                                                        props.locale,
                                                    )}{' '}
                                                    / {copy.unitPrice}
                                                </bdi>
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>

                    <section
                        aria-labelledby="payment-breakdown-heading"
                        className="flex flex-col gap-4 rounded-lg border border-border bg-card p-4 text-card-foreground shadow-xs"
                    >
                        <div className="flex items-center gap-2 border-b border-border/60 pb-3">
                            <Receipt
                                aria-hidden="true"
                                className="size-4 text-muted-foreground"
                            />
                            <h2
                                className="text-base font-semibold text-foreground"
                                id="payment-breakdown-heading"
                            >
                                {copy.paymentSection}
                            </h2>
                        </div>

                        <div className="flex flex-col gap-2 text-xs">
                            <div className="flex items-center justify-between text-muted-foreground">
                                <span>{copy.subtotal}</span>
                                <span className="tabular-nums">
                                    <bdi>
                                        {formatAdminMoney(
                                            order.money.subtotal,
                                            props.locale,
                                        )}
                                    </bdi>
                                </span>
                            </div>
                            {order.money.discount.amountMinor !== '0' ? (
                                <div className="flex items-center justify-between text-emerald-500">
                                    <span>{copy.discount}</span>
                                    <span className="tabular-nums">
                                        <bdi>
                                            -
                                            {formatAdminMoney(
                                                order.money.discount,
                                                props.locale,
                                            )}
                                        </bdi>
                                    </span>
                                </div>
                            ) : null}
                            {order.money.wallet.amountMinor !== '0' ? (
                                <div className="flex items-center justify-between text-muted-foreground">
                                    <span>{copy.wallet}</span>
                                    <span className="tabular-nums">
                                        <bdi>
                                            {formatAdminMoney(
                                                order.money.wallet,
                                                props.locale,
                                            )}
                                        </bdi>
                                    </span>
                                </div>
                            ) : null}
                            {order.money.payment.amountMinor !== '0' ? (
                                <div className="flex items-center justify-between text-muted-foreground">
                                    <span>{copy.payment}</span>
                                    <span className="tabular-nums">
                                        <bdi>
                                            {formatAdminMoney(
                                                order.money.payment,
                                                props.locale,
                                            )}
                                        </bdi>
                                    </span>
                                </div>
                            ) : null}
                            <div className="flex items-center justify-between border-t border-border pt-2 text-sm font-bold text-foreground">
                                <span>{copy.total}</span>
                                <span className="tabular-nums">
                                    <bdi>
                                        {formatAdminMoney(
                                            order.money.total,
                                            props.locale,
                                        )}
                                    </bdi>
                                </span>
                            </div>
                        </div>

                        {order.payments.length > 0 ? (
                            <div className="border-t border-border/60 pt-3">
                                <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold text-foreground">
                                    <CreditCard
                                        aria-hidden="true"
                                        className="size-3.5 text-muted-foreground"
                                    />
                                    <span>{ordersCopy.payment}</span>
                                </div>
                                <div className="flex flex-col gap-2 text-xs">
                                    {order.payments.map((p) => (
                                        <div
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-border/40 bg-muted/20 p-2"
                                            key={p.id}
                                        >
                                            <div className="flex items-center gap-2">
                                                <AdminBadge
                                                    icon={statusIcons[p.status]}
                                                    variant={getStatusVariant(
                                                        p.status,
                                                    )}
                                                >
                                                    {statuses[p.status] ??
                                                        p.status}
                                                </AdminBadge>
                                                <span className="font-semibold text-foreground tabular-nums">
                                                    <bdi>
                                                        {formatAdminMoney(
                                                            p.amount,
                                                            props.locale,
                                                        )}
                                                    </bdi>
                                                </span>
                                            </div>
                                            <span className="text-muted-foreground tabular-nums">
                                                <bdi>
                                                    {dateFormatter.format(
                                                        new Date(
                                                            p.paidAt ??
                                                                p.createdAt,
                                                        ),
                                                    )}
                                                </bdi>
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ) : null}
                    </section>
                </div>

                <div className="flex flex-col gap-6 lg:col-span-1">
                    <AdminOrderTransitionControls
                        adminUi={props.adminUi}
                        allowedTransitions={props.allowedTransitions}
                        onStatusUpdated={(freshOrder) => setOrder(freshOrder)}
                        order={order}
                        permissions={props.permissions}
                        transitionUrl={props.transitionUrl}
                    />

                    <AdminOrderHistory
                        adminUi={props.adminUi}
                        locale={props.locale}
                        order={order}
                    />
                </div>
            </div>
        </article>
    );
}
