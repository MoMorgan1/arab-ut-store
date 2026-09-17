import { Search, SlidersHorizontal, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type {
    AdminFilterOption,
    AdminFulfillmentFilterOptions,
    AdminFulfillmentQueryState,
    AdminTranslations,
} from '@/types/admin';

/** The sentinel a Select uses for "no filter", because a Select cannot hold null. */
const ALL = 'ALL';

export type AdminFulfillmentToolbarProps = {
    adminUi: AdminTranslations;
    filterOptions: AdminFulfillmentFilterOptions;
    filters: AdminFulfillmentQueryState;
    isNavigating: boolean;
    onFilterChange: (next: Partial<AdminFulfillmentQueryState>) => void;
    onResetFilters: () => void;
};

export default function AdminFulfillmentToolbar({
    adminUi,
    filterOptions,
    filters,
    isNavigating,
    onFilterChange,
    onResetFilters,
}: AdminFulfillmentToolbarProps) {
    const copy = adminUi.fulfillment;
    const [search, setSearch] = useState(filters.search ?? '');
    const [sheetOpen, setSheetOpen] = useState(false);

    const chips = activeChips(filters, filterOptions, copy);

    const selects: {
        key: keyof AdminFulfillmentQueryState;
        label: string;
        options: AdminFilterOption[];
    }[] = [
        {
            key: 'supplier',
            label: copy.filterSupplier,
            options: filterOptions.suppliers,
        },
        {
            key: 'status',
            label: copy.filterStatus,
            options: filterOptions.statuses,
        },
        {
            key: 'alarm',
            label: copy.filterAlarm,
            options: filterOptions.alarms,
        },
        {
            key: 'service',
            label: copy.filterService,
            options: filterOptions.services,
        },
        {
            key: 'phase',
            label: copy.filterPhase,
            options: filterOptions.phases,
        },
        { key: 'hold', label: copy.filterHold, options: filterOptions.holds },
    ];

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2">
                <form
                    className="flex min-w-[200px] flex-1 items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onFilterChange({ search: search.trim() || null });
                    }}
                    role="search"
                >
                    <div className="relative min-w-0 flex-1">
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            aria-label={copy.searchLabel}
                            // 1rem on a phone so iOS never zooms the field,
                            // the smaller desktop size only from `md` up.
                            className="min-h-11 ps-9 pe-12 text-sm md:text-xs"
                            maxLength={100}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={copy.searchPlaceholder}
                            type="search"
                            value={search}
                        />
                        {search !== '' ? (
                            <button
                                aria-label={copy.clearSearch}
                                className="absolute end-0 top-0 inline-flex size-11 items-center justify-center rounded-md text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                                onClick={() => {
                                    setSearch('');
                                    onFilterChange({ search: null });
                                }}
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
                    // Shown at every width, not only on a phone: the two date
                    // filters live inside this sheet, so hiding its button on
                    // desktop left `paid_from`/`paid_to` reachable by editing
                    // the URL and no other way.
                    className="min-h-11 shrink-0 gap-2 text-sm"
                    onClick={() => setSheetOpen(true)}
                    type="button"
                    variant="outline"
                >
                    <SlidersHorizontal aria-hidden="true" className="size-4" />
                    <span>{copy.filters}</span>
                    {chips.length > 0 ? (
                        <span className="inline-flex size-5 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">
                            {chips.length}
                        </span>
                    ) : null}
                </Button>

                <div className="hidden md:flex md:flex-wrap md:items-center md:gap-2">
                    {selects.map((select) => (
                        <FilterSelect
                            key={String(select.key)}
                            label={select.label}
                            onChange={(value) =>
                                onFilterChange({
                                    [select.key]: value,
                                } as Partial<AdminFulfillmentQueryState>)
                            }
                            options={select.options}
                            value={
                                (filters[select.key] as
                                    string | null | undefined) ?? null
                            }
                        />
                    ))}
                </div>
            </div>

            <Sheet onOpenChange={setSheetOpen} open={sheetOpen}>
                <SheetContent
                    className="max-h-[85vh] overflow-y-auto rounded-t-xl motion-reduce:animate-none motion-reduce:transition-none"
                    side="bottom"
                >
                    <SheetHeader>
                        <SheetTitle>{copy.filters}</SheetTitle>
                    </SheetHeader>
                    <div className="grid grid-cols-1 gap-3 p-4 pt-2 sm:grid-cols-2">
                        {selects.map((select) => (
                            <div
                                className="flex flex-col gap-1.5"
                                key={`sheet-${String(select.key)}`}
                            >
                                <Label className="text-xs font-medium text-muted-foreground">
                                    {select.label}
                                </Label>
                                <FilterSelect
                                    label={select.label}
                                    onChange={(value) =>
                                        onFilterChange({
                                            [select.key]: value,
                                        } as Partial<AdminFulfillmentQueryState>)
                                    }
                                    options={select.options}
                                    value={
                                        (filters[select.key] as
                                            string | null | undefined) ?? null
                                    }
                                />
                            </div>
                        ))}
                        <DateFilter
                            label={copy.filterPaidFrom}
                            onChange={(value) =>
                                onFilterChange({ paid_from: value })
                            }
                            value={filters.paid_from ?? ''}
                        />
                        <DateFilter
                            label={copy.filterPaidTo}
                            onChange={(value) =>
                                onFilterChange({ paid_to: value })
                            }
                            value={filters.paid_to ?? ''}
                        />
                    </div>
                    <SheetFooter className="flex flex-row items-center justify-between gap-3 border-t border-border p-4">
                        <Button
                            className="min-h-11 flex-1 text-sm"
                            onClick={onResetFilters}
                            type="button"
                            variant="outline"
                        >
                            {copy.resetFilters}
                        </Button>
                        <Button
                            className="min-h-11 flex-1 text-sm"
                            onClick={() => setSheetOpen(false)}
                            type="button"
                        >
                            {copy.applyFilters}
                        </Button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>

            {chips.length > 0 ? (
                <div className="flex flex-wrap items-center gap-1.5 pt-1 text-xs">
                    <span className="font-medium text-muted-foreground">
                        {copy.activeFilters}
                    </span>
                    {chips.map((chip) => (
                        <span
                            className="inline-flex items-center gap-1 rounded-md border border-border bg-muted/40 px-2 py-0.5 text-xs text-foreground"
                            key={chip.key}
                        >
                            {chip.label}
                            <button
                                aria-label={`${copy.resetFilters}: ${chip.label}`}
                                className="-my-2 -me-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                                onClick={() => {
                                    if (chip.key === 'search') {
                                        setSearch('');
                                    }

                                    onFilterChange({
                                        [chip.key]: null,
                                    } as Partial<AdminFulfillmentQueryState>);
                                }}
                                type="button"
                            >
                                <X aria-hidden="true" className="size-3" />
                            </button>
                        </span>
                    ))}
                    <button
                        className="inline-flex min-h-11 items-center px-1 text-xs font-medium text-primary underline underline-offset-2 transition-colors hover:text-primary/80 focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                        onClick={() => {
                            setSearch('');
                            onResetFilters();
                        }}
                        type="button"
                    >
                        {copy.resetFilters}
                    </button>
                </div>
            ) : null}
        </div>
    );
}

