'use no memo'; // TanStack Table exposes a mutable table object.

import type { Table } from '@tanstack/react-table';
import { Columns3, Search, SlidersHorizontal, X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { hasActiveCustomerFilters } from '@/lib/admin-customers-query';
import type {
    AdminCustomerRow,
    AdminCustomersQueryState,
    AdminFilterOption,
    AdminTranslations,
} from '@/types/admin';

export type AdminCustomersToolbarProps = {
    adminUi: AdminTranslations;
    filterOptions: {
        statuses: AdminFilterOption[];
    };
    filters: AdminCustomersQueryState;
    isNavigating: boolean;
    onFilterChange: (filters: Partial<AdminCustomersQueryState>) => void;
    onResetFilters: () => void;
    table: Table<AdminCustomerRow>;
};

/**
 * A start date after the current end date clears the end date; a new end date
 * before the current start date clears the start date.
 */
export function dateRangePatch(
    field: 'date_from' | 'date_to',
    value: string | null,
    current: { date_from?: string | null; date_to?: string | null },
): { date_from?: string | null; date_to?: string | null } {
    const other = field === 'date_from' ? current.date_to : current.date_from;
    const conflicts =
        value !== null &&
        other !== null &&
        other !== undefined &&
        (field === 'date_from' ? other < value : value < other);

    if (field === 'date_from') {
        return conflicts
            ? { date_from: value, date_to: null }
            : { date_from: value };
    }

    return conflicts ? { date_from: null, date_to: value } : { date_to: value };
}

export default function AdminCustomersToolbar({
    adminUi,
    filterOptions,
    filters,
    isNavigating,
    onFilterChange,
    onResetFilters,
    table,
}: AdminCustomersToolbarProps) {
    const copy = adminUi.customers;
    const [search, setSearch] = useState(filters.search ?? '');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [draftFilters, setDraftFilters] = useState<{
        date_from?: string | null;
        date_to?: string | null;
        status?: 'active' | 'suspended' | null;
    }>({
        date_from: filters.date_from,
        date_to: filters.date_to,
        status: filters.status,
    });

    const columnLabels: Record<string, string> = {
        createdAt: copy.createdAt,
        customer: copy.customer,
        email: copy.email,
        ordersCount: copy.ordersCount,
        phone: copy.phone,
        status: copy.status,
        totalSpent: copy.totalSpent,
        walletBalance: copy.walletBalance,
    };

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        onFilterChange({ search: search.trim() || null });
    };

    const clearSearch = () => {
        setSearch('');
        onFilterChange({ search: null });
    };

    const handleOpenSheet = () => {
        setDraftFilters({
            date_from: filters.date_from,
            date_to: filters.date_to,
            status: filters.status,
        });
        setSheetOpen(true);
    };

    const handleApplySheet = () => {
        const hasChanges =
            (draftFilters.status ?? null) !== (filters.status ?? null) ||
            (draftFilters.date_from ?? null) !== (filters.date_from ?? null) ||
            (draftFilters.date_to ?? null) !== (filters.date_to ?? null);

        if (hasChanges) {
            onFilterChange(draftFilters);
        }

        setSheetOpen(false);
    };

    const handleClearAllSheet = () => {
        onResetFilters();
        setSheetOpen(false);
    };

    const activeFilterCount = [
        filters.status,
        filters.date_from,
        filters.date_to,
    ].filter((val) => val !== null && val !== undefined && val !== '').length;

    const activeChips: Array<{
        key: string;
        label: string;
        name: string;
        onClear: () => void;
    }> = [];

    if (filters.search && filters.search.trim() !== '') {
        activeChips.push({
            key: 'search',
            label: `Search: "${filters.search.trim()}"`,
            name: 'search',
            onClear: clearSearch,
        });
    }

    if (filters.status) {
        const option = filterOptions.statuses.find(
            (o) => o.value === filters.status,
        );
        activeChips.push({
            key: 'status',
            label: `Status: ${option?.label ?? filters.status}`,
            name: 'status',
            onClear: () => onFilterChange({ status: null }),
        });
    }

    if (filters.date_from) {
        activeChips.push({
            key: 'date_from',
            label: `From: ${filters.date_from}`,
            name: 'date from',
            onClear: () => onFilterChange({ date_from: null }),
        });
    }

    if (filters.date_to) {
        activeChips.push({
            key: 'date_to',
            label: `To: ${filters.date_to}`,
            name: 'date to',
            onClear: () => onFilterChange({ date_to: null }),
        });
    }

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-col gap-2 md:flex-row md:flex-wrap md:items-center">
                <form
                    className="flex min-w-[200px] flex-1 items-center gap-2"
                    onSubmit={submitSearch}
                    role="search"
                >
                    <div className="relative min-w-0 flex-1">
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            aria-label={copy.searchLabel}
                            className="min-h-11 ps-9 pe-12 text-sm md:text-xs"
                            disabled={isNavigating}
                            maxLength={100}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={copy.searchPlaceholder}
                            type="search"
                            value={search}
                        />
                        {search ? (
                            <button
                                aria-label={copy.clearSearch}
                                className="absolute end-0 top-0 inline-flex size-11 items-center justify-center rounded-md text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                                onClick={clearSearch}
                                type="button"
                            >
                                <X aria-hidden="true" className="size-4" />
                            </button>
                        ) : null}
                    </div>
                    <Button
                        className="min-h-11 shrink-0 text-sm md:text-xs"
                        disabled={isNavigating}
                        type="submit"
                        variant="secondary"
                    >
                        {copy.searchButton}
                    </Button>
                </form>

                <div className="hidden md:flex md:items-center md:gap-2">
                    <FilterSelect
                        allLabel={copy.allStatuses}
                        disabled={isNavigating}
                        label={copy.filterStatus}
                        onChange={(status) =>
                            onFilterChange({
                                status:
                                    (status as 'active' | 'suspended') || null,
                            })
                        }
                        options={filterOptions.statuses}
                        value={filters.status}
                    />
                </div>

                <div className="grid grid-cols-2 gap-2 md:flex md:items-center">
                    <Button
                        aria-label={copy.filters}
                        className="min-h-11 w-full gap-2 text-sm md:w-auto md:text-xs"
                        disabled={isNavigating}
                        onClick={handleOpenSheet}
                        type="button"
                        variant="outline"
                    >
                        <SlidersHorizontal
                            aria-hidden="true"
                            className="size-4"
                        />
                        <span>{copy.filters}</span>
                        {activeFilterCount > 0 ? (
                            <span className="inline-flex size-5 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">
                                {activeFilterCount}
                            </span>
                        ) : null}
                    </Button>

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                aria-label={copy.toggleColumns}
                                className="min-h-11 w-full gap-2 text-sm md:w-auto md:text-xs"
                                disabled={isNavigating}
                                variant="outline"
                            >
                                <Columns3
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                <span>{copy.columns}</span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            className="w-48 motion-reduce:animate-none"
                        >
                            <DropdownMenuLabel>
                                {copy.toggleColumns}
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            {table
                                .getAllLeafColumns()
                                .filter((column) => column.getCanHide())
                                .map((column) => (
                                    <DropdownMenuCheckboxItem
                                        checked={column.getIsVisible()}
                                        className="min-h-12 text-sm md:text-xs"
                                        key={column.id}
                                        onCheckedChange={(visible) =>
                                            column.toggleVisibility(
                                                Boolean(visible),
                                            )
                                        }
                                    >
                                        {columnLabels[column.id] ?? column.id}
                                    </DropdownMenuCheckboxItem>
                                ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <Sheet onOpenChange={setSheetOpen} open={sheetOpen}>
                <SheetContent
                    className="max-h-[85vh] overflow-y-auto rounded-t-xl motion-reduce:animate-none motion-reduce:transition-none motion-reduce:duration-[0.01ms]"
                    side="bottom"
                >
                    <SheetHeader>
                        <SheetTitle>{copy.filters}</SheetTitle>
                        <SheetDescription className="sr-only">
                            Filter customer accounts
                        </SheetDescription>
                    </SheetHeader>
                    <div className="grid grid-cols-1 gap-3 p-4 pt-2 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5 md:hidden">
                            <span className="text-xs font-medium text-muted-foreground">
                                {copy.filterStatus}
                            </span>
                            <FilterSelect
                                allLabel={copy.allStatuses}
                                disabled={isNavigating}
                                label={copy.filterStatus}
                                onChange={(status) =>
                                    setDraftFilters((prev) => ({
                                        ...prev,
                                        status:
                                            (status as
                                                'active' | 'suspended') || null,
                                    }))
                                }
                                options={filterOptions.statuses}
                                value={draftFilters.status}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <span className="text-xs font-medium text-muted-foreground">
                                {copy.dateFrom}
                            </span>
                            <DateFilter
                                disabled={isNavigating}
                                id="admin-customers-date-from"
                                label={copy.dateFrom}
                                onChange={(date_from) => {
                                    setDraftFilters((prev) => ({
                                        ...prev,
                                        ...dateRangePatch(
                                            'date_from',
                                            date_from,
                                            prev,
                                        ),
                                    }));
                                }}
                                value={draftFilters.date_from}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <span className="text-xs font-medium text-muted-foreground">
                                {copy.dateTo}
                            </span>
                            <DateFilter
                                disabled={isNavigating}
                                id="admin-customers-date-to"
                                label={copy.dateTo}
                                min={draftFilters.date_from}
                                onChange={(date_to) => {
                                    setDraftFilters((prev) => ({
                                        ...prev,
                                        ...dateRangePatch(
                                            'date_to',
                                            date_to,
                                            prev,
                                        ),
                                    }));
                                }}
                                value={draftFilters.date_to}
                            />
                        </div>
                    </div>
                    <SheetFooter className="flex flex-row items-center justify-between gap-3 border-t border-border p-4">
                        <Button
                            className="min-h-11 flex-1 text-sm"
                            disabled={isNavigating}
                            onClick={handleClearAllSheet}
                            type="button"
                            variant="outline"
                        >
                            {copy.clearAll}
                        </Button>
                        <Button
                            className="min-h-11 flex-1 text-sm"
                            disabled={isNavigating}
                            onClick={handleApplySheet}
                            type="button"
                        >
                            {copy.apply}
                        </Button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>

            {hasActiveCustomerFilters(filters) && activeChips.length > 0 ? (
                <div
                    aria-label={copy.activeFilters}
                    className="flex items-center gap-1.5 overflow-x-auto pt-1 text-xs md:flex-wrap"
                >
                    <span className="shrink-0 font-medium text-muted-foreground">
                        Active filters:
                    </span>
                    {activeChips.map((chip) => (
                        <span
                            className="inline-flex shrink-0 items-center gap-1 rounded-md border border-border bg-muted/40 px-2 py-0.5 text-xs text-foreground"
                            key={chip.key}
                        >
                            <span>{chip.label}</span>
                            <button
                                aria-label={copy.clearOneFilter.replace(
                                    ':name',
                                    chip.name,
                                )}
                                className="-my-2 -me-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                                disabled={isNavigating}
                                onClick={chip.onClear}
                                type="button"
                            >
                                <X aria-hidden="true" className="size-3" />
                            </button>
                        </span>
                    ))}
                    <button
                        className="inline-flex min-h-11 shrink-0 items-center px-1 text-xs font-medium text-primary underline underline-offset-2 transition-colors hover:text-primary/80 focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                        disabled={isNavigating}
                        onClick={onResetFilters}
                        type="button"
                    >
                        {copy.clearAll}
                    </button>
                </div>
            ) : null}
        </div>
    );
}

function FilterSelect({
    allLabel,
    disabled,
    label,
    onChange,
    options,
    value,
}: {
    allLabel: string;
    disabled: boolean;
    label: string;
    onChange: (value: string | null) => void;
    options: AdminFilterOption[];
    value?: string | null;
}) {
    return (
        <Select
            disabled={disabled}
            onValueChange={(nextValue) =>
                onChange(nextValue === 'ALL' ? null : nextValue)
            }
            value={value ?? 'ALL'}
        >
            <SelectTrigger
                aria-label={label}
                className="min-h-11 w-full text-sm min-[480px]:w-36 md:text-xs"
            >
                <SelectValue placeholder={allLabel} />
            </SelectTrigger>
            <SelectContent className="motion-reduce:animate-none">
                <SelectItem className="min-h-11 text-sm md:text-xs" value="ALL">
                    {allLabel}
                </SelectItem>
                {options.map((option) => (
                    <SelectItem
                        className="min-h-11 text-sm md:text-xs"
                        key={option.value}
                        value={option.value}
                    >
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function DateFilter({
    disabled,
    id,
    label,
    min,
    onChange,
    value,
}: {
    disabled: boolean;
    id: string;
    label: string;
    min?: string | null;
    onChange: (value: string | null) => void;
    value?: string | null;
}) {
    return (
        <div className="w-full min-[480px]:w-auto">
            <label className="sr-only" htmlFor={id}>
                {label}
            </label>
            <Input
                aria-label={label}
                className="min-h-11 w-full text-sm min-[480px]:w-36 md:text-xs"
                disabled={disabled}
                id={id}
                min={min ?? undefined}
                onChange={(event) => onChange(event.target.value || null)}
                type="date"
                value={value ?? ''}
            />
        </div>
    );
}
