'use no memo'; // TanStack Table exposes mutable row and table objects.

import { Link, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    CheckCircle2,
    XCircle,
} from 'lucide-react';

import AdminBadge from '@/components/admin/admin-badge';
import { formatAdminMoney } from '@/components/admin/admin-money';
import { Checkbox } from '@/components/ui/checkbox';
import { DATE_LOCALE } from '@/lib/date-locale';
import type { AdminCustomerRow, AdminTranslations } from '@/types/admin';

type CustomerSortKey =
    'created_at' | 'name' | 'orders_count' | 'last_order_at' | 'total_spent';

export type CustomerColumnOptions = {
    adminUi: AdminTranslations;
    currentSort: CustomerSortKey;
    currentDirection: 'asc' | 'desc';
    locale: 'ar' | 'en';
    onSortChange: (sort: CustomerSortKey, direction: 'asc' | 'desc') => void;
};

function CustomerNameCell({ row }: { row: { original: AdminCustomerRow } }) {
    const { url } = usePage();
    const isLocalized = url.startsWith('/en/admin');
    const basePath = isLocalized ? '/en/admin/customers' : '/admin/customers';
    const detailUrl = `${basePath}/${row.original.id}`;

    return (
        <div className="flex max-w-44 flex-col gap-0.5">
            <Link
                className="text-sm font-semibold whitespace-nowrap text-foreground underline decoration-border underline-offset-4 transition-colors hover:text-primary hover:decoration-primary focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                href={detailUrl}
            >
                <bdi>{row.original.name}</bdi>
            </Link>
            <span
                className="truncate text-xs text-muted-foreground tabular-nums"
                title={row.original.id}
            >
                <bdi>{row.original.number ?? row.original.id}</bdi>
            </span>
        </div>
    );
}

export function getAdminCustomerColumns({
    adminUi,
    currentSort,
    currentDirection,
    locale,
    onSortChange,
}: CustomerColumnOptions): ColumnDef<AdminCustomerRow>[] {
    const copy = adminUi.customers;
    const dateFormatter = new Intl.DateTimeFormat(DATE_LOCALE, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    const sortHeader = (sortKey: CustomerSortKey, label: string) => {
        const isSorted = currentSort === sortKey;
        const nextDirection = isSorted
            ? currentDirection === 'asc'
                ? 'desc'
                : 'asc'
            : sortKey === 'name'
              ? 'asc'
              : 'desc';
        const direction =
            currentDirection === 'asc'
                ? copy.sortAscending
                : copy.sortDescending;
        const ariaLabel = isSorted
            ? `${label}, ${direction}`
            : copy.sortBy.replace(':column', label);

        return (
            <button
                aria-label={ariaLabel}
                className="inline-flex min-h-11 items-center gap-1.5 font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                onClick={() => onSortChange(sortKey, nextDirection)}
                type="button"
            >
                <span>{label}</span>
                {isSorted ? (
                    currentDirection === 'asc' ? (
                        <ArrowUp
                            aria-hidden="true"
                            className="size-3.5 text-primary"
                        />
                    ) : (
                        <ArrowDown
                            aria-hidden="true"
                            className="size-3.5 text-primary"
                        />
                    )
                ) : (
                    <ArrowUpDown
                        aria-hidden="true"
                        className="size-3.5 opacity-50"
                    />
                )}
            </button>
        );
    };

    return [
        {
            cell: ({ row }) => (
                <Checkbox
                    aria-label={copy.selectRow}
                    checked={row.getIsSelected()}
                    className="translate-y-0.5"
                    onCheckedChange={(value) =>
                        row.toggleSelected(Boolean(value))
                    }
                />
            ),
            enableHiding: false,
            enableSorting: false,
            header: ({ table }) => (
                <Checkbox
                    aria-label={copy.selectAll}
                    checked={
                        table.getIsAllPageRowsSelected() ||
                        (table.getIsSomePageRowsSelected() && 'indeterminate')
                    }
                    className="translate-y-0.5"
                    onCheckedChange={(value) =>
                        table.toggleAllPageRowsSelected(Boolean(value))
                    }
                />
            ),
            id: 'select',
        },
        {
            accessorKey: 'name',
            cell: ({ row }) => <CustomerNameCell row={row} />,
            header: () => sortHeader('name', copy.customer),
            id: 'customer',
        },
        {
            accessorKey: 'email',
            cell: ({ row }) => (
                <span className="text-xs text-muted-foreground">
                    {row.original.email}
                </span>
            ),
            header: copy.email,
            id: 'email',
        },
        {
            accessorKey: 'phone',
            cell: ({ row }) => (
                <span className="text-xs text-muted-foreground tabular-nums">
                    {row.original.phone ? (
                        <bdi>{row.original.phone}</bdi>
                    ) : (
                        <span className="text-muted-foreground/60 italic">
                            {copy.noPhone}
                        </span>
                    )}
                </span>
            ),
            header: copy.phone,
            id: 'phone',
        },
        {
            accessorKey: 'isActive',
            cell: ({ row }) => {
                const isActive = row.original.isActive;

                return (
                    <AdminBadge
                        icon={isActive ? CheckCircle2 : XCircle}
                        variant={isActive ? 'success' : 'danger'}
                    >
                        {isActive ? copy.statusActive : copy.statusSuspended}
                    </AdminBadge>
                );
            },
            header: copy.status,
            id: 'status',
        },
        {
            accessorKey: 'ordersCount',
            cell: ({ row }) => (
                <span className="text-xs font-medium text-foreground tabular-nums">
                    {row.original.ordersCount}
                </span>
            ),
            header: () => sortHeader('orders_count', copy.ordersCount),
            id: 'ordersCount',
        },
        {
            accessorKey: 'totalSpent',
            cell: ({ row }) => (
                <span className="text-xs font-medium text-foreground tabular-nums">
                    {formatAdminMoney(row.original.totalSpent, locale)}
                </span>
            ),
            header: () => sortHeader('total_spent', copy.totalSpent),
            id: 'totalSpent',
        },
        {
            accessorKey: 'walletBalance',
            cell: ({ row }) => (
                <span className="text-xs font-medium text-foreground tabular-nums">
                    {formatAdminMoney(row.original.walletBalance, locale)}
                </span>
            ),
            header: copy.walletBalance,
            id: 'walletBalance',
        },
        {
            accessorKey: 'createdAt',
            cell: ({ row }) => {
                const date = row.original.createdAt;

                return (
                    <span className="text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                        {date ? dateFormatter.format(new Date(date)) : '—'}
                    </span>
                );
            },
            header: () => sortHeader('created_at', copy.createdAt),
            id: 'createdAt',
        },
        {
            cell: ({ row }) => {
                const isLocalized = locale === 'en';
                const basePath = isLocalized
                    ? '/en/admin/customers'
                    : '/admin/customers';
                const detailUrl = `${basePath}/${row.original.id}`;

                return (
                    <Link
                        className="inline-flex min-h-11 items-center justify-center rounded-md px-3 text-xs font-medium text-primary hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                        href={detailUrl}
                    >
                        {copy.viewDetail}
                    </Link>
                );
            },
            enableHiding: false,
            enableSorting: false,
            header: copy.actions,
            id: 'actions',
        },
    ];
}
