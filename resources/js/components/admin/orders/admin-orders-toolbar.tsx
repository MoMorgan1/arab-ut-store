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
import { hasActiveFilters } from '@/lib/admin-orders-query';
import type {
    AdminFilterOption,
    AdminOrderRow,
    AdminOrdersQueryState,
    AdminTranslations,
} from '@/types/admin';

export type AdminOrdersToolbarProps = {
    adminUi: AdminTranslations;
    filterOptions: {
        statuses: AdminFilterOption[];
        services: AdminFilterOption[];
        platforms: AdminFilterOption[];
        paymentStatuses: AdminFilterOption[];
    };
    filters: AdminOrdersQueryState;
    isNavigating: boolean;
    onFilterChange: (filters: Partial<AdminOrdersQueryState>) => void;
    onResetFilters: () => void;
    table: Table<AdminOrderRow>;
};

export default function AdminOrdersToolbar({
    adminUi,
    filterOptions,
    filters,
    isNavigating,
    onFilterChange,
    onResetFilters,
    table,
}: AdminOrdersToolbarProps) {
    const copy = adminUi.orders;
    const [search, setSearch] = useState(filters.search ?? '');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [draftFilters, setDraftFilters] = useState<{
        date_from?: string | null;
        date_to?: string | null;
        payment_status?: string | null;
        platform?: string | null;
        service?: string | null;
        status?: string | null;
    }>({
        date_from: filters.date_from,
        date_to: filters.date_to,
        payment_status: filters.payment_status,
        platform: filters.platform,
        service: filters.service,
        status: filters.status,
    });

    const columnLabels: Record<string, string> = {
        customer: copy.customer,
        itemCount: copy.items,
        payment: copy.payment,
        placed_at: copy.placedAt,
        platforms: copy.platform,
        serviceTypes: copy.service,
        status: copy.status,
        total: copy.total,
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
            payment_status: filters.payment_status,
            platform: filters.platform,
            service: filters.service,
            status: filters.status,
        });
        setSheetOpen(true);
    };

    const handleApplySheet = () => {
        onFilterChange(draftFilters);
        setSheetOpen(false);
    };

    const handleClearAllSheet = () => {
        onResetFilters();
        setSheetOpen(false);
    };

    const activeFilterCount = [
        filters.status,
        filters.service,
        filters.platform,
        filters.payment_status,
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

    if (filters.service) {
        const option = filterOptions.services.find(
            (o) => o.value === filters.service,
        );
        activeChips.push({
            key: 'service',
            label: `Service: ${option?.label ?? filters.service}`,
            name: 'service',
            onClear: () => onFilterChange({ service: null }),
        });
    }

    if (filters.platform) {
        const option = filterOptions.platforms.find(
            (o) => o.value === filters.platform,
        );
        activeChips.push({
            key: 'platform',
            label: `Platform: ${option?.label ?? filters.platform}`,
            name: 'platform',
            onClear: () => onFilterChange({ platform: null }),
        });
    }

    if (filters.payment_status) {
        const option = filterOptions.paymentStatuses.find(
            (o) => o.value === filters.payment_status,
        );
        activeChips.push({
            key: 'payment_status',
            label: `Payment: ${option?.label ?? filters.payment_status}`,
            name: 'payment status',
            onClear: () => onFilterChange({ payment_status: null }),
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
            <div className="flex flex-wrap items-center gap-2">
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

                <Button
                    aria-label={copy.filters ?? 'Filters'}
                    className="min-h-11 shrink-0 gap-2 text-sm md:hidden"
                    disabled={isNavigating}
                    onClick={handleOpenSheet}
                    type="button"
                    variant="outline"
                >
                    <SlidersHorizontal aria-hidden="true" className="size-4" />
                    <span>{copy.filters ?? 'Filters'}</span>
                    {activeFilterCount > 0 ? (
                        <span className="inline-flex size-5 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">
                            {activeFilterCount}
                        </span>
                    ) : null}
                </Button>

                <div className="hidden md:flex md:flex-wrap md:items-center md:gap-2">
                    <FilterSelect
                        allLabel={copy.allStatuses}
                        disabled={isNavigating}
                        label={copy.filterStatus}
                        onChange={(status) => onFilterChange({ status })}
                        options={filterOptions.statuses}
                        value={filters.status}
                    />
                    <FilterSelect
                        allLabel={copy.allServices}
                        disabled={isNavigating}
                        label={copy.filterService}
                        onChange={(service) => onFilterChange({ service })}
                        options={filterOptions.services}
                        value={filters.service}
                    />
                    <FilterSelect
                        allLabel={copy.allPlatforms}
                        disabled={isNavigating}
                        label={copy.filterPlatform}
                        onChange={(platform) => onFilterChange({ platform })}
                        options={filterOptions.platforms}
                        value={filters.platform}
                    />
                    <FilterSelect
                        allLabel={copy.allPaymentStatuses}
                        disabled={isNavigating}
                        label={copy.filterPayment}
                        onChange={(payment_status) =>
                            onFilterChange({ payment_status })
                        }
                        options={filterOptions.paymentStatuses}
                        value={filters.payment_status}
                    />
                    <DateFilter
                        disabled={isNavigating}
                        id="admin-orders-date-from"
                        label={copy.dateFrom}
                        onChange={(date_from) => {
                            const conflictsWithDateTo =
                                date_from !== null &&
                                filters.date_to !== null &&
                                filters.date_to !== undefined &&
                                filters.date_to < date_from;

                            onFilterChange(
                                conflictsWithDateTo
                                    ? { date_from, date_to: null }
                                    : { date_from },
                            );
                        }}
                        value={filters.date_from}
                    />
                    <DateFilter
                        disabled={isNavigating}
                        id="admin-orders-date-to"
                        label={copy.dateTo}
                        min={filters.date_from}
                        onChange={(date_to) => onFilterChange({ date_to })}
                        value={filters.date_to}
                    />

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                aria-label={copy.toggleColumns}
                                className="min-h-11 text-sm md:text-xs"
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
                    className="max-h-[85vh] overflow-y-auto rounded-t-xl"
                    side="bottom"
                >
                    <SheetHeader>
                        <SheetTitle>{copy.filters ?? 'Filters'}</SheetTitle>
                        <SheetDescription className="sr-only">
                            Filter customer orders
                        </SheetDescription>
                    </SheetHeader>
                    <div className="grid grid-cols-1 gap-3 p-4 pt-2 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
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
                                        status,
                                    }))
                                }
                                options={filterOptions.statuses}
                                value={draftFilters.status}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <span className="text-xs font-medium text-muted-foreground">
                                {copy.filterService}
                            </span>
                            <FilterSelect
                                allLabel={copy.allServices}
                                disabled={isNavigating}
                                label={copy.filterService}
                                onChange={(service) =>
                                    setDraftFilters((prev) => ({
                                        ...prev,
                                        service,
                                    }))
                                }
                                options={filterOptions.services}
                                value={draftFilters.service}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <span className="text-xs font-medium text-muted-foreground">
                                {copy.filterPlatform}
                            </span>
                            <FilterSelect
                                allLabel={copy.allPlatforms}
                                disabled={isNavigating}
                                label={copy.filterPlatform}
                                onChange={(platform) =>
                                    setDraftFilters((prev) => ({
                                        ...prev,
                                        platform,
                                    }))
                                }
                                options={filterOptions.platforms}
                                value={draftFilters.platform}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <span className="text-xs font-medium text-muted-foreground">
                                {copy.filterPayment}
                            </span>
                            <FilterSelect
                                allLabel={copy.allPaymentStatuses}
                                disabled={isNavigating}
                                label={copy.filterPayment}
                                onChange={(payment_status) =>
                                    setDraftFilters((prev) => ({
                                        ...prev,
                                        payment_status,
                                    }))
                                }
                                options={filterOptions.paymentStatuses}
                                value={draftFilters.payment_status}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <span className="text-xs font-medium text-muted-foreground">
                                {copy.dateFrom}
                            </span>
                            <DateFilter
                                disabled={isNavigating}
                                id="admin-orders-mobile-date-from"
                                label={copy.dateFrom}
                                onChange={(date_from) => {
                                    const conflictsWithDateTo =
                                        date_from !== null &&
                                        draftFilters.date_to !== null &&
                                        draftFilters.date_to !== undefined &&
                                        draftFilters.date_to < date_from;

                                    setDraftFilters((prev) => ({
                                        ...prev,
                                        date_from,
                                        date_to: conflictsWithDateTo
                                            ? null
                                            : prev.date_to,
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
                                id="admin-orders-mobile-date-to"
                                label={copy.dateTo}
                                min={draftFilters.date_from}
                                onChange={(date_to) =>
                                    setDraftFilters((prev) => ({
                                        ...prev,
                                        date_to,
                                    }))
                                }
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
                            {copy.clearAll ?? 'Clear all'}
                        </Button>
                        <Button
                            className="min-h-11 flex-1 text-sm"
                            disabled={isNavigating}
                            onClick={handleApplySheet}
                            type="button"
                        >
                            {copy.apply ?? 'Apply'}
                        </Button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>

            {hasActiveFilters(filters) && activeChips.length > 0 ? (
                <div className="flex flex-wrap items-center gap-1.5 pt-1 text-xs">
                    <span className="font-medium text-muted-foreground">
                        Active filters:
                    </span>
                    {activeChips.map((chip) => (
                        <span
                            className="inline-flex items-center gap-1 rounded-md border border-border bg-muted/40 px-2 py-0.5 text-xs text-foreground"
                            key={chip.key}
                        >
                            <span>{chip.label}</span>
                            <button
                                aria-label={`Clear ${chip.name} filter`}
                                className="inline-flex size-4 items-center justify-center rounded-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                                disabled={isNavigating}
                                onClick={chip.onClear}
                                type="button"
                            >
                                <X aria-hidden="true" className="size-3" />
                            </button>
                        </span>
                    ))}
                    <button
                        className="text-xs font-medium text-primary underline underline-offset-2 transition-colors hover:text-primary/80 focus-visible:outline-2 focus-visible:outline-ring"
                        disabled={isNavigating}
                        onClick={onResetFilters}
                        type="button"
                    >
                        Clear all
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
