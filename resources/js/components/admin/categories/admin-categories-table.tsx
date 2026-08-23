'use no memo'; // TanStack Table exposes mutable row and table objects.

import { flexRender } from '@tanstack/react-table';
import type { Table as TanStackTable } from '@tanstack/react-table';
import { LoaderCircle } from 'lucide-react';
import React from 'react';

import AdminCategoriesMobileCard from '@/components/admin/categories/admin-categories-mobile-card';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { AdminCategoryRow, AdminTranslations } from '@/types/admin';
import type { CategorySortKey } from './admin-categories-columns';

export type AdminCategoriesTableProps = {
    adminUi: AdminTranslations;
    canManage: boolean;
    currentDirection: 'asc' | 'desc';
    currentSort: CategorySortKey;
    isFiltered: boolean;
    isNavigating: boolean;
    locale: 'ar' | 'en';
    onResetFilters: () => void;
    onToggleVisibility: (category: AdminCategoryRow) => void;
    table: TanStackTable<AdminCategoryRow>;
};

export default function AdminCategoriesTable({
    adminUi,
    canManage,
    currentDirection,
    currentSort,
    isFiltered,
    isNavigating,
    locale,
    onResetFilters,
    onToggleVisibility,
    table,
}: AdminCategoriesTableProps) {
    const copy = adminUi.categories;
    const rows = table.getRowModel().rows;
    const dateFormatter = new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    return (
        <div aria-busy={isNavigating} className="relative">
            {isNavigating ? (
                <div
                    aria-live="polite"
                    className="absolute inset-0 z-20 flex items-center justify-center rounded-lg bg-background/90"
                >
                    <div className="flex items-center gap-2 rounded-md border border-border bg-popover px-4 py-2 text-sm font-medium text-popover-foreground shadow-md">
                        <LoaderCircle
                            aria-hidden="true"
                            className="size-4 animate-spin motion-reduce:hidden"
                        />
                        <span>{copy.loading}</span>
                    </div>
                </div>
            ) : null}

            <div
                aria-label={copy.tableLabel}
                className="hidden rounded-lg border border-border bg-card shadow-xs md:block"
                role="region"
            >
                <Table>
                    <TableHeader>
                        {table.getHeaderGroups().map((headerGroup) => (
                            <TableRow key={headerGroup.id}>
                                {headerGroup.headers.map((header) => (
                                    <TableHead
                                        aria-sort={ariaSort(
                                            header.column.id,
                                            currentSort,
                                            currentDirection,
                                        )}
                                        key={header.id}
                                    >
                                        {header.isPlaceholder
                                            ? null
                                            : flexRender(
                                                  header.column.columnDef
                                                      .header,
                                                  header.getContext(),
                                              )}
                                    </TableHead>
                                ))}
                            </TableRow>
                        ))}
                    </TableHeader>
                    <TableBody>
                        {rows.length > 0 ? (
                            rows.map((row) => (
                                <TableRow
                                    data-state={
                                        row.getIsSelected()
                                            ? 'selected'
                                            : undefined
                                    }
                                    key={row.id}
                                >
                                    {row.getVisibleCells().map((cell) => (
                                        <TableCell key={cell.id}>
                                            {flexRender(
                                                cell.column.columnDef.cell,
                                                cell.getContext(),
                                            )}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))
                        ) : (
                            <TableRow>
                                <TableCell
                                    className="h-32 text-center"
                                    colSpan={
                                        table.getVisibleLeafColumns().length
                                    }
                                >
                                    <div className="flex flex-col items-center justify-center gap-2 text-muted-foreground">
                                        <p className="text-sm">
                                            {isFiltered
                                                ? copy.noCategoriesMatching
                                                : copy.noCategories}
                                        </p>
                                        {isFiltered ? (
                                            <Button
                                                className="min-h-11 text-xs"
                                                onClick={onResetFilters}
                                                type="button"
                                                variant="outline"
                                            >
                                                {copy.resetFilters}
                                            </Button>
                                        ) : null}
                                    </div>
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>

            <div
                aria-label={copy.tableLabel}
                className="flex flex-col gap-3 md:hidden"
                role="list"
            >
                {rows.length > 0 ? (
                    rows.map((row) => (
                        <AdminCategoriesMobileCard
                            adminUi={adminUi}
                            canManage={canManage}
                            dateFormatter={dateFormatter}
                            key={row.id}
                            locale={locale}
                            onToggleVisibility={onToggleVisibility}
                            row={row}
                        />
                    ))
                ) : (
                    <div className="rounded-lg border border-border bg-card p-6 text-center text-muted-foreground">
                        <p className="text-sm">
                            {isFiltered
                                ? copy.noCategoriesMatching
                                : copy.noCategories}
                        </p>
                        {isFiltered ? (
                            <Button
                                className="mt-3 min-h-11 text-xs"
                                onClick={onResetFilters}
                                type="button"
                                variant="outline"
                            >
                                {copy.resetFilters}
                            </Button>
                        ) : null}
                    </div>
                )}
            </div>
        </div>
    );
}

function ariaSort(
    columnId: string,
    currentSort: CategorySortKey,
    currentDirection: 'asc' | 'desc',
): 'ascending' | 'descending' | 'none' {
    const columnToSortKey: Record<string, CategorySortKey> = {
        name: 'name',
        sortOrder: 'sort_order',
        updatedAt: 'updated_at',
    };

    const sortKey = columnToSortKey[columnId];

    if (sortKey && sortKey === currentSort) {
        return currentDirection === 'asc' ? 'ascending' : 'descending';
    }

    return 'none';
}
