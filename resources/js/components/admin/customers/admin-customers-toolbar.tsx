'use no memo'; // TanStack Table exposes a mutable table object.

import type { Table } from '@tanstack/react-table';
import { Columns3, Search, X } from 'lucide-react';
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
                                className="absolute end-0 top-0 inline-flex size-11 items-center justify-center rounded-md text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none md:size-9"
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

                <FilterSelect
                    allLabel={copy.allStatuses}
                    disabled={isNavigating}
                    label={copy.filterStatus}
                    onChange={(status) =>
                        onFilterChange({
                            status: (status as 'active' | 'suspended') || null,
                        })
                    }
                    options={filterOptions.statuses}
                    value={filters.status ?? null}
                />

                <DateFilter
                    disabled={isNavigating}
                    id="admin-customers-date-from"
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
                    id="admin-customers-date-to"
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
                            <Columns3 aria-hidden="true" className="size-4" />
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

                {hasActiveCustomerFilters(filters) ? (
                    <Button
                        className="min-h-11 text-sm md:text-xs"
                        disabled={isNavigating}
                        onClick={onResetFilters}
                        type="button"
                        variant="ghost"
                    >
                        {copy.resetFilters}
                    </Button>
                ) : null}
            </div>

            {activeChips.length > 0 ? (
                <div
                    aria-label="Active filters"
                    className="flex flex-wrap items-center gap-1.5 pt-1"
                >
                    {activeChips.map((chip) => (
                        <span
                            className="inline-flex items-center gap-1 rounded-md border border-border bg-muted/60 px-2 py-1 text-xs text-foreground"
                            key={chip.key}
                        >
                            <span>{chip.label}</span>
                            <button
                                aria-label={`Remove filter ${chip.name}`}
                                className="inline-flex size-4 items-center justify-center rounded-xs hover:bg-accent hover:text-accent-foreground focus-visible:outline-2 focus-visible:outline-ring"
                                disabled={isNavigating}
                                onClick={chip.onClear}
                                type="button"
                            >
                                <X aria-hidden="true" className="size-3" />
                            </button>
                        </span>
                    ))}
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
    value: string | null | undefined;
}) {
    return (
        <Select
            disabled={disabled}
            onValueChange={(nextValue) =>
                onChange(nextValue === '__ALL__' ? null : nextValue)
            }
            value={value ?? '__ALL__'}
        >
            <SelectTrigger
                aria-label={label}
                className="min-h-11 w-full text-sm md:w-auto md:min-w-[130px] md:text-xs"
            >
                <SelectValue placeholder={label} />
            </SelectTrigger>
            <SelectContent className="motion-reduce:animate-none">
                <SelectItem
                    className="min-h-11 text-sm md:min-h-8 md:text-xs"
                    value="__ALL__"
                >
                    {allLabel}
                </SelectItem>
                {options.map((option) => (
                    <SelectItem
                        className="min-h-11 text-sm md:min-h-8 md:text-xs"
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
    value: string | null | undefined;
}) {
    return (
        <div className="flex items-center gap-1.5">
            <label className="sr-only" htmlFor={id}>
                {label}
            </label>
            <Input
                aria-label={label}
                className="min-h-11 text-sm md:w-36 md:text-xs"
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
