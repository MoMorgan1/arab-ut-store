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
import { hasActiveProductFilters } from '@/lib/admin-products-query';
import type {
    AdminFilterOption,
    AdminProductRow,
    AdminProductsQueryState,
    AdminTranslations,
} from '@/types/admin';

export type AdminProductsToolbarProps = {
    adminUi: AdminTranslations;
    filterOptions: {
        services: AdminFilterOption[];
        authorities: AdminFilterOption[];
        sources: AdminFilterOption[];
        visibilities: AdminFilterOption[];
        archived: AdminFilterOption[];
    };
    filters: AdminProductsQueryState;
    isNavigating: boolean;
    onFilterChange: (filters: Partial<AdminProductsQueryState>) => void;
    onResetFilters: () => void;
    table: Table<AdminProductRow>;
};

export default function AdminProductsToolbar({
    adminUi,
    filterOptions,
    filters,
    isNavigating,
    onFilterChange,
    onResetFilters,
    table,
}: AdminProductsToolbarProps) {
    const copy = adminUi.products;
    const [search, setSearch] = useState(filters.search ?? '');
    const columnLabels: Record<string, string> = {
        actions: copy.actions,
        authority: copy.authority,
        createdAt: copy.createdAt,
        isVisible: copy.visibility,
        name: copy.product,
        product: copy.product,
        service: copy.service,
        serviceType: copy.service,
        sortOrder: copy.sortOrder,
        source: copy.source,
        updatedAt: copy.updatedAt,
        variantsCount: copy.variantsCount,
        visibility: copy.visibility,
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

    if (filters.service_type) {
        const option = filterOptions.services.find(
            (o) => o.value === filters.service_type,
        );
        activeChips.push({
            key: 'service_type',
            label: `Service: ${option?.label ?? filters.service_type}`,
            name: 'service',
            onClear: () => onFilterChange({ service_type: null }),
        });
    }

    if (filters.authority) {
        const option = filterOptions.authorities.find(
            (o) => o.value === filters.authority,
        );
        activeChips.push({
            key: 'authority',
            label: `Authority: ${option?.label ?? filters.authority}`,
            name: 'authority',
            onClear: () => onFilterChange({ authority: null }),
        });
    }

    if (filters.source) {
        const option = filterOptions.sources.find(
            (o) => o.value === filters.source,
        );
        activeChips.push({
            key: 'source',
            label: `Source: ${option?.label ?? filters.source}`,
            name: 'source',
            onClear: () => onFilterChange({ source: null }),
        });
    }

    if (filters.visibility) {
        const option = filterOptions.visibilities.find(
            (o) => o.value === filters.visibility,
        );
        activeChips.push({
            key: 'visibility',
            label: `Visibility: ${option?.label ?? filters.visibility}`,
            name: 'visibility',
            onClear: () => onFilterChange({ visibility: null }),
        });
    }

    if (filters.archived && filters.archived !== 'active') {
        const option = filterOptions.archived.find(
            (o) => o.value === filters.archived,
        );
        activeChips.push({
            key: 'archived',
            label: `Archived: ${option?.label ?? filters.archived}`,
            name: 'archived',
            onClear: () => onFilterChange({ archived: null }),
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

                <FilterSelect
                    allLabel={copy.allServices}
                    disabled={isNavigating}
                    label={copy.filterService}
                    onChange={(service_type) =>
                        onFilterChange({ service_type })
                    }
                    options={filterOptions.services}
                    value={filters.service_type ?? null}
                />

                <FilterSelect
                    allLabel={copy.allAuthorities}
                    disabled={isNavigating}
                    label={copy.filterAuthority}
                    onChange={(authority) =>
                        onFilterChange({
                            authority:
                                (authority as 'manual' | 'automation') || null,
                        })
                    }
                    options={filterOptions.authorities}
                    value={filters.authority ?? null}
                />

                <FilterSelect
                    allLabel={copy.allSources}
                    disabled={isNavigating}
                    label={copy.filterSource}
                    onChange={(source) => onFilterChange({ source })}
                    options={filterOptions.sources}
                    value={filters.source ?? null}
                />

                <FilterSelect
                    allLabel={copy.allVisibilities}
                    disabled={isNavigating}
                    label={copy.filterVisibility}
                    onChange={(visibility) =>
                        onFilterChange({
                            visibility:
                                (visibility as 'visible' | 'hidden') || null,
                        })
                    }
                    options={filterOptions.visibilities}
                    value={filters.visibility ?? null}
                />

                <FilterSelect
                    allLabel={copy.allArchived}
                    disabled={isNavigating}
                    label={copy.filterArchived}
                    onChange={(archived) =>
                        onFilterChange({
                            archived:
                                (archived as 'active' | 'archived') || null,
                        })
                    }
                    options={filterOptions.archived}
                    value={filters.archived ?? null}
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

                {hasActiveProductFilters(filters) ? (
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
