'use no memo'; // TanStack Table exposes mutable row and table objects.

import { flexRender } from '@tanstack/react-table';
import type { Table as TanStackTable } from '@tanstack/react-table';
import { LoaderCircle } from 'lucide-react';

import AdminCustomersMobileCard from '@/components/admin/customers/admin-customers-mobile-card';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { DATE_LOCALE } from '@/lib/date-locale';
import type { AdminCustomerRow, AdminTranslations } from '@/types/admin';

type CustomerSortKey =
    'created_at' | 'name' | 'orders_count' | 'last_order_at' | 'total_spent';

export type AdminCustomersTableProps = {
    adminUi: AdminTranslations;
    currentDirection: 'asc' | 'desc';
    currentSort: CustomerSortKey;
    isFiltered: boolean;
    isNavigating: boolean;
    locale: 'ar' | 'en';
    onResetFilters: () => void;
    table: TanStackTable<AdminCustomerRow>;
};

export default function AdminCustomersTable({
    adminUi,
    currentDirection,
    currentSort,
    isFiltered,
    isNavigating,
    locale,
    onResetFilters,
    table,
}: AdminCustomersTableProps) {
    const copy = adminUi.customers;
    const rows = table.getRowModel().rows;
    const dateFormatter = new Intl.DateTimeFormat(DATE_LOCALE, {
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
                                                ? copy.noCustomersMatching
                                                : copy.noCustomers}
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
                        <AdminCustomersMobileCard
                            adminUi={adminUi}
                            dateFormatter={dateFormatter}
                            key={row.id}
                            locale={locale}
                            row={row}
                        />
                    ))
                ) : (
                    <div className="rounded-lg border border-border bg-card p-6 text-center text-muted-foreground">
                        <p className="text-sm">
                            {isFiltered
                                ? copy.noCustomersMatching
                                : copy.noCustomers}
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
    currentSort: CustomerSortKey,
    currentDirection: 'asc' | 'desc',
): 'ascending' | 'descending' | 'none' {
    const columnToSortKey: Record<string, CustomerSortKey> = {
        createdAt: 'created_at',
        customer: 'name',
        ordersCount: 'orders_count',
        totalSpent: 'total_spent',
    };

    const sortKey = columnToSortKey[columnId];

    if (sortKey && sortKey === currentSort) {
        return currentDirection === 'asc' ? 'ascending' : 'descending';
    }

    return 'none';
}
