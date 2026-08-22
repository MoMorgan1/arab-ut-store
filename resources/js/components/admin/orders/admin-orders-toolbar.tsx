'use no memo'; // TanStack Table exposes a mutable table object.

import type { Table } from '@tanstack/react-table';
import { Columns3, RotateCcw, Search, X } from 'lucide-react';
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

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <form
                    className="grid w-full grid-cols-[minmax(0,1fr)_auto] gap-2 sm:max-w-md"
                    onSubmit={submitSearch}
                    role="search"
                >
                    <div className="relative min-w-0">
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            aria-label={copy.searchLabel}
                            className="min-h-11 ps-9 pe-12"
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
                        className="min-h-11 shrink-0"
                        disabled={isNavigating}
                        type="submit"
                        variant="secondary"
                    >
                        {copy.searchButton}
                    </Button>
                </form>

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            aria-label={copy.toggleColumns}
                            className="ms-auto min-h-11"
                            disabled={isNavigating}
                            variant="outline"
                        >
                            <Columns3 aria-hidden="true" className="size-4" />
                            {copy.columns}
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
                                    className="min-h-12"
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

            <div className="flex flex-wrap items-center gap-2">
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
                {hasActiveFilters(filters) ? (
                    <Button
                        className="min-h-11 gap-1 px-3 text-xs"
                        disabled={isNavigating}
                        onClick={onResetFilters}
                        type="button"
                        variant="ghost"
                    >
                        <RotateCcw aria-hidden="true" className="size-3.5" />
                        {copy.resetFilters}
                    </Button>
                ) : null}
            </div>
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
                className="min-h-11 w-full min-[480px]:w-44"
            >
                <SelectValue placeholder={allLabel} />
            </SelectTrigger>
            <SelectContent className="motion-reduce:animate-none">
                <SelectItem className="min-h-11" value="ALL">
                    {allLabel}
                </SelectItem>
                {options.map((option) => (
                    <SelectItem
                        className="min-h-11"
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
                className="min-h-11 w-full min-[480px]:w-40"
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