function FilterSelect({
    label,
    onChange,
    options,
    value,
}: {
    label: string;
    onChange: (value: string | null) => void;
    options: AdminFilterOption[];
    value: string | null;
}) {
    return (
        <Select
            onValueChange={(next) => onChange(next === ALL ? null : next)}
            value={value ?? ALL}
        >
            <SelectTrigger
                aria-label={label}
                className="min-h-11 w-full text-sm min-[480px]:w-36 md:text-xs"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent className="motion-reduce:animate-none">
                {options.map((option) => (
                    <SelectItem
                        className="min-h-11 text-sm md:text-xs"
                        key={option.value}
                        value={option.value === 'all' ? ALL : option.value}
                    >
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function DateFilter({
    label,
    onChange,
    value,
}: {
    label: string;
    onChange: (value: string | null) => void;
    value: string;
}) {
    return (
        <div className="flex w-full flex-col gap-1.5">
            <Label className="text-xs font-medium text-muted-foreground">
                {label}
            </Label>
            <Input
                aria-label={label}
                className="min-h-11 w-full text-sm md:text-xs"
                onChange={(event) => onChange(event.target.value || null)}
                type="date"
                value={value}
            />
        </div>
    );
}

/**
 * The filters currently narrowing the list, as removable chips.
 *
 * Labels come from the same option lists the selects render, so a chip and its
 * select can never disagree about what a value is called.
 */
function activeChips(
    filters: AdminFulfillmentQueryState,
    options: AdminFulfillmentFilterOptions,
    copy: AdminTranslations['fulfillment'],
): { key: keyof AdminFulfillmentQueryState; label: string }[] {
    const chips: { key: keyof AdminFulfillmentQueryState; label: string }[] =
        [];
    const labelFor = (list: AdminFilterOption[], value: string): string =>
        list.find((option) => option.value === value)?.label ?? value;

    if (filters.search && filters.search.trim() !== '') {
        chips.push({
            key: 'search',
            label: `${copy.searchLabel}: "${filters.search}"`,
        });
    }

    if (filters.supplier) {
        chips.push({
            key: 'supplier',
            label: `${copy.columnSupplier}: ${labelFor(options.suppliers, filters.supplier)}`,
        });
    }

    if (filters.status) {
        chips.push({
            key: 'status',
            label: `${copy.columnState}: ${labelFor(options.statuses, filters.status)}`,
        });
    }

    if (filters.alarm) {
        chips.push({
            key: 'alarm',
            label: `${copy.filterAlarm}: ${labelFor(options.alarms, filters.alarm)}`,
        });
    }

    if (filters.service) {
        chips.push({
            key: 'service',
            label: `${copy.columnService}: ${labelFor(options.services, filters.service)}`,
        });
    }

    if (filters.phase) {
        chips.push({
            key: 'phase',
            label: `${copy.filterPhase}: ${labelFor(options.phases, filters.phase)}`,
        });
    }

    if (filters.hold) {
        chips.push({
            key: 'hold',
            label: `${copy.filterHold}: ${labelFor(options.holds, filters.hold)}`,
        });
    }

    if (filters.paid_from) {
        chips.push({
            key: 'paid_from',
            label: `${copy.filterPaidFrom}: ${filters.paid_from}`,
        });
    }

    if (filters.paid_to) {
        chips.push({
            key: 'paid_to',
            label: `${copy.filterPaidTo}: ${filters.paid_to}`,
        });
    }

    return chips;
}
