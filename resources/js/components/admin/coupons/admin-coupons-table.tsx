'use no memo'; // TanStack Table exposes mutable row and table objects.

import { flexRender } from '@tanstack/react-table';
import type { Table as TanStackTable } from '@tanstack/react-table';
import { LoaderCircle } from 'lucide-react';

import AdminCouponsMobileCard from '@/components/admin/coupons/admin-coupons-mobile-card';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { AdminCouponRow, AdminTranslations } from '@/types/admin';

export type AdminCouponsTableProps = {
    adminUi: AdminTranslations;
    isFiltered: boolean;
    isNavigating: boolean;
    locale: 'ar' | 'en';
    onDuplicate: (coupon: AdminCouponRow) => void;
    onEdit: (coupon: AdminCouponRow) => void;
    onResetFilters: () => void;
    onToggle: (coupon: AdminCouponRow, targetActive: boolean) => void;
    permissions: string[];
    showUrlTemplate: string;
    table: TanStackTable<AdminCouponRow>;
};

export default function AdminCouponsTable({
    adminUi,
    isFiltered,
    isNavigating,
    locale,
    onDuplicate,
    onEdit,
    onResetFilters,
    onToggle,
    permissions,
    showUrlTemplate,
    table,
}: AdminCouponsTableProps) {
    const copy = adminUi.coupons;
    const rows = table.getRowModel().rows;

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
                        <span>{adminUi.coupons.loading}</span>
                    </div>
                </div>
            ) : null}

            {/* Desktop Table */}
            <div
                aria-label={copy.title}
                className="hidden rounded-xl border border-border bg-card shadow-xs md:block"
                role="region"
            >
                <Table>
                    <TableHeader>
                        {table.getHeaderGroups().map((headerGroup) => (
                            <TableRow key={headerGroup.id}>
                                {headerGroup.headers.map((header) => (
                                    <TableHead key={header.id}>
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
                                <TableRow key={row.id}>
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
                                                ? copy.noCouponsMatching
                                                : copy.noCoupons}
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

            {/* Mobile Card List */}
            <div
                aria-label={copy.title}
                className="flex flex-col gap-3 md:hidden"
                role="list"
            >
                {rows.length > 0 ? (
                    rows.map((row) => (
                        <AdminCouponsMobileCard
                            adminUi={adminUi}
                            key={row.id}
                            locale={locale}
                            onDuplicate={onDuplicate}
                            onEdit={onEdit}
                            onToggle={onToggle}
                            permissions={permissions}
                            row={row}
                            showUrlTemplate={showUrlTemplate}
                        />
                    ))
                ) : (
                    <div className="rounded-xl border border-border bg-card p-6 text-center text-muted-foreground">
                        <p className="text-sm">
                            {isFiltered
                                ? copy.noCouponsMatching
                                : copy.noCoupons}
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
