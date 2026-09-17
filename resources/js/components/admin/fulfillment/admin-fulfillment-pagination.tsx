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
import type { AdminPagination, AdminTranslations } from '@/types/admin';

/**
 * Pagination for a list with no row selection.
 *
 * Not `AdminOrdersPagination`: that one reads a TanStack table for its
 * selected-row count, and this screen has no selection on purpose -
 * `tables.md` refuses a bulk financial action without its own transaction and
 * idempotency design, and every action here spends money or instructs a
 * supplier. Passing a table object only to satisfy a count of zero would be
 * worse than the twenty lines below.
 */
export default function AdminFulfillmentPagination({
    adminUi,
    direction,
    isNavigating,
    onPageChange,
    onPerPageChange,
    pagination,
    perPageOptions,
}: {
    adminUi: AdminTranslations;
    direction: 'ltr' | 'rtl';
    isNavigating: boolean;
    onPageChange: (page: number) => void;
    onPerPageChange: (perPage: number) => void;
    pagination: AdminPagination;
    perPageOptions: number[];
}) {
    const common = adminUi.orders;
    const isRtl = direction === 'rtl';
    const First = isRtl ? ChevronsRight : ChevronsLeft;
    const Previous = isRtl ? ChevronRight : ChevronLeft;
    const Next = isRtl ? ChevronLeft : ChevronRight;
    const Last = isRtl ? ChevronsLeft : ChevronsRight;
    const onFirstPage = pagination.currentPage <= 1;
    const onLastPage = pagination.currentPage >= pagination.lastPage;

    return (
        <div className="flex flex-wrap items-center justify-between gap-4 px-2 py-3 text-sm text-muted-foreground">
            <div className="w-full text-xs whitespace-nowrap md:w-auto md:flex-1">
                <bdi className="tabular-nums">
                    {pagination.from ?? 0}–{pagination.to ?? 0} / {pagination.total}
                </bdi>
            </div>

            <div className="flex w-full flex-wrap items-center justify-between gap-3 md:w-auto md:justify-end md:gap-6">
                <div className="flex items-center gap-2">
                    <span className="text-xs">{common.perPage}</span>
                    <Select
                        onValueChange={(value) => onPerPageChange(Number(value))}
                        value={String(pagination.perPage)}
                    >
                        <SelectTrigger
                            aria-label={common.perPage}
                            className="h-9 min-h-[44px] w-[70px]"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent
                            className="motion-reduce:animate-none"
                            side="top"
                        >
                            {perPageOptions.map((option) => (
                                <SelectItem
                                    className="min-h-11"
                                    key={option}
                                    value={String(option)}
                                >
                                    {option}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div
                    aria-live="polite"
                    className="flex items-center justify-center text-xs text-foreground"
                >
                    <bdi className="tabular-nums">
                        {pagination.currentPage} / {pagination.lastPage}
                    </bdi>
                </div>

                <div className="flex items-center gap-1">
                    <Button
                        aria-label={common.firstPage}
                        className="hidden h-9 min-h-[44px] w-9 min-w-[44px] p-0 md:inline-flex"
                        disabled={onFirstPage || isNavigating}
                        onClick={() => onPageChange(1)}
                        type="button"
                        variant="outline"
                    >
                        <First aria-hidden="true" className="h-4 w-4" />
                    </Button>
                    <Button
                        aria-label={common.previous}
                        className="h-9 min-h-[44px] w-9 min-w-[44px] p-0"
                        disabled={onFirstPage || isNavigating}
                        onClick={() => onPageChange(pagination.currentPage - 1)}
                        type="button"
                        variant="outline"
                    >
                        <Previous aria-hidden="true" className="h-4 w-4" />
                    </Button>
                    <Button
                        aria-label={common.next}
                        className="h-9 min-h-[44px] w-9 min-w-[44px] p-0"
                        disabled={onLastPage || isNavigating}
                        onClick={() => onPageChange(pagination.currentPage + 1)}
                        type="button"
                        variant="outline"
                    >
                        <Next aria-hidden="true" className="h-4 w-4" />
                    </Button>
                    <Button
                        aria-label={common.lastPage}
                        className="hidden h-9 min-h-[44px] w-9 min-w-[44px] p-0 md:inline-flex"
                        disabled={onLastPage || isNavigating}
                        onClick={() => onPageChange(pagination.lastPage)}
                        type="button"
                        variant="outline"
                    >
                        <Last aria-hidden="true" className="h-4 w-4" />
                    </Button>
                </div>
            </div>
        </div>
    );
}
