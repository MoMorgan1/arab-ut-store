'use no memo';

import { Head, router } from '@inertiajs/react';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
import type {
    OnChangeFn,
    PaginationState,
    RowSelectionState,
    SortingState,
    VisibilityState,
} from '@tanstack/react-table';
import { CheckCircle2 } from 'lucide-react';
import React, { useMemo, useState } from 'react';

import { getAdminCategoryColumns } from '@/components/admin/categories/admin-categories-columns';
import type { CategorySortKey } from '@/components/admin/categories/admin-categories-columns';
import AdminCategoriesPagination from '@/components/admin/categories/admin-categories-pagination';
import AdminCategoriesTable from '@/components/admin/categories/admin-categories-table';
import AdminCategoriesToolbar from '@/components/admin/categories/admin-categories-toolbar';
import AdminCategoryVisibilityDialog from '@/components/admin/categories/admin-category-visibility-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    buildCategoriesQuery,
    hasActiveCategoryFilters,
} from '@/lib/admin-categories-query';
import type {
    AdminCategoriesPageProps,
    AdminCategoriesQueryState,
    AdminCategoryRow,
} from '@/types/admin';

export default function AdminCategoriesIndex(props: AdminCategoriesPageProps) {
    const copy = props.adminUi.categories;
    const canManage =
        props.permissions.includes('catalog.manage') &&
        props.adminIdentity.role === 'admin';

    const [isNavigating, setIsNavigating] = useState(false);
    const [queryFailed, setQueryFailed] = useState(false);
    const [failedFilters, setFailedFilters] =
        useState<Partial<AdminCategoriesQueryState> | null>(null);

    const [feedbackMessage, setFeedbackMessage] = useState<string | null>(null);
    const [conflictAlert, setConflictAlert] = useState<string | null>(null);

    const [selectedCategory, setSelectedCategory] =
        useState<AdminCategoryRow | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );
    const [selection, setSelection] = useState<{
        query: string;
        rows: RowSelectionState;
    }>({
        query: '',
        rows: {},
    });

    const queryScope = JSON.stringify(props.filters);
    const rowSelection = selection.query === queryScope ? selection.rows : {};

    const visitCategories = (
        nextFilters: Partial<AdminCategoriesQueryState>,
        preservePage = true,
    ) => {
        const merged: AdminCategoriesQueryState = {
            ...props.filters,
            ...nextFilters,
        };

        if (!preservePage) {
            merged.page = 1;
        }

        const query = buildCategoriesQuery(merged);

        setIsNavigating(true);
        setQueryFailed(false);
        setFeedbackMessage(null);
        setConflictAlert(null);

        router.get(window.location.pathname, query, {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                setQueryFailed(true);
                setFailedFilters(merged);
                setIsNavigating(false);
            },
            onFinish: () => {
                setIsNavigating(false);
            },
        });
    };

    const applyQuery = (
        patch: Partial<AdminCategoriesQueryState>,
        preservePage = false,
    ) => {
        visitCategories(patch, preservePage);
    };

    const resetFilters = () => {
        visitCategories(
            {
                search: null,
                visibility: null,
                source: null,
                sort: 'sort_order',
                direction: 'asc',
                per_page: 15,
                page: 1,
            },
            false,
        );
    };

    const handleSortChange = (
        sort: CategorySortKey,
        direction: 'asc' | 'desc',
    ) => {
        applyQuery({ direction, sort }, true);
    };

    const handleToggleVisibility = (category: AdminCategoryRow) => {
        setSelectedCategory(category);
        setDialogOpen(true);
    };

    const handleVisibilitySuccess = (result: {
        adminHidden: boolean;
        category: string;
    }) => {
        setFeedbackMessage(
            result.adminHidden
                ? copy.visibilityHiddenMessage
                : copy.visibilityRestoredMessage,
        );
        // Refresh page data silently
        router.reload({ only: ['categories'] });
    };

    // The 409 body reports the server's current state, but the reload below is
    // what resyncs the whole page, so the handler deliberately takes no argument.
    const handleVisibilityConflict = () => {
        setConflictAlert(copy.visibilityConflictError);
        router.reload({ only: ['categories'] });
    };

    const columns = useMemo(
        () =>
            getAdminCategoryColumns({
                adminUi: props.adminUi,
                canManage,
                currentDirection:
                    (props.filters.direction as 'asc' | 'desc') || 'asc',
                currentSort:
                    (props.filters.sort as CategorySortKey) || 'sort_order',
                locale: props.locale,
                onSortChange: handleSortChange,
                onToggleVisibility: handleToggleVisibility,
            }),
        [
            props.adminUi,
            canManage,
            props.filters.direction,
            props.filters.sort,
            props.locale,
        ],
    );

    const sorting: SortingState = [
        {
            desc: props.filters.direction === 'desc',
            id: props.filters.sort || 'sort_order',
        },
    ];

    const pagination: PaginationState = {
        pageIndex: props.pagination.currentPage - 1,
        pageSize: props.pagination.perPage,
    };

    const changeRowSelection: OnChangeFn<RowSelectionState> = (updater) => {
        setSelection((current) => {
            const currentRows =
                current.query === queryScope ? current.rows : {};
            const nextRows =
                typeof updater === 'function' ? updater(currentRows) : updater;

            return { query: queryScope, rows: nextRows };
        });
    };

    const table = useReactTable({
        columns,
        data: props.categories,
        enableRowSelection: true,
        getCoreRowModel: getCoreRowModel(),
        getRowId: (row) => row.id,
        manualFiltering: true,
        manualPagination: true,
        manualSorting: true,
        onColumnVisibilityChange: setColumnVisibility,
        onRowSelectionChange: changeRowSelection,
        rowCount: props.pagination.total,
        state: { columnVisibility, pagination, rowSelection, sorting },
    });

    return (
        <article className="space-y-6" dir={props.direction}>
            <Head title={copy.headTitle} />

            <header className="flex flex-col gap-1 border-b border-border pb-5">
                <h1 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">
                    {copy.title}
                </h1>
                <p className="max-w-prose text-sm leading-relaxed text-muted-foreground">
                    {copy.description}
                </p>
            </header>

            {feedbackMessage ? (
                <Alert
                    className="border-emerald-500/40 bg-emerald-500/10 text-emerald-900 dark:text-emerald-200"
                    role="status"
                >
                    <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400" />
                    <AlertTitle>{copy.status}</AlertTitle>
                    <AlertDescription>{feedbackMessage}</AlertDescription>
                </Alert>
            ) : null}

            {conflictAlert ? (
                <Alert role="alert" variant="destructive">
                    <AlertTitle>{copy.errorTitle}</AlertTitle>
                    <AlertDescription>{conflictAlert}</AlertDescription>
                </Alert>
            ) : null}

            {queryFailed ? (
                <Alert variant="destructive">
                    <AlertTitle>{copy.errorTitle}</AlertTitle>
                    <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
                        <span>{copy.loadFailed}</span>
                        <Button
                            className="min-h-11"
                            onClick={() =>
                                visitCategories(failedFilters ?? props.filters)
                            }
                            type="button"
                            variant="outline"
                        >
                            {props.adminUi.common.retry}
                        </Button>
                    </AlertDescription>
                </Alert>
            ) : null}

            <AdminCategoriesToolbar
                adminUi={props.adminUi}
                filterOptions={props.filterOptions}
                filters={props.filters}
                isNavigating={isNavigating}
                key={queryScope}
                onFilterChange={(filters) => applyQuery(filters)}
                onResetFilters={resetFilters}
                productsUrl={props.productsUrl}
                table={table}
            />

            <AdminCategoriesTable
                adminUi={props.adminUi}
                canManage={canManage}
                currentDirection={
                    (props.filters.direction as 'asc' | 'desc') || 'asc'
                }
                currentSort={
                    (props.filters.sort as CategorySortKey) || 'sort_order'
                }
                isFiltered={hasActiveCategoryFilters(props.filters)}
                isNavigating={isNavigating}
                locale={props.locale}
                onResetFilters={resetFilters}
                onToggleVisibility={handleToggleVisibility}
                table={table}
            />

            <AdminCategoriesPagination
                adminUi={props.adminUi}
                direction={props.direction}
                isNavigating={isNavigating}
                onPageChange={(page) => applyQuery({ page }, false)}
                onPerPageChange={(per_page) => applyQuery({ per_page })}
                pagination={props.pagination}
                perPageOptions={props.filterOptions.perPageOptions}
                table={table}
            />

            <AdminCategoryVisibilityDialog
                adminUi={props.adminUi}
                category={selectedCategory}
                onConflict={handleVisibilityConflict}
                onOpenChange={setDialogOpen}
                onSuccess={handleVisibilitySuccess}
                open={dialogOpen}
                visibilityUrlTemplate={props.visibilityUrlTemplate}
            />
        </article>
    );
}
