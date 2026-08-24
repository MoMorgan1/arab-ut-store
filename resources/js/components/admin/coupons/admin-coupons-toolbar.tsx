'use no memo'; // TanStack Table exposes a mutable table object.

import type { Table } from '@tanstack/react-table';
import { Columns3, Plus, Search, SlidersHorizontal, X } from 'lucide-react';
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
import type {
    AdminCouponRow,
    AdminCouponsPageProps,
    AdminCouponsQueryState,
    AdminFilterOption,
    AdminTranslations,
} from '@/types/admin';

export type AdminCouponsToolbarProps = {
    adminUi: AdminTranslations;
    counts: AdminCouponsPageProps['counts'];
    filterOptions: AdminCouponsPageProps['filterOptions'];
    filters: AdminCouponsQueryState;
    isNavigating: boolean;
    onCreateClick?: () => void;
    onFilterChange: (filters: Partial<AdminCouponsQueryState>) => void;
    onResetFilters: () => void;
    permissions: string[];
    table: Table<AdminCouponRow>;
};

export default function AdminCouponsToolbar({
    adminUi,
    counts,
    filterOptions,
    filters,
    isNavigating,
    onCreateClick,
    onFilterChange,
    onResetFilters,
    permissions,
    table,
}: AdminCouponsToolbarProps) {
    const copy = adminUi.coupons;
    const canManage = permissions.includes('marketing.manage');
    const [search, setSearch] = useState(filters.search ?? '');
    const [sheetOpen, setSheetOpen] = useState(false);

    const [draftFilters, setDraftFilters] = useState<{
        status?: AdminCouponsQueryState['status'];
        scope?: AdminCouponsQueryState['scope'];
        discount_type?: AdminCouponsQueryState['discount_type'];
    }>({
        discount_type: filters.discount_type,
        scope: filters.scope,
        status: filters.status,
    });

    const columnLabels: Record<string, string> = {
        actions: copy.columns.actions,
        code: copy.columns.code,
        discount: copy.columns.discount,
        eligibility: copy.columns.eligibility,
        scope: copy.columns.scope,
        status: copy.columns.status,
        usage: copy.columns.usage,
        window: copy.columns.window,
    };

    const statusTabs: Array<{
        key: NonNullable<AdminCouponsQueryState['status']>;
        label: string;
        count: number;
    }> = [
        { key: 'all', label: copy.statusAll, count: counts.total },
        { key: 'active', label: copy.statusActive, count: counts.active },
        {
            key: 'scheduled',
            label: copy.statusScheduled,
            count: counts.scheduled ?? 0,
        },
        { key: 'paused', label: copy.statusPaused, count: counts.paused ?? 0 },
        {
            key: 'expired',
            label: copy.statusExpired,
            count: counts.expired ?? 0,
        },
        {
            key: 'exhausted',
            label: copy.statusExhausted,
            count: counts.exhausted ?? 0,
        },
    ];

    const currentStatus = filters.status ?? 'all';

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        onFilterChange({ search: search.trim() || null, page: 1 });
    };

    const clearSearch = () => {
        setSearch('');
        onFilterChange({ search: null, page: 1 });
    };

    const handleOpenSheet = () => {
        setDraftFilters({
            discount_type: filters.discount_type,
            scope: filters.scope,
            status: filters.status,
        });
        setSheetOpen(true);
    };

    const handleApplySheet = () => {
        onFilterChange({ ...draftFilters, page: 1 });
        setSheetOpen(false);
    };

    const handleClearAllSheet = () => {
        onResetFilters();
        setSheetOpen(false);
    };

    const activeFilterCount = [
        filters.scope,
        filters.discount_type,
        filters.status && filters.status !== 'all' ? filters.status : null,
    ].filter((val) => val !== null && val !== undefined).length;

    const activeChips: Array<{
        key: string;
        label: string;
        name: string;
        onClear: () => void;
    }> = [];

    if (filters.search && filters.search.trim() !== '') {
        activeChips.push({
            key: 'search',
            label: `${copy.searchLabel}: "${filters.search.trim()}"`,
            name: 'search',
            onClear: clearSearch,
        });
    }

    if (filters.status && filters.status !== 'all') {
        const tab = statusTabs.find((t) => t.key === filters.status);
        activeChips.push({
            key: 'status',
            label: `${copy.filterStatus}: ${tab?.label ?? filters.status}`,
            name: 'status',
            onClear: () => onFilterChange({ status: null, page: 1 }),
        });
    }

    if (filters.scope) {
        const option = filterOptions.scopes.find(
            (o) => o.value === filters.scope,
        );
        activeChips.push({
            key: 'scope',
            label: `${copy.filterScope}: ${option?.label ?? filters.scope}`,
            name: 'scope',
            onClear: () => onFilterChange({ scope: null, page: 1 }),
        });
    }

    if (filters.discount_type) {
        const option = filterOptions.discountTypes.find(
            (o) => o.value === filters.discount_type,
        );
        activeChips.push({
            key: 'discount_type',
            label: `${copy.filterDiscountType}: ${option?.label ?? filters.discount_type}`,
            name: 'discount_type',
            onClear: () => onFilterChange({ discount_type: null, page: 1 }),
        });
    }

    return (
        <div className="flex flex-col gap-4">
            {/* Status Tabs with Counts */}
            <div className="flex items-center gap-1.5 overflow-x-auto border-b border-border pb-2">
                {statusTabs.map((tab) => {
                    const isSelected = currentStatus === tab.key;

                    return (
                        <button
                            key={tab.key}
                            className={`inline-flex min-h-11 min-w-11 items-center gap-2 rounded-lg px-3.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none ${
                                isSelected
                                    ? 'border border-primary/30 bg-primary/15 text-primary'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                            }`}
                            disabled={isNavigating}
                            onClick={() =>
                                onFilterChange({
                                    status: tab.key === 'all' ? null : tab.key,
                                    page: 1,
                                })
                            }
                            type="button"
                        >
                            <span>{tab.label}</span>
                            <span
                                className={`inline-flex items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums ${
                                    isSelected
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted text-muted-foreground'
                                }`}
                            >
                                {tab.count}
                            </span>
                        </button>
                    );
                })}
            </div>

            {/* Toolbar search & controls */}
            <div className="flex flex-col gap-2 md:flex-row md:flex-wrap md:items-center md:justify-between">
                <div className="flex flex-1 flex-col gap-2 min-[480px]:flex-row min-[480px]:items-center">
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
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
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
                            {copy.apply}
                        </Button>
                    </form>

                    <div className="hidden lg:flex lg:items-center lg:gap-2">
                        <FilterSelect
                            allLabel={copy.allScopes}
                            disabled={isNavigating}
                            label={copy.filterScope}
                            onChange={(scope) =>
                                onFilterChange({
                                    scope:
                                        (scope as AdminCouponsQueryState['scope']) ||
                                        null,
                                    page: 1,
                                })
                            }
                            options={filterOptions.scopes}
                            value={filters.scope}
                        />
                        <FilterSelect
                            allLabel={copy.allDiscountTypes}
                            disabled={isNavigating}
                            label={copy.filterDiscountType}
                            onChange={(discount_type) =>
                                onFilterChange({
                                    discount_type:
                                        (discount_type as AdminCouponsQueryState['discount_type']) ||
                                        null,
                                    page: 1,
                                })
                            }
                            options={filterOptions.discountTypes}
                            value={filters.discount_type}
                        />
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <Button
                        aria-label={copy.filters}
                        className="min-h-11 flex-1 gap-2 text-sm sm:flex-initial md:text-xs"
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
                                aria-label={copy.columnsToggle}
                                className="min-h-11 flex-1 gap-2 text-sm sm:flex-initial md:text-xs"
                                disabled={isNavigating}
                                variant="outline"
                            >
                                <Columns3
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                <span>{copy.columnsToggle}</span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            className="w-48 motion-reduce:animate-none"
                        >
                            <DropdownMenuLabel>
                                {copy.columnsToggle}
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            {table
                                .getAllLeafColumns()
                                .filter((column) => column.getCanHide())
                                .map((column) => (
                                    <DropdownMenuCheckboxItem
                                        checked={column.getIsVisible()}
                                        className="min-h-11 text-sm md:text-xs"
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

                    {canManage && onCreateClick ? (
                        <Button
                            className="min-h-11 gap-1.5 text-sm md:text-xs"
                            onClick={onCreateClick}
                            type="button"
                        >
                            <Plus aria-hidden="true" className="size-4" />
                            <span>{copy.createButton}</span>
                        </Button>
                    ) : null}
                </div>
            </div>

            {/* Filter Bottom Sheet */}
            <Sheet onOpenChange={setSheetOpen} open={sheetOpen}>
                <SheetContent
                    className="max-h-[85vh] overflow-y-auto rounded-t-xl motion-reduce:animate-none motion-reduce:transition-none"
                    side="bottom"
                >
                    <SheetHeader>
                        <SheetTitle>{copy.filters}</SheetTitle>
                        <SheetDescription className="sr-only">
                            Filter storefront coupons
                        </SheetDescription>
                    </SheetHeader>
                    <div className="grid grid-cols-1 gap-4 p-4 pt-2 sm:grid-cols-2">
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
                                        status:
                                            (status as AdminCouponsQueryState['status']) ||
                                            null,
                                    }))
                                }
                                options={filterOptions.statuses}
                                value={draftFilters.status}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <span className="text-xs font-medium text-muted-foreground">
                                {copy.filterScope}
                            </span>
                            <FilterSelect
                                allLabel={copy.allScopes}
                                disabled={isNavigating}
                                label={copy.filterScope}
                                onChange={(scope) =>
                                    setDraftFilters((prev) => ({
                                        ...prev,
                                        scope:
                                            (scope as AdminCouponsQueryState['scope']) ||
                                            null,
                                    }))
                                }
                                options={filterOptions.scopes}
                                value={draftFilters.scope}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <span className="text-xs font-medium text-muted-foreground">
                                {copy.filterDiscountType}
                            </span>
                            <FilterSelect
                                allLabel={copy.allDiscountTypes}
                                disabled={isNavigating}
                                label={copy.filterDiscountType}
                                onChange={(discount_type) =>
                                    setDraftFilters((prev) => ({
                                        ...prev,
                                        discount_type:
                                            (discount_type as AdminCouponsQueryState['discount_type']) ||
                                            null,
                                    }))
                                }
                                options={filterOptions.discountTypes}
                                value={draftFilters.discount_type}
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

            {/* Active Chips */}
            {activeChips.length > 0 ? (
                <div
                    aria-label={copy.activeFilters}
                    className="flex items-center gap-1.5 overflow-x-auto pt-1 text-xs md:flex-wrap"
                >
                    <span className="shrink-0 font-medium text-muted-foreground">
                        {copy.activeFilters}:
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
