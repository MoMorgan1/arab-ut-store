'use no memo'; // TanStack Table exposes mutable row and table objects.

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
import React from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { AdminCategoryRow, AdminTranslations } from '@/types/admin';

export type CategorySortKey =
    'sort_order' | 'name' | 'created_at' | 'updated_at';

export type CategoryColumnOptions = {
    adminUi: AdminTranslations;
    canManage: boolean;
    currentDirection: 'asc' | 'desc';
    currentSort: CategorySortKey;
    locale: 'ar' | 'en';
    onSortChange: (sort: CategorySortKey, direction: 'asc' | 'desc') => void;
    onToggleVisibility: (category: AdminCategoryRow) => void;
};

function CategoryNameCell({ row }: { row: { original: AdminCategoryRow } }) {
    return (
        <div className="flex max-w-56 flex-col gap-0.5">
            <span className="text-sm font-semibold whitespace-nowrap text-foreground">
                <bdi>{row.original.name}</bdi>
            </span>
            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <span className="truncate" title={row.original.slug}>
                    <bdi>{row.original.slug}</bdi>
                </span>
            </div>
        </div>
    );
}

export function getAdminCategoryColumns({
    adminUi,
    canManage,
    currentDirection,
    currentSort,
    locale,
    onSortChange,
    onToggleVisibility,
}: CategoryColumnOptions): ColumnDef<AdminCategoryRow>[] {
    const copy = adminUi.categories;
    const dateFormatter = new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    const sortHeader = (sortKey: CategorySortKey, label: string) => {
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
            cell: ({ row }) => <CategoryNameCell row={row} />,
            header: () => sortHeader('name', copy.name),
            id: 'name',
        },
        {
            accessorKey: 'productsCount',
            cell: ({ row }) => (
                <span className="text-xs font-medium text-foreground tabular-nums">
                    {row.original.productsCount}
                </span>
            ),
            header: copy.products,
            id: 'productsCount',
        },
        {
            accessorKey: 'visibleProductsCount',
            cell: ({ row }) => (
                <span className="text-xs font-medium text-foreground tabular-nums">
                    {row.original.visibleProductsCount}
                </span>
            ),
            header: copy.visibleProducts,
            id: 'visibleProductsCount',
        },
        {
            accessorKey: 'source',
            cell: ({ row }) => {
                const isAutomation = row.original.isAutomation;
                const source = row.original.source;

                return (
                    <div className="flex flex-col gap-1">
                        <AdminBadge
                            icon={isAutomation ? Bot : UserCheck}
                            variant={isAutomation ? 'neutral' : 'info'}
                        >
                            {source ? source.name : copy.sourceManual}
                        </AdminBadge>
                    </div>
                );
            },
            header: copy.source,
            id: 'source',
        },
        {
            accessorKey: 'visibility',
            cell: ({ row }) => {
                const { adminHidden, isVisible, adminHiddenAt } = row.original;

                if (adminHidden) {
                    return (
                        <div className="flex flex-col gap-1">
                            <AdminBadge icon={EyeOff} variant="danger">
                                {copy.stateAdminHidden}
                            </AdminBadge>
                            {adminHiddenAt ? (
                                <span className="text-[11px] text-muted-foreground">
                                    {copy.hiddenAt.replace(
                                        ':date',
                                        dateFormatter.format(
                                            new Date(adminHiddenAt),
                                        ),
                                    )}
                                </span>
                            ) : null}
                            {!isVisible ? (
                                <span className="text-[11px] text-amber-500/90 dark:text-amber-400/90">
                                    {copy.automationHiddenBadge}
                                </span>
                            ) : null}
                        </div>
                    );
                }

                if (!isVisible) {
                    return (
                        <div className="flex flex-col gap-1">
                            <AdminBadge icon={EyeOff} variant="warning">
                                {copy.stateAutomationHidden}
                            </AdminBadge>
                        </div>
                    );
                }

                return (
                    <AdminBadge icon={Eye} variant="success">
                        {copy.stateVisible}
                    </AdminBadge>
                );
            },
            header: copy.status,
            id: 'visibility',
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
                if (!canManage) {
                    return null;
                }

                const isHidden = row.original.adminHidden;

                return (
                    <Button
                        aria-label={
                            isHidden ? copy.restoreToStore : copy.hideFromStore
                        }
                        className={`min-h-11 min-w-11 gap-1.5 text-xs font-medium ${
                            isHidden
                                ? 'text-primary hover:text-primary'
                                : 'text-destructive hover:text-destructive'
                        }`}
                        onClick={() => onToggleVisibility(row.original)}
                        type="button"
                        variant="ghost"
                    >
                        {isHidden ? (
                            <>
                                <Eye aria-hidden="true" className="size-4" />
                                <span>{copy.restoreToStore}</span>
                            </>
                        ) : (
                            <>
                                <EyeOff aria-hidden="true" className="size-4" />
                                <span>{copy.hideFromStore}</span>
                            </>
                        )}
                    </Button>
                );
            },
            enableHiding: false,
            enableSorting: false,
            header: copy.actions,
            id: 'actions',
        },
    ];
}
