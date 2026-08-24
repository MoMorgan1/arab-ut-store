'use no memo'; // TanStack Table's mutable instances are not React Compiler compatible.

import { Head, router, usePage } from '@inertiajs/react';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
import type {
    OnChangeFn,
    PaginationState,
    RowSelectionState,
    SortingState,
    VisibilityState,
} from '@tanstack/react-table';
import { useCallback, useMemo, useState } from 'react';

import { getAdminProductColumns } from '@/components/admin/products/admin-products-columns';
import AdminProductsPagination from '@/components/admin/products/admin-products-pagination';
import AdminProductsTable from '@/components/admin/products/admin-products-table';
import AdminProductsToolbar from '@/components/admin/products/admin-products-toolbar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    buildProductsQuery,
    hasActiveProductFilters,
} from '@/lib/admin-products-query';
import type {
    AdminProductsPageProps,
    AdminProductsQueryState,
} from '@/types/admin';

type ScopedSelection = {
    query: string;
    rows: RowSelectionState;
};

export default function AdminProductsPage() {
    const { props, url } = usePage<AdminProductsPageProps>();
    const copy = props.adminUi.products;
    const pathname = new URL(url, window.location.origin).pathname;
    const queryScope = JSON.stringify(props.filters);
    const [isNavigating, setIsNavigating] = useState(false);
    const [queryFailed, setQueryFailed] = useState(false);
    const [failedFilters, setFailedFilters] =
        useState<AdminProductsQueryState | null>(null);
    const [selection, setSelection] = useState<ScopedSelection>({
        query: queryScope,
        rows: {},
    });
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );
    const rowSelection = selection.query === queryScope ? selection.rows : {};

    const visitProducts = useCallback(
        (filters: AdminProductsQueryState) => {
            const showFailure = () => {
                setQueryFailed(true);
                setFailedFilters(filters);
            };

            router.get(pathname, buildProductsQuery(filters), {
                onError: showFailure,
                onFinish: () => setIsNavigating(false),
                onHttpException: () => {
                    showFailure();

                    return false;
                },
                onNetworkError: () => {
                    showFailure();

                    return false;
                },
                onStart: () => {
                    setIsNavigating(true);
                    setQueryFailed(false);
                },
                onSuccess: () => setFailedFilters(null),
                preserveScroll: true,
                preserveState: true,
                replace: true,
            });
        },
        [pathname],
    );

    const applyQuery = useCallback(
        (nextFilters: Partial<AdminProductsQueryState>, resetPage = true) => {
            visitProducts({
                ...props.filters,
                ...nextFilters,
                page: resetPage ? 1 : (nextFilters.page ?? props.filters.page),
            });
        },
        [props.filters, visitProducts],
    );

    const resetFilters = useCallback(() => {
        applyQuery({
            archived: null,
            authority: null,
            direction: 'desc',
            search: null,
            service_type: null,
            sort: 'created_at',
            source: null,
            visibility: null,
        });
    }, [applyQuery]);

    const columns = useMemo(
        () =>
            getAdminProductColumns({
                adminUi: props.adminUi,
                currentDirection: props.filters.direction,
                currentSort: props.filters.sort,
                locale: props.locale,
                onSortChange: (sort, direction) =>
                    applyQuery({ direction, sort }),
            }),
        [
            applyQuery,
            props.adminUi,
            props.filters.direction,
            props.filters.sort,
            props.locale,
        ],
    );

    const sorting: SortingState = [
        {
            desc: props.filters.direction === 'desc',
            id: props.filters.sort,
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
        data: props.products,
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

            {queryFailed ? (
                <Alert variant="destructive">
                    <AlertTitle>{copy.errorTitle}</AlertTitle>
                    <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
                        <span>{copy.loadFailed}</span>
                        <Button
                            className="min-h-11"
                            onClick={() =>
                                visitProducts(failedFilters ?? props.filters)
                            }
                            type="button"
                            variant="outline"
                        >
                            {props.adminUi.common.retry}
                        </Button>
                    </AlertDescription>
                </Alert>
            ) : null}

            <AdminProductsToolbar
                adminUi={props.adminUi}
                categoriesUrl={props.categoriesUrl}
                filterOptions={props.filterOptions}
                filters={props.filters}
                isNavigating={isNavigating}
                key={queryScope}
                onFilterChange={(filters) => applyQuery(filters)}
                onResetFilters={resetFilters}
                table={table}
            />

            <AdminProductsTable
                adminUi={props.adminUi}
                currentDirection={props.filters.direction}
                currentSort={props.filters.sort}
                isFiltered={hasActiveProductFilters(props.filters)}
                isNavigating={isNavigating}
                locale={props.locale}
                onResetFilters={resetFilters}
                table={table}
            />

            <AdminProductsPagination
                adminUi={props.adminUi}
                direction={props.direction}
                isNavigating={isNavigating}
                onPageChange={(page) => applyQuery({ page }, false)}
                onPerPageChange={(per_page) => applyQuery({ per_page })}
                pagination={props.pagination}
                perPageOptions={props.filterOptions.perPageOptions}
                table={table}
            />
        </article>
    );
}
