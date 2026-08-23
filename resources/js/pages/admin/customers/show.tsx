import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    CheckCircle2,
    History,
    Pencil,
    Shield,
    ShoppingBag,
    User as UserIcon,
    Wallet,
    XCircle,
} from 'lucide-react';
import React, { useState } from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import { formatAdminMoney } from '@/components/admin/admin-money';
import {
    getStatusVariant,
    statusIcons,
} from '@/components/admin/admin-order-status';
import AdminCustomerContactDialog from '@/components/admin/customers/admin-customer-contact-dialog';
import AdminCustomerStatusDialog from '@/components/admin/customers/admin-customer-status-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import type {
    AdminCustomerDetail,
    AdminCustomerDetailPageProps,
} from '@/types/admin';

export default function AdminCustomerDetailPage() {
    const { props, url } = usePage<AdminCustomerDetailPageProps>();
    const [customer, setCustomer] = useState<AdminCustomerDetail>(
        props.customer,
    );
    const [syncedCustomer, setSyncedCustomer] = useState(props.customer);
    const [statusDialogOpen, setStatusDialogOpen] = useState(false);
    const [contactDialogOpen, setContactDialogOpen] = useState(false);
    const [statusAction, setStatusAction] = useState<'suspend' | 'reactivate'>(
        customer.isActive ? 'suspend' : 'reactivate',
    );
    const [feedback, setFeedback] = useState<{
        type: 'success' | 'error' | 'conflict';
        title: string;
        message: string;
    } | null>(null);

    // Re-sync local working copy whenever Inertia delivers fresh props
    // (adjust-state-during-render pattern, never setState inside useEffect).
    if (props.customer !== syncedCustomer) {
        setSyncedCustomer(props.customer);
        setCustomer(props.customer);
    }

    const copy = props.adminUi.customerDetail;
    const customersCopy = props.adminUi.customers;
    const statuses = props.adminUi.statuses;

    const pathname = new URL(url, window.location.origin).pathname;
    const isLocalized = pathname.startsWith('/en/admin');
    const customersListUrl = isLocalized
        ? '/en/admin/customers'
        : '/admin/customers';
    const ordersBasePath = isLocalized ? '/en/admin/orders' : '/admin/orders';

    const dateFormatter = new Intl.DateTimeFormat(props.locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    const canUpdateStatus = props.permissions.includes(
        'customers.update_status',
    );
    const canUpdateContact = props.permissions.includes(
        'customers.update_contact',
    );

    const handleOpenStatusDialog = (action: 'suspend' | 'reactivate') => {
        setStatusAction(action);
        setStatusDialogOpen(true);
    };

    const handleStatusSuccess = (result: {
        isActive: boolean;
        updatedAt: string;
    }) => {
        setCustomer((prev) => ({
            ...prev,
            isActive: result.isActive,
            updatedAt: result.updatedAt,
        }));
        setFeedback({
            message: result.isActive
                ? copy.reactivatedMessage
                : copy.suspendedMessage,
            title: copy.statusUpdated,
            type: 'success',
        });
        router.reload({ only: ['customer'] });
    };

    const handleStatusConflict = (currentActive: boolean) => {
        const readableStatus = currentActive
            ? customersCopy.statusActive
            : customersCopy.statusSuspended;
        setFeedback({
            message: copy.conflictError.replace(':status', readableStatus),
            title: copy.conflictTitle,
            type: 'conflict',
        });
        router.reload({ only: ['customer'] });
    };

    const handleContactSuccess = (result: {
        email: string;
        firstName: string;
        lastName: string;
        phone: string | null;
        updatedAt: string;
    }) => {
        setCustomer((prev) => ({
            ...prev,
            email: result.email,
            firstName: result.firstName,
            lastName: result.lastName,
            name: `${result.firstName} ${result.lastName}`.trim(),
            phone: result.phone,
            updatedAt: result.updatedAt,
        }));
        setFeedback({
            message: copy.contactUpdatedMessage,
            title: copy.contactUpdated,
            type: 'success',
        });
        router.reload({ only: ['customer'] });
    };

    const handleContactConflict = () => {
        setFeedback({
            message: copy.contactConflictError,
            title: copy.conflictTitle,
            type: 'conflict',
        });
        router.reload({ only: ['customer'] });
    };

    return (
        <article className="space-y-6" dir={props.direction}>
            <Head title={copy.headTitle.replace(':name', customer.name)} />

            <header className="flex flex-col gap-4 border-b border-border pb-5">
                <div>
                    <Link
                        className="inline-flex min-h-11 items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                        href={customersListUrl}
                    >
                        <ArrowLeft aria-hidden="true" className="size-3.5" />
                        <span>{copy.backToCustomers}</span>
                    </Link>
                </div>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-1">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">
                                <bdi>{customer.name}</bdi>
                            </h1>
                            <AdminBadge
                                icon={
                                    customer.isActive ? CheckCircle2 : XCircle
                                }
                                variant={
                                    customer.isActive ? 'success' : 'danger'
                                }
                            >
                                {customer.isActive
                                    ? customersCopy.statusActive
                                    : customersCopy.statusSuspended}
                            </AdminBadge>
                        </div>
                        <p className="text-xs [overflow-wrap:anywhere] text-muted-foreground">
                            <bdi>{customer.id}</bdi>
                        </p>
                        <p className="text-xs text-muted-foreground md:hidden">
                            <span className="tabular-nums">
                                {copy.registeredAt}{' '}
                                <bdi>
                                    {customer.createdAt
                                        ? dateFormatter.format(
                                              new Date(customer.createdAt),
                                          )
                                        : '—'}
                                </bdi>
                            </span>
                            <span aria-hidden="true"> · </span>
                            <span className="tabular-nums">
                                {customer.ordersSummary.ordersCount}{' '}
                                {copy.ordersCount}
                            </span>
                            <span aria-hidden="true"> · </span>
                            <span className="tabular-nums">
                                <bdi>
                                    {formatAdminMoney(
                                        customer.ordersSummary.totalSpent,
                                        props.locale,
                                    )}
                                </bdi>
                            </span>
                        </p>
                    </div>

                    <div className="hidden flex-wrap gap-4 text-xs text-muted-foreground md:flex">
                        {customer.createdAt ? (
                            <div className="flex flex-col">
                                <span className="font-medium text-foreground">
                                    {copy.registeredAt}
                                </span>
                                <span className="tabular-nums">
                                    {dateFormatter.format(
                                        new Date(customer.createdAt),
                                    )}
                                </span>
                            </div>
                        ) : null}
                    </div>
                </div>
            </header>

            <div aria-atomic="true" aria-live="polite" className="empty:hidden">
                {feedback ? (
                    <Alert
                        className="text-xs"
                        variant={
                            feedback.type === 'success'
                                ? 'default'
                                : 'destructive'
                        }
                    >
                        {feedback.type === 'success' ? (
                            <CheckCircle2 className="size-4 text-emerald-500" />
                        ) : (
                            <AlertCircle className="size-4" />
                        )}
                        <AlertTitle className="text-xs font-semibold">
                            {feedback.title}
                        </AlertTitle>
                        <AlertDescription>{feedback.message}</AlertDescription>
                    </Alert>
                ) : null}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
                {/* Account Status Control */}
                <section
                    aria-labelledby="account-status-heading"
                    className="rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xs lg:col-span-1 lg:col-start-3 lg:row-start-1"
                >
                    <div className="flex items-center gap-2 border-b border-border pb-3">
                        <Shield
                            aria-hidden="true"
                            className="size-4 text-primary"
                        />
                        <h2
                            className="text-sm font-semibold text-foreground"
                            id="account-status-heading"
                        >
                            {copy.accountStatus}
                        </h2>
                    </div>

                    <div className="mt-4 flex flex-col gap-4">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-muted-foreground">
                                {customersCopy.status}
                            </span>
                            <AdminBadge
                                icon={
                                    customer.isActive ? CheckCircle2 : XCircle
                                }
                                variant={
                                    customer.isActive ? 'success' : 'danger'
                                }
                            >
                                {customer.isActive
                                    ? customersCopy.statusActive
                                    : customersCopy.statusSuspended}
                            </AdminBadge>
                        </div>

                        <p className="text-xs leading-relaxed text-muted-foreground">
                            {copy.statusDescription}
                        </p>

                        {canUpdateStatus ? (
                            customer.isActive ? (
                                <Button
                                    className="min-h-11 w-full text-xs font-medium"
                                    onClick={() =>
                                        handleOpenStatusDialog('suspend')
                                    }
                                    type="button"
                                    variant="outline"
                                >
                                    <XCircle className="size-4 text-destructive" />
                                    <span>{copy.suspendButton}</span>
                                </Button>
                            ) : (
                                <Button
                                    className="min-h-11 w-full text-xs font-medium"
                                    onClick={() =>
                                        handleOpenStatusDialog('reactivate')
                                    }
                                    type="button"
                                    variant="default"
                                >
                                    <CheckCircle2 className="size-4" />
                                    <span>{copy.reactivateButton}</span>
                                </Button>
                            )
                        ) : null}
                    </div>
                </section>

                {/* Main Left Column (2 Cols) */}
                <div className="space-y-6 lg:col-span-2 lg:col-start-1 lg:row-start-1">
                    {/* Customer Identity Section */}
                    <section
                        aria-labelledby="customer-identity-heading"
                        className="rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xs"
                    >
                        <div className="flex items-center justify-between border-b border-border pb-3">
                            <div className="flex items-center gap-2">
                                <UserIcon
                                    aria-hidden="true"
                                    className="size-4 text-primary"
                                />
                                <h2
                                    className="text-sm font-semibold text-foreground"
                                    id="customer-identity-heading"
                                >
                                    {copy.identitySection}
                                </h2>
                            </div>
                            {canUpdateContact ? (
                                <Button
                                    className="min-h-11 gap-1.5 text-xs font-medium"
                                    onClick={() => setContactDialogOpen(true)}
                                    type="button"
                                    variant="outline"
                                >
                                    <Pencil
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                    <span>{copy.editDetailsButton}</span>
                                </Button>
                            ) : null}
                        </div>

                        <dl className="mt-4 grid grid-cols-1 gap-4 text-xs sm:grid-cols-2">
                            <div>
                                <dt className="font-medium text-muted-foreground">
                                    {copy.name}
                                </dt>
                                <dd className="mt-1 font-semibold text-foreground">
                                    {customer.name}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium text-muted-foreground">
                                    {copy.email}
                                </dt>
                                <dd className="mt-1 flex items-center gap-2 [overflow-wrap:anywhere] text-foreground">
                                    <span>{customer.email}</span>
                                    {customer.emailVerifiedAt ? (
                                        <AdminBadge variant="success">
                                            {copy.emailVerified}
                                        </AdminBadge>
                                    ) : (
                                        <AdminBadge variant="neutral">
                                            {copy.emailUnverified}
                                        </AdminBadge>
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium text-muted-foreground">
                                    {copy.phone}
                                </dt>
                                <dd className="mt-1 flex items-center gap-2 text-foreground tabular-nums">
                                    {customer.phone ? (
                                        <>
                                            <bdi>{customer.phone}</bdi>
                                            {customer.phoneVerifiedAt ? (
                                                <AdminBadge variant="success">
                                                    {copy.phoneVerified}
                                                </AdminBadge>
                                            ) : (
                                                <AdminBadge variant="neutral">
                                                    {copy.phoneUnverified}
                                                </AdminBadge>
                                            )}
                                        </>
                                    ) : (
                                        <span className="text-muted-foreground/60 italic">
                                            {customersCopy.noPhone}
                                        </span>
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium text-muted-foreground">
                                    {copy.preferredLocale}
                                </dt>
                                <dd className="mt-1 font-semibold text-foreground uppercase">
                                    {customer.preferredLocale}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    {/* Orders Summary & Recent Orders */}
                    <section
                        aria-labelledby="customer-orders-heading"
                        className="rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xs"
                    >
                        <div className="flex items-center justify-between border-b border-border pb-3">
                            <div className="flex items-center gap-2">
                                <ShoppingBag
                                    aria-hidden="true"
                                    className="size-4 text-primary"
                                />
                                <h2
                                    className="text-sm font-semibold text-foreground"
                                    id="customer-orders-heading"
                                >
                                    {copy.ordersSection}
                                </h2>
                            </div>
                            <span className="text-xs font-semibold text-muted-foreground tabular-nums">
                                {customer.ordersSummary.ordersCount}{' '}
                                {copy.ordersCount}
                            </span>
                        </div>

                        <div className="mt-4 grid grid-cols-1 gap-4 border-b border-border pb-4 text-xs sm:grid-cols-2">
                            <div>
                                <span className="font-medium text-muted-foreground">
                                    {copy.totalSpent}
                                </span>
                                <p className="mt-1 text-base font-bold text-foreground tabular-nums">
                                    {formatAdminMoney(
                                        customer.ordersSummary.totalSpent,
                                        props.locale,
                                    )}
                                </p>
                            </div>
                            <div>
                                <span className="font-medium text-muted-foreground">
                                    {copy.lastOrderAt}
                                </span>
                                <p className="mt-1 font-medium text-foreground tabular-nums">
                                    {customer.ordersSummary.lastOrderAt
                                        ? dateFormatter.format(
                                              new Date(
                                                  customer.ordersSummary
                                                      .lastOrderAt,
                                              ),
                                          )
                                        : '—'}
                                </p>
                            </div>
                        </div>

                        <div className="mt-4">
                            <h3 className="mb-3 text-xs font-semibold text-foreground">
                                {copy.recentOrders}
                            </h3>
                            {customer.recentOrders.length > 0 ? (
                                <>
                                    {/* Mobile Recent Orders as Tappable Rows */}
                                    <div className="flex flex-col divide-y divide-border/60 md:hidden">
                                        {customer.recentOrders.map((order) => (
                                            <Link
                                                className="flex min-h-11 items-center justify-between gap-3 py-2.5 text-xs transition-colors hover:bg-muted/30 focus-visible:outline-2 focus-visible:outline-ring"
                                                href={`${ordersBasePath}/${order.id}`}
                                                key={order.id}
                                            >
                                                <div className="flex min-w-0 flex-col gap-0.5">
                                                    <span className="font-semibold text-foreground tabular-nums underline decoration-border underline-offset-4">
                                                        <bdi>
                                                            {order.orderNumber}
                                                        </bdi>
                                                    </span>
                                                    <span className="text-[11px] text-muted-foreground tabular-nums">
                                                        <bdi>
                                                            {order.placedAt
                                                                ? dateFormatter.format(
                                                                      new Date(
                                                                          order.placedAt,
                                                                      ),
                                                                  )
                                                                : '—'}
                                                        </bdi>
                                                    </span>
                                                </div>
                                                <div className="flex shrink-0 flex-col items-end gap-1">
                                                    <AdminBadge
                                                        className="text-[11px]"
                                                        icon={
                                                            statusIcons[
                                                                order.status
                                                            ]
                                                        }
                                                        variant={getStatusVariant(
                                                            order.status,
                                                        )}
                                                    >
                                                        {statuses[
                                                            order.status
                                                        ] ?? order.status}
                                                    </AdminBadge>
                                                    <span className="font-bold text-foreground tabular-nums">
                                                        <bdi>
                                                            {formatAdminMoney(
                                                                order.total,
                                                                props.locale,
                                                            )}
                                                        </bdi>
                                                    </span>
                                                </div>
                                            </Link>
                                        ))}
                                    </div>

                                    {/* Desktop Recent Orders Table */}
                                    <div className="hidden overflow-x-auto md:block">
                                        <table className="w-full text-start text-xs">
                                            <thead>
                                                <tr className="border-b border-border text-muted-foreground">
                                                    <th className="pb-2 text-start font-medium">
                                                        {copy.orderNumber}
                                                    </th>
                                                    <th className="pb-2 text-start font-medium">
                                                        {copy.orderStatus}
                                                    </th>
                                                    <th className="pb-2 text-start font-medium">
                                                        {copy.orderTotal}
                                                    </th>
                                                    <th className="pb-2 text-start font-medium">
                                                        {copy.orderPlacedAt}
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-border/60">
                                                {customer.recentOrders.map(
                                                    (order) => (
                                                        <tr key={order.id}>
                                                            <td className="py-2.5 font-semibold">
                                                                <Link
                                                                    className="text-foreground tabular-nums underline decoration-border underline-offset-4 hover:text-primary hover:decoration-primary"
                                                                    href={`${ordersBasePath}/${order.id}`}
                                                                >
                                                                    <bdi>
                                                                        {
                                                                            order.orderNumber
                                                                        }
                                                                    </bdi>
                                                                </Link>
                                                            </td>
                                                            <td className="py-2.5">
                                                                <AdminBadge
                                                                    icon={
                                                                        statusIcons[
                                                                            order
                                                                                .status
                                                                        ]
                                                                    }
                                                                    variant={getStatusVariant(
                                                                        order.status,
                                                                    )}
                                                                >
                                                                    {statuses[
                                                                        order
                                                                            .status
                                                                    ] ??
                                                                        order.status}
                                                                </AdminBadge>
                                                            </td>
                                                            <td className="py-2.5 font-semibold text-foreground tabular-nums">
                                                                {formatAdminMoney(
                                                                    order.total,
                                                                    props.locale,
                                                                )}
                                                            </td>
                                                            <td className="py-2.5 text-muted-foreground tabular-nums">
                                                                {order.placedAt
                                                                    ? dateFormatter.format(
                                                                          new Date(
                                                                              order.placedAt,
                                                                          ),
                                                                      )
                                                                    : '—'}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    {copy.noOrders}
                                </p>
                            )}
                        </div>
                    </section>

                    {/* Wallet Summary & Transactions */}
                    <section
                        aria-labelledby="customer-wallet-heading"
                        className="rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xs"
                    >
                        <div className="flex items-center justify-between border-b border-border pb-3">
                            <div className="flex items-center gap-2">
                                <Wallet
                                    aria-hidden="true"
                                    className="size-4 text-primary"
                                />
                                <h2
                                    className="text-sm font-semibold text-foreground"
                                    id="customer-wallet-heading"
                                >
                                    {copy.walletSection}
                                </h2>
                            </div>
                            <span className="text-xs font-semibold text-muted-foreground tabular-nums">
                                {customer.walletSummary.entriesCount}{' '}
                                {copy.walletEntriesCount}
                            </span>
                        </div>

                        <div className="mt-4 border-b border-border pb-4 text-xs">
                            <span className="font-medium text-muted-foreground">
                                {copy.walletBalance}
                            </span>
                            <p className="mt-1 text-base font-bold text-foreground tabular-nums">
                                {formatAdminMoney(
                                    customer.walletSummary.balance,
                                    props.locale,
                                )}
                            </p>
                        </div>

                        <div className="mt-4">
                            <h3 className="mb-3 text-xs font-semibold text-foreground">
                                {copy.recentWalletEntries}
                            </h3>
                            {customer.recentWalletEntries.length > 0 ? (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-start text-xs">
                                        <thead>
                                            <tr className="border-b border-border text-muted-foreground">
                                                <th className="pb-2 text-start font-medium">
                                                    {copy.entryType}
                                                </th>
                                                <th className="pb-2 text-start font-medium">
                                                    {copy.entryAmount}
                                                </th>
                                                <th className="pb-2 text-start font-medium">
                                                    {copy.entryReference}
                                                </th>
                                                <th className="pb-2 text-start font-medium">
                                                    {copy.entryDate}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-border/60">
                                            {customer.recentWalletEntries.map(
                                                (entry) => (
                                                    <tr key={entry.id}>
                                                        <td className="py-2.5 font-medium text-foreground uppercase">
                                                            {entry.type}
                                                        </td>
                                                        <td className="py-2.5 font-semibold tabular-nums">
                                                            <span
                                                                className={
                                                                    entry.direction ===
                                                                    'credit'
                                                                        ? 'text-emerald-500'
                                                                        : entry.direction ===
                                                                            'debit'
                                                                          ? 'text-destructive'
                                                                          : 'text-foreground'
                                                                }
                                                            >
                                                                {entry.direction ===
                                                                'credit'
                                                                    ? '+'
                                                                    : entry.direction ===
                                                                        'debit'
                                                                      ? '-'
                                                                      : ''}
                                                                {formatAdminMoney(
                                                                    entry.amount,
                                                                    props.locale,
                                                                )}
                                                            </span>
                                                        </td>
                                                        <td className="py-2.5 [overflow-wrap:anywhere] text-muted-foreground">
                                                            {entry.reference ? (
                                                                <bdi>
                                                                    {
                                                                        entry.reference
                                                                    }
                                                                </bdi>
                                                            ) : (
                                                                '—'
                                                            )}
                                                        </td>
                                                        <td className="py-2.5 text-muted-foreground tabular-nums">
                                                            {entry.createdAt
                                                                ? dateFormatter.format(
                                                                      new Date(
                                                                          entry.createdAt,
                                                                      ),
                                                                  )
                                                                : '—'}
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    {copy.noWalletEntries}
                                </p>
                            )}
                        </div>
                    </section>

                    {/* Staff Audit Logs (if present) */}
                    {customer.recentAuditLogs !== null ? (
                        <section
                            aria-labelledby="customer-audit-heading"
                            className="rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xs"
                        >
                            <div className="flex items-center gap-2 border-b border-border pb-3">
                                <History
                                    aria-hidden="true"
                                    className="size-4 text-primary"
                                />
                                <h2
                                    className="text-sm font-semibold text-foreground"
                                    id="customer-audit-heading"
                                >
                                    {copy.auditSection}
                                </h2>
                            </div>

                            <div className="mt-4">
                                {customer.recentAuditLogs.length > 0 ? (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-start text-xs">
                                            <thead>
                                                <tr className="border-b border-border text-muted-foreground">
                                                    <th className="pb-2 text-start font-medium">
                                                        {copy.action}
                                                    </th>
                                                    <th className="pb-2 text-start font-medium">
                                                        {copy.actor}
                                                    </th>
                                                    <th className="pb-2 text-start font-medium">
                                                        {copy.date}
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-border/60">
                                                {customer.recentAuditLogs.map(
                                                    (log) => (
                                                        <tr key={log.id}>
                                                            <td className="py-2.5 font-medium text-foreground">
                                                                <code>
                                                                    {log.action}
                                                                </code>
                                                            </td>
                                                            <td className="py-2.5 text-muted-foreground">
                                                                {log.actor ? (
                                                                    <span>
                                                                        {
                                                                            log
                                                                                .actor
                                                                                .name
                                                                        }{' '}
                                                                        (
                                                                        {
                                                                            log
                                                                                .actor
                                                                                .role
                                                                        }
                                                                        )
                                                                    </span>
                                                                ) : (
                                                                    'System'
                                                                )}
                                                            </td>
                                                            <td className="py-2.5 text-muted-foreground tabular-nums">
                                                                {log.createdAt
                                                                    ? dateFormatter.format(
                                                                          new Date(
                                                                              log.createdAt,
                                                                          ),
                                                                      )
                                                                    : '—'}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        {copy.noAudit}
                                    </p>
                                )}
                            </div>
                        </section>
                    ) : null}
                </div>
            </div>

            <AdminCustomerStatusDialog
                action={statusAction}
                adminUi={props.adminUi}
                confirmPasswordUrl={props.confirmPasswordUrl}
                customer={customer}
                onConflict={handleStatusConflict}
                onOpenChange={setStatusDialogOpen}
                onSuccess={handleStatusSuccess}
                open={statusDialogOpen}
                statusUrl={props.statusUrl}
            />

            <AdminCustomerContactDialog
                adminUi={props.adminUi}
                confirmPasswordUrl={props.confirmPasswordUrl}
                contactUrl={props.contactUrl}
                customer={customer}
                onConflict={handleContactConflict}
                onOpenChange={setContactDialogOpen}
                onSuccess={handleContactSuccess}
                open={contactDialogOpen}
            />
        </article>
    );
}
