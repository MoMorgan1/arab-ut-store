'use no memo'; // TanStack Table exposes a mutable table object.

import type { Table } from '@tanstack/react-table';
import {
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
} from 'lucide-react';
import React from 'react';

import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    AdminCategoryRow,
    AdminPagination,
    AdminTranslations,
} from '@/types/admin';

export type AdminCategoriesPaginationProps = {
    adminUi: AdminTranslations;
    direction: 'rtl' | 'ltr';
    isNavigating: boolean;
    onPageChange: (page: number) => void;
    onPerPageChange: (perPage: 15 | 25 | 50 | 100) => void;
    pagination: AdminPagination;
    perPageOptions: number[];
    table: Table<AdminCategoryRow>;
};

export default function AdminCategoriesPagination({
    adminUi,
    direction,
    isNavigating,
    onPageChange,
    onPerPageChange,
    pagination,
    perPageOptions,
    table,
}: AdminCategoriesPaginationProps) {
    const copy = adminUi.categories;
    const { currentPage, lastPage, perPage, total, from, to } = pagination;
    const selectedCount = table.getFilteredSelectedRowModel().rows.length;
    const totalCount = table.getFilteredRowModel().rows.length;

    const canPrevious = currentPage > 1 && !isNavigating;
    const canNext = currentPage < lastPage && !isNavigating;

    const isRtl = direction === 'rtl';
    const FirstIcon = isRtl ? ChevronsRight : ChevronsLeft;
    const PreviousIcon = isRtl ? ChevronRight : ChevronLeft;
    const NextIcon = isRtl ? ChevronLeft : ChevronRight;
    const LastIcon = isRtl ? ChevronsLeft : ChevronsRight;

    return (
        <div className="flex flex-col gap-4 pt-2 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
                <span>
                    {copy.selectedRows
                        .replace(':count', String(selectedCount))
                        .replace(':total', String(totalCount))}
                </span>
                {total > 0 && from !== null && to !== null ? (
                    <span className="tabular-nums">
                        {copy.showing} {from} {copy.to} {to} {copy.of} {total}{' '}
                        {copy.results}
                    </span>
                ) : null}
            </div>

            <div className="flex flex-wrap items-center gap-3 sm:gap-4">
                <div className="flex items-center gap-2">
                    <span className="text-xs whitespace-nowrap text-muted-foreground">
                        {copy.perPage}
                    </span>
                    <Select
                        disabled={isNavigating}
                        onValueChange={(value) =>
                            onPerPageChange(Number(value) as 15 | 25 | 50 | 100)
                        }
                        value={String(perPage)}
                    >
                        <SelectTrigger
                            aria-label={copy.perPage}
                            className="min-h-11 min-w-[76px] text-sm md:text-xs"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent className="motion-reduce:animate-none">
                            {perPageOptions.map((option) => (
                                <SelectItem
                                    className="min-h-11 text-sm md:min-h-8 md:text-xs"
                                    key={option}
                                    value={String(option)}
                                >
                                    {option}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="flex items-center gap-1 text-xs text-muted-foreground tabular-nums">
                    <span>
                        {copy.page} {currentPage} {copy.of} {lastPage || 1}
                    </span>
                </div>

                <div className="flex items-center gap-1">
                    <Button
                        aria-label={copy.firstPage}
                        className="size-11 p-0"
                        disabled={!canPrevious}
                        onClick={() => onPageChange(1)}
                        type="button"
                        variant="outline"
                    >
                        <FirstIcon aria-hidden="true" className="size-4" />
                    </Button>
                    <Button
                        aria-label={copy.previous}
                        className="size-11 p-0"
                        disabled={!canPrevious}
                        onClick={() => onPageChange(currentPage - 1)}
                        type="button"
                        variant="outline"
                    >
                        <PreviousIcon aria-hidden="true" className="size-4" />
                    </Button>
                    <Button
                        aria-label={copy.next}
                        className="size-11 p-0"
                        disabled={!canNext}
                        onClick={() => onPageChange(currentPage + 1)}
                        type="button"
                        variant="outline"
                    >
                        <NextIcon aria-hidden="true" className="size-4" />
                    </Button>
                    <Button
                        aria-label={copy.lastPage}
                        className="size-11 p-0"
                        disabled={!canNext}
                        onClick={() => onPageChange(lastPage)}
                        type="button"
                        variant="outline"
                    >
                        <LastIcon aria-hidden="true" className="size-4" />
                    </Button>
                </div>
            </div>
        </div>
    );
}
