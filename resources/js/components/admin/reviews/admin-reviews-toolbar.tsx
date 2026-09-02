import { Search, X } from 'lucide-react';
import React, { useState } from 'react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    AdminFilterOption,
    AdminReviewsQueryState,
    AdminTranslations,
} from '@/types/admin';

export type AdminReviewsToolbarProps = {
    adminUi: AdminTranslations;
    filterOptions: {
        ratings: AdminFilterOption[];
        sources: AdminFilterOption[];
        statuses: AdminFilterOption[];
    };
    filters: AdminReviewsQueryState;
    isNavigating: boolean;
    onFilterChange: (filters: Partial<AdminReviewsQueryState>) => void;
    onResetFilters: () => void;
};

export default function AdminReviewsToolbar({
    adminUi,
    filterOptions,
    filters,
    isNavigating,
    onFilterChange,
    onResetFilters,
}: AdminReviewsToolbarProps) {
    const copy = adminUi.reviews;
    const [search, setSearch] = useState(filters.search ?? '');

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        onFilterChange({ search: search.trim() || null });
    };

    const clearSearch = () => {
        setSearch('');
        onFilterChange({ search: null });
    };

    const hasActiveFilters =
        (filters.search ?? '') !== '' ||
        (filters.status ?? 'all') !== 'all' ||
        (filters.rating ?? 'all') !== 'all' ||
        (filters.source ?? 'all') !== 'all';

    return (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <form
                className="flex w-full items-end gap-2 lg:max-w-md"
                onSubmit={submitSearch}
                role="search"
            >
                <div className="flex w-full flex-col gap-1.5">
                    <label
                        className="text-xs font-medium text-muted-foreground"
                        htmlFor="admin-reviews-search"
                    >
                        {copy.searchLabel}
                    </label>
                    <Input
                        className="min-h-11"
                        id="admin-reviews-search"
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder={copy.searchPlaceholder}
                        type="search"
                        value={search}
                    />
                </div>
                <Button
                    className="min-h-11 min-w-11 shrink-0"
                    disabled={isNavigating}
                    type="submit"
                    variant="outline"
                >
                    <Search aria-hidden="true" className="size-4" />
                    <span>{copy.searchButton}</span>
                </Button>
                {(filters.search ?? '') !== '' ? (
                    <Button
                        aria-label={copy.clearSearch}
                        className="size-11 shrink-0 p-0"
                        onClick={clearSearch}
                        type="button"
                        variant="ghost"
                    >
                        <X aria-hidden="true" className="size-4" />
                    </Button>
                ) : null}
            </form>

            <div className="flex flex-wrap items-end gap-2">
                <ToolbarSelect
                    disabled={isNavigating}
                    label={copy.filterStatus}
                    name="admin-reviews-status"
                    onChange={(value) =>
                        onFilterChange({
                            status: value as AdminReviewsQueryState['status'],
                        })
                    }
                    options={filterOptions.statuses}
                    value={filters.status ?? 'all'}
                />
                <ToolbarSelect
                    disabled={isNavigating}
                    label={copy.filterRating}
                    name="admin-reviews-rating"
                    onChange={(value) =>
                        onFilterChange({
                            rating: value as AdminReviewsQueryState['rating'],
                        })
                    }
                    options={filterOptions.ratings}
                    value={filters.rating ?? 'all'}
                />
                <ToolbarSelect
                    disabled={isNavigating}
                    label={copy.filterSource}
                    name="admin-reviews-source"
                    onChange={(value) =>
                        onFilterChange({
                            source: value as AdminReviewsQueryState['source'],
                        })
                    }
                    options={filterOptions.sources}
                    value={filters.source ?? 'all'}
                />
                {hasActiveFilters ? (
                    <Button
                        className="min-h-11 text-xs"
                        onClick={onResetFilters}
                        type="button"
                        variant="ghost"
                    >
                        {copy.resetFilters}
                    </Button>
                ) : null}
            </div>
        </div>
    );
}

function ToolbarSelect({
    disabled,
    label,
    name,
    onChange,
    options,
    value,
}: {
    disabled: boolean;
    label: string;
    name: string;
    onChange: (value: string) => void;
    options: AdminFilterOption[];
    value: string;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <span
                className="text-xs font-medium text-muted-foreground"
                id={name}
            >
                {label}
            </span>
            <Select disabled={disabled} onValueChange={onChange} value={value}>
                <SelectTrigger
                    aria-label={label}
                    className="min-h-11 min-w-[150px] text-sm md:text-xs"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent className="motion-reduce:animate-none">
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
        </div>
    );
}
