'use no memo'; // TanStack Table exposes mutable row and table objects.

import { Link, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';

import AdminBadge from '@/components/admin/admin-badge';
import { formatAdminMoney } from '@/components/admin/admin-money';
import {
    getStatusVariant,
    statusIcons,
} from '@/components/admin/admin-order-status';
import { Checkbox } from '@/components/ui/checkbox';
import { DATE_LOCALE } from '@/lib/date-locale';
import type { AdminOrderRow, AdminTranslations } from '@/types/admin';

type SortKey = 'placed_at' | 'total' | 'order_number';

export type ColumnOptions = {
    adminUi: AdminTranslations;
    currentSort: SortKey;
    currentDirection: 'asc' | 'desc';
    locale: 'ar' | 'en';
    onSortChange: (sort: SortKey, direction: 'asc' | 'desc') => void;
};

function OrderNumberCell({ row }: { row: { original: AdminOrderRow } }) {
    const { url } = usePage();
    const isLocalized = url.startsWith('/en/admin');
    const basePath = isLocalized ? '/en/admin/orders' : '/admin/orders';
    const detailUrl = `${basePath}/${row.original.id}`;

    return (
        <div className="flex max-w-44 flex-col gap-0.5">
            <Link
                className="text-sm font-semibold whitespace-nowrap text-foreground tabular-nums underline decoration-border underline-offset-4 transition-colors hover:text-primary hover:decoration-primary focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                href={detailUrl}
            >
                <bdi>{row.original.orderNumber}</bdi>
            </Link>
            <span
                className="truncate text-xs text-muted-foreground"
                title={row.original.id}
            >
                <bdi>{row.original.id}</bdi>
            </span>
        </div>
    );
}

export function getAdminOrderColumns({
    adminUi,
    currentSort,
    currentDirection,
    locale,
    onSortChange,
}: ColumnOptions): ColumnDef<AdminOrderRow>[] {
    const copy = adminUi.orders;
    const statuses = adminUi.statuses;
    const dateFormatter = new Intl.DateTimeFormat(DATE_LOCALE, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });
    const sortHeader = (sortKey: SortKey, label: string) => {
        const isSorted = currentSort === sortKey;
        const nextDirection = isSorted
            ? currentDirection === 'asc'
                ? 'desc'
                : 'asc'
            : sortKey === 'order_number'
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
                <label
                    className="flex min-h-11 min-w-11 cursor-pointer items-center justify-center"
                    htmlFor={`select-order-${row.original.id}`}
                >
                    <Checkbox
                        aria-label={`${copy.selectRow} ${row.original.orderNumber}`}
                        checked={row.getIsSelected()}
                        id={`select-order-${row.original.id}`}
                        onCheckedChange={(checked) =>
                            row.toggleSelected(Boolean(checked))
                        }
                    />
                </label>
            ),
            enableHiding: false,
            enableSorting: false,
            header: ({ table }) => (
                <label
                    className="flex min-h-11 min-w-11 cursor-pointer items-center justify-center"
                    htmlFor="select-all-orders"
                >
                    <Checkbox
                        aria-label={copy.selectAll}
                        checked={
                            table.getIsAllPageRowsSelected() ||
                            (table.getIsSomePageRowsSelected() &&
                                'indeterminate')
                        }
                        id="select-all-orders"
                        onCheckedChange={(checked) =>
                            table.toggleAllPageRowsSelected(Boolean(checked))
                        }
                    />
                </label>
            ),
            id: 'select',
        },
        {
            accessorKey: 'orderNumber',
            cell: ({ row }) => <OrderNumberCell row={row} />,
            enableHiding: false,
            header: () => sortHeader('order_number', copy.order),
            id: 'order_number',
        },
        {
            accessorFn: (row) => row.customer.name,
            cell: ({ row }) => (
                <div className="flex max-w-52 flex-col gap-0.5">
                    <span className="truncate text-sm font-medium text-foreground">
                        {row.original.customer.name || '—'}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                        {row.original.customer.email}
                    </span>
                    {row.original.customer.phone ? (
                        <span className="text-xs text-muted-foreground tabular-nums">
                            <bdi>{row.original.customer.phone}</bdi>
                        </span>
                    ) : null}
                </div>
            ),
            enableSorting: false,
            header: copy.customer,
            id: 'customer',
        },
        {
            accessorKey: 'status',
            cell: ({ row }) => {
                const status = row.original.status;

                return (
                    <AdminBadge
                        icon={statusIcons[status]}
                        variant={getStatusVariant(status)}
                    >
                        {statuses[status] ?? status}
                    </AdminBadge>
                );
            },
            enableSorting: false,
            header: copy.status,
            id: 'status',
        },
        {
            accessorFn: (row) => row.serviceTypes.join(','),
            cell: ({ row }) => (
                <TagList
                    labels={copy.services}
                    values={row.original.serviceTypes}
                    variant="filled"
                />
            ),
            enableSorting: false,
            header: copy.service,
            id: 'serviceTypes',
        },
        {
            accessorFn: (row) => row.platforms.join(','),
            cell: ({ row }) => (
                <TagList
                    labels={copy.platforms}
                    values={row.original.platforms}
                />
            ),
            enableSorting: false,
            header: copy.platform,
            id: 'platforms',
        },
        {
            accessorKey: 'itemCount',
            cell: ({ row }) => (
                <span className="text-sm text-foreground tabular-nums">
                    {row.original.itemCount}
                </span>
            ),
            enableSorting: false,
            header: copy.items,
            id: 'itemCount',
        },
        {
            accessorFn: (row) => row.latestPaymentStatus ?? '',
            cell: ({ row }) => {
                const status = row.original.latestPaymentStatus;

                return status ? (
                    <AdminBadge
                        icon={statusIcons[status]}
                        variant={getStatusVariant(status)}
                    >
                        {statuses[status] ?? status}
                    </AdminBadge>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        {copy.noPayment}
                    </span>
                );
            },
            enableSorting: false,
            header: copy.payment,
            id: 'payment',
        },
        {
            accessorFn: (row) => row.total.amountMinor,
            cell: ({ row }) => (
                <span className="text-sm font-semibold text-foreground tabular-nums">
                    <bdi>{formatAdminMoney(row.original.total, locale)}</bdi>
                </span>
            ),
            header: () => sortHeader('total', copy.total),
            id: 'total',
        },
        {
            accessorKey: 'placedAt',
            cell: ({ row }) => (
                <span className="text-xs text-muted-foreground tabular-nums">
                    <bdi>
                        {row.original.placedAt
                            ? dateFormatter.format(
                                  new Date(row.original.placedAt),
                              )
                            : '—'}
                    </bdi>
                </span>
            ),
            header: () => sortHeader('placed_at', copy.placedAt),
            id: 'placed_at',
        },
    ];
}

function TagList({
    labels,
    values,
    variant = 'outlined',
}: {
    labels: Record<string, string>;
    values: string[];
    variant?: 'filled' | 'outlined';
}) {
    if (values.length === 0) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {values.map((value) => (
                <span
                    className={
                        variant === 'filled'
                            ? 'rounded-sm bg-secondary px-1.5 py-0.5 text-xs text-secondary-foreground'
                            : 'rounded-sm border border-border px-1.5 py-0.5 text-xs text-muted-foreground'
                    }
                    key={value}
                >
                    {labels[value] ?? value}
                </span>
            ))}
        </div>
    );
}
