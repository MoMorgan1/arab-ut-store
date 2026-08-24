'use no memo'; // TanStack Table exposes mutable row and table objects.

import { Link, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Bot,
    Eye,
    EyeOff,
    UserCheck,
} from 'lucide-react';

import AdminBadge from '@/components/admin/admin-badge';
import { Checkbox } from '@/components/ui/checkbox';
import { DATE_LOCALE } from '@/lib/date-locale';
import type { AdminProductRow, AdminTranslations } from '@/types/admin';

type ProductSortKey = 'name' | 'created_at' | 'updated_at' | 'sort_order';

export type ProductColumnOptions = {
    adminUi: AdminTranslations;
    currentSort: ProductSortKey;
    currentDirection: 'asc' | 'desc';
    locale: 'ar' | 'en';
    onSortChange: (sort: ProductSortKey, direction: 'asc' | 'desc') => void;
};

function ProductNameCell({ row }: { row: { original: AdminProductRow } }) {
    const { url } = usePage();
    const isLocalized = url.startsWith('/en/admin');
    const basePath = isLocalized ? '/en/admin/products' : '/admin/products';
    const detailUrl = `${basePath}/${row.original.id}`;

    return (
        <div className="flex max-w-56 flex-col gap-0.5">
            <Link
                className="text-sm font-semibold whitespace-nowrap text-foreground underline decoration-border underline-offset-4 transition-colors hover:text-primary hover:decoration-primary focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                href={detailUrl}
            >
                <bdi>{row.original.name}</bdi>
            </Link>
            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <span className="truncate" title={row.original.slug}>
                    <bdi>{row.original.slug}</bdi>
                </span>
            </div>
        </div>
    );
}

export function getAdminProductColumns({
    adminUi,
    currentSort,
    currentDirection,
    locale,
    onSortChange,
}: ProductColumnOptions): ColumnDef<AdminProductRow>[] {
    const copy = adminUi.products;
    const orderServices = adminUi.orders.services ?? {};
    const dateFormatter = new Intl.DateTimeFormat(DATE_LOCALE, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    const sortHeader = (sortKey: ProductSortKey, label: string) => {
        const isSorted = currentSort === sortKey;
        const nextDirection = isSorted
            ? currentDirection === 'asc'
                ? 'desc'
                : 'asc'
            : sortKey === 'name' || sortKey === 'sort_order'
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
            cell: ({ row }) => <ProductNameCell row={row} />,
            header: () => sortHeader('name', copy.product),
            id: 'product',
        },
        {
            accessorKey: 'serviceType',
            cell: ({ row }) => {
                const serviceKey = row.original.serviceType;
                const serviceLabel = orderServices[serviceKey] ?? serviceKey;

                return (
                    <span className="text-xs font-medium text-foreground">
                        {serviceLabel}
                    </span>
                );
            },
            header: copy.service,
            id: 'service',
        },
        {
            accessorKey: 'authority',
            cell: ({ row }) => {
                const isManual = row.original.authority === 'manual';

                return (
                    <AdminBadge
                        icon={isManual ? UserCheck : Bot}
                        variant={isManual ? 'info' : 'neutral'}
                    >
                        {isManual
                            ? copy.authorityManual
                            : copy.authorityAutomation}
                    </AdminBadge>
                );
            },
            header: copy.authority,
            id: 'authority',
        },
        {
            accessorKey: 'source',
            cell: ({ row }) => {
                const source = row.original.source;

                return (
                    <span className="text-xs text-muted-foreground">
                        {source ? source.name : copy.sourceManual}
                    </span>
                );
            },
            header: copy.source,
            id: 'source',
        },
        {
            accessorKey: 'isVisible',
            cell: ({ row }) => {
                const isVisible = row.original.isVisible;

                return (
                    <AdminBadge
                        icon={isVisible ? Eye : EyeOff}
                        variant={isVisible ? 'success' : 'neutral'}
                    >
                        {isVisible
                            ? copy.visibilityVisible
                            : copy.visibilityHidden}
                    </AdminBadge>
                );
            },
            header: copy.visibility,
            id: 'visibility',
        },
        {
            accessorKey: 'variantsCount',
            cell: ({ row }) => (
                <span className="text-xs font-medium text-foreground tabular-nums">
                    {row.original.variantsCount}
                </span>
            ),
            header: copy.variantsCount,
            id: 'variantsCount',
        },
        {
            accessorKey: 'sortOrder',
            cell: ({ row }) => (
                <span className="text-xs font-medium text-foreground tabular-nums">
                    {row.original.sortOrder}
                </span>
            ),
            header: () => sortHeader('sort_order', copy.sortOrder),
            id: 'sortOrder',
        },
        {
            accessorKey: 'updatedAt',
            cell: ({ row }) => {
                const date = row.original.updatedAt;

                return (
                    <span className="text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                        {date ? dateFormatter.format(new Date(date)) : '—'}
                    </span>
                );
            },
            header: () => sortHeader('updated_at', copy.updatedAt),
            id: 'updatedAt',
        },
        {
            cell: ({ row }) => {
                const isLocalized = locale === 'en';
                const basePath = isLocalized
                    ? '/en/admin/products'
                    : '/admin/products';
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
