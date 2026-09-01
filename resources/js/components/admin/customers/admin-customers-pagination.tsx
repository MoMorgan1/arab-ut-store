'use no memo'; // TanStack Table exposes a mutable table object.

import type { Table } from '@tanstack/react-table';
import {
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
} from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    AdminCustomerRow,
    AdminPagination,
    AdminTranslations,
} from '@/types/admin';

export type AdminCustomersPaginationProps = {
    adminUi: AdminTranslations;
    direction: 'rtl' | 'ltr';
    isNavigating: boolean;
    onPageChange: (page: number) => void;
    onPerPageChange: (perPage: 15 | 25 | 50 | 100) => void;
    pagination: AdminPagination;
    perPageOptions: number[];
    table: Table<AdminCustomerRow>;
};

export default function AdminCustomersPagination({
    adminUi,
    direction,
    isNavigating,
    onPageChange,
    onPerPageChange,
    pagination,
    perPageOptions,
    table,
}: AdminCustomersPaginationProps) {
    const copy = adminUi.customers;
    const selectedCount = table.getSelectedRowModel().rows.length;
    const pageRowsCount = table.getRowModel().rows.length;

    const { currentPage, lastPage, perPage, total, from, to } = pagination;

    const canPreviousPage = currentPage > 1;
    const canNextPage = currentPage < lastPage;

    const isRtl = direction === 'rtl';
    const FirstIcon = isRtl ? ChevronsRight : ChevronsLeft;
    const PreviousIcon = isRtl ? ChevronRight : ChevronLeft;
    const NextIcon = isRtl ? ChevronLeft : ChevronRight;
    const LastIcon = isRtl ? ChevronsLeft : ChevronsRight;

    return (
        <div className="flex flex-wrap items-center justify-between gap-4 px-2 py-3 text-sm text-muted-foreground">
            <div className="flex-1 text-xs">
                {selectedCount > 0 ? (
                    <span>
                        {copy.selectedRows
                            .replace(':count', String(selectedCount))
                            .replace(':total', String(pageRowsCount))}
                    </span>
                ) : (
                    <span>
                        {total > 0 && from !== null && to !== null ? (
                            <>
                                {copy.showing}{' '}
                                <strong className="text-foreground tabular-nums">
                                    {from}
                                </strong>{' '}
                                {copy.to}{' '}
                                <strong className="text-foreground tabular-nums">
                                    {to}
                                </strong>{' '}
                                {copy.of}{' '}
                                <strong className="text-foreground tabular-nums">
                                    {total}
                                </strong>{' '}
                                {copy.results}
                            </>
                        ) : null}
                    </span>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-6">
                <div className="flex items-center gap-2">
                    <span className="text-xs">{copy.perPage}</span>
                    <Select
                        disabled={isNavigating}
                        onValueChange={(val) =>
                            onPerPageChange(Number(val) as 15 | 25 | 50 | 100)
                        }
                        value={String(perPage)}
                    >
                        <SelectTrigger
                            aria-label={copy.perPage}
                            className="h-9 min-h-[44px] w-[70px]"
                        >
                            <SelectValue placeholder={String(perPage)} />
                        </SelectTrigger>
                        <SelectContent
                            className="motion-reduce:animate-none"
                            side="top"
                        >
                            {perPageOptions.map((opt) => (
                                <SelectItem
                                    className="min-h-11"
                                    key={opt}
                                    value={String(opt)}
                                >
                                    {opt}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div
                    aria-live="polite"
                    className="flex items-center justify-center text-xs text-foreground"
                >
                    {copy.page} <strong className="mx-1">{currentPage}</strong>{' '}
                    {copy.of}{' '}
                    <strong className="mx-1">{Math.max(1, lastPage)}</strong>
                </div>

                <div className="flex items-center gap-1">
                    <Button
                        aria-label={copy.firstPage}
                        className="h-9 min-h-[44px] w-9 min-w-[44px] p-0"
                        disabled={!canPreviousPage || isNavigating}
                        onClick={() => onPageChange(1)}
                        type="button"
                        variant="outline"
                    >
                        <FirstIcon aria-hidden="true" className="h-4 w-4" />
                    </Button>
                    <Button
                        aria-label={copy.previous}
                        className="h-9 min-h-[44px] w-9 min-w-[44px] p-0"
                        disabled={!canPreviousPage || isNavigating}
                        onClick={() => onPageChange(currentPage - 1)}
                        type="button"
                        variant="outline"
                    >
                        <PreviousIcon aria-hidden="true" className="h-4 w-4" />
                    </Button>
                    <Button
                        aria-label={copy.next}
                        className="h-9 min-h-[44px] w-9 min-w-[44px] p-0"
                        disabled={!canNextPage || isNavigating}
                        onClick={() => onPageChange(currentPage + 1)}
                        type="button"
                        variant="outline"
                    >
                        <NextIcon aria-hidden="true" className="h-4 w-4" />
                    </Button>
                    <Button
                        aria-label={copy.lastPage}
                        className="h-9 min-h-[44px] w-9 min-w-[44px] p-0"
                        disabled={!canNextPage || isNavigating}
                        onClick={() => onPageChange(lastPage)}
                        type="button"
                        variant="outline"
                    >
                        <LastIcon aria-hidden="true" className="h-4 w-4" />
                    </Button>
                </div>
            </div>
        </div>
    );
}
