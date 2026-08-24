'use no memo'; // TanStack Table exposes a mutable table object.

import { Link } from '@inertiajs/react';
import type { Table } from '@tanstack/react-table';
import { Columns3, Package, Search, SlidersHorizontal, X } from 'lucide-react';
import React, { useState } from 'react';
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
import { hasActiveCategoryFilters } from '@/lib/admin-categories-query';
import type {
    AdminCategoriesQueryState,
    AdminCategoryRow,
    AdminFilterOption,
    AdminTranslations,
} from '@/types/admin';

export type AdminCategoriesToolbarProps = {
    adminUi: AdminTranslations;
    filterOptions: {
        sources: AdminFilterOption[];
        visibilities: AdminFilterOption[];
    };
    filters: AdminCategoriesQueryState;
    isNavigating: boolean;
    onFilterChange: (filters: Partial<AdminCategoriesQueryState>) => void;
    onResetFilters: () => void;
    productsUrl?: string;
    table: Table<AdminCategoryRow>;
};

export default function AdminCategoriesToolbar({
    adminUi,
    filterOptions,
    filters,
    isNavigating,
    onFilterChange,
    onResetFilters,
    productsUrl,
    table,
}: AdminCategoriesToolbarProps) {
    const copy = adminUi.categories;
    const [search, setSearch] = useState(filters.search ?? '');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [draftFilters, setDraftFilters] = useState<{
        source?: string | null;
        visibility?: 'visible' | 'admin_hidden' | 'automation_hidden' | null;
    }>({
        source: filters.source,
        visibility: filters.visibility,
    });

    const columnLabels: Record<string, string> = {
        actions: copy.actions,
        createdAt: copy.createdAt,
        name: copy.name,
        productsCount: copy.products,
        sortOrder: copy.sortOrder,
        source: copy.source,
        updatedAt: copy.updatedAt,
        visibility: copy.status,
        visibleProductsCount: copy.visibleProducts,
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
            source: filters.source,
            visibility: filters.visibility,
        });
        setSheetOpen(true);
    };

    const handleApplySheet = () => {
        const hasChanges =
            (draftFilters.source ?? null) !== (filters.source ?? null) ||
            (draftFilters.visibility ?? null) !== (filters.visibility ?? null);

        if (hasChanges) {
            onFilterChange(draftFilters);
        }

        setSheetOpen(false);
    };

    const handleClearAllSheet = () => {
        onResetFilters();
        setSheetOpen(false);
    };

    const activeFilterCount = [filters.visibility, filters.source].filter(
        (val) => val !== null && val !== undefined && val !== '',
    ).length;

    const activeChips: Array<{
        key: string;
        label: string;
        name: string;
        onClear: () => void;
    }> = [];

    if (filters.search && filters.search.trim() !== '') {
        activeChips.push({
            key: 'search',
            label: `${copy.searchButton}: "${filters.search.trim()}"`,
            name: 'search',
            onClear: clearSearch,
        });
    }

    if (filters.visibility) {
        const option = filterOptions.visibilities.find(
            (o) => o.value === filters.visibility,
        );
        activeChips.push({
            key: 'visibility',
            label: `${copy.status}: ${option?.label ?? filters.visibility}`,
            name: 'visibility',
            onClear: () => onFilterChange({ visibility: null }),
        });
    }

    if (filters.source) {
        const option = filterOptions.sources.find(
            (o) => o.value === filters.source,
        );
        activeChips.push({
            key: 'source',
            label: `${copy.source}: ${option?.label ?? filters.source}`,
            name: 'source',
            onClear: () => onFilterChange({ source: null }),
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
                        allLabel={copy.allVisibilities}
                        disabled={isNavigating}
                        label={copy.filterVisibility}
                        onChange={(visibility) =>
                            onFilterChange({
                                visibility:
                                    (visibility as
                                        | 'visible'
                                        | 'admin_hidden'
                                        | 'automation_hidden') || null,
                            })
                        }
                        options={filterOptions.visibilities}
                        value={filters.visibility}
                    />
                    <FilterSelect
                        allLabel={copy.allSources}
                        disabled={isNavigating}
                        label={copy.filterSource}
                        onChange={(source) => onFilterChange({ source })}
                        options={filterOptions.sources}
                        value={filters.source}
                    />
                </div>

                <div className="grid grid-cols-2 gap-2 md:flex md:items-center">
                    {productsUrl ? (
                        <Link
                            aria-label={copy.backToProducts}
                            className="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-md border border-border bg-background px-3 text-sm font-medium text-foreground transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring md:text-xs"
                            href={productsUrl}
                        >
                            <Package aria-hidden="true" className="size-4" />
                            <span>{copy.backToProducts}</span>
                        </Link>
                    ) : null}

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
                            {copy.filterVisibility}
                        </SheetDescription>
                    </SheetHeader>
                    <div className="grid grid-cols-1 gap-3 p-4 pt-2 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5 md:hidden">
                            <span className="text-xs font-medium text-muted-foreground">
                                {copy.filterVisibility}
                            </span>
                            <FilterSelect
                                allLabel={copy.allVisibilities}
                                disabled={isNavigating}
                                label={copy.filterVisibility}
                                onChange={(visibility) =>
                                    setDraftFilters((prev) => ({
                                        ...prev,
                                        visibility:
                                            (visibility as
                                                | 'visible'
                                                | 'admin_hidden'
                                                | 'automation_hidden') || null,
                                    }))
                                }
                                options={filterOptions.visibilities}
                                value={draftFilters.visibility}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5 md:hidden">
                            <span className="text-xs font-medium text-muted-foreground">
                                {copy.filterSource}
                            </span>
                            <FilterSelect
                                allLabel={copy.allSources}
                                disabled={isNavigating}
                                label={copy.filterSource}
                                onChange={(source) =>
                                    setDraftFilters((prev) => ({
                                        ...prev,
                                        source,
                                    }))
                                }
                                options={filterOptions.sources}
                                value={draftFilters.source}
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

            {hasActiveCategoryFilters(filters) && activeChips.length > 0 ? (
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
