'use no memo'; // TanStack Table exposes mutable row and table objects.

import { flexRender } from '@tanstack/react-table';
import type { Table as TanStackTable } from '@tanstack/react-table';
import { LoaderCircle } from 'lucide-react';

import AdminOrdersMobileCard from '@/components/admin/orders/admin-orders-mobile-card';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { AdminOrderRow, AdminTranslations } from '@/types/admin';

type SortKey = 'placed_at' | 'total' | 'order_number';

export type AdminOrdersTableProps = {
    adminUi: AdminTranslations;
    currentDirection: 'asc' | 'desc';
    currentSort: SortKey;
    isFiltered: boolean;
    isNavigating: boolean;
    locale: 'ar' | 'en';
    onResetFilters: () => void;
    table: TanStackTable<AdminOrderRow>;
};

export default function AdminOrdersTable({
    adminUi,
    currentDirection,
    currentSort,
    isFiltered,
    isNavigating,
    locale,
    onResetFilters,
    table,
}: AdminOrdersTableProps) {
    const copy = adminUi.orders;
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
                                    className="data-[state=selected]:bg-primary/5"
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
                                    className="h-36 text-center"
                                    colSpan={
                                        table.getVisibleLeafColumns().length
                                    }
                                >
                                    <EmptyOrders
                                        copy={copy}
                                        isFiltered={isFiltered}
                                        onResetFilters={onResetFilters}
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>

            <div
                aria-label={`${copy.tableLabel} mobile`}
                className="flex flex-col gap-3 md:hidden"
                role="list"
            >
                {rows.length > 0 ? (
                    rows.map((row) => (
                        <AdminOrdersMobileCard
                            adminUi={adminUi}
                            dateFormatter={dateFormatter}
                            key={row.id}
                            locale={locale}
                            row={row}
                        />
                    ))
                ) : (
                    <div className="rounded-lg border border-dashed border-border px-5 py-10">
                        <EmptyOrders
                            copy={copy}
                            isFiltered={isFiltered}
                            onResetFilters={onResetFilters}
                        />
                    </div>
                )}
            </div>
        </div>
    );
}

function ariaSort(
    columnId: string,
    currentSort: SortKey,
    currentDirection: 'asc' | 'desc',
): 'ascending' | 'descending' | undefined {
    if (columnId !== currentSort) {
        return undefined;
    }

    return currentDirection === 'asc' ? 'ascending' : 'descending';
}

function EmptyOrders({
    copy,
    isFiltered,
    onResetFilters,
}: {
    copy: AdminTranslations['orders'];
    isFiltered: boolean;
    onResetFilters: () => void;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 text-center text-muted-foreground">
            <p className="text-sm font-medium">
                {isFiltered ? copy.noOrdersMatching : copy.noOrders}
            </p>
            {isFiltered ? (
                <Button
                    className="min-h-11"
                    onClick={onResetFilters}
                    type="button"
                    variant="outline"
                >
                    {copy.resetFilters}
                </Button>
            ) : null}
        </div>
    );
}
