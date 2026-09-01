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

import { getAdminCustomerColumns } from '@/components/admin/customers/admin-customers-columns';
import AdminCustomersPagination from '@/components/admin/customers/admin-customers-pagination';
import AdminCustomersTable from '@/components/admin/customers/admin-customers-table';
import AdminCustomersToolbar from '@/components/admin/customers/admin-customers-toolbar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    buildCustomersQuery,
    hasActiveCustomerFilters,
} from '@/lib/admin-customers-query';
import type {
    AdminCustomersPageProps,
    AdminCustomersQueryState,
} from '@/types/admin';

type ScopedSelection = {
    query: string;
    rows: RowSelectionState;
};

export default function AdminCustomersPage() {
    const { props, url } = usePage<AdminCustomersPageProps>();
    const copy = props.adminUi.customers;
    const pathname = new URL(url, window.location.origin).pathname;
    const queryScope = JSON.stringify(props.filters);
    const [isNavigating, setIsNavigating] = useState(false);
    const [queryFailed, setQueryFailed] = useState(false);
    const [failedFilters, setFailedFilters] =
        useState<AdminCustomersQueryState | null>(null);
    const [selection, setSelection] = useState<ScopedSelection>({
        query: queryScope,
        rows: {},
    });
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );
    const rowSelection = selection.query === queryScope ? selection.rows : {};

    const visitCustomers = useCallback(
        (filters: AdminCustomersQueryState) => {
            const showFailure = () => {
                setQueryFailed(true);
                setFailedFilters(filters);
            };

            router.get(pathname, buildCustomersQuery(filters), {
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
        (nextFilters: Partial<AdminCustomersQueryState>, resetPage = true) => {
            visitCustomers({
                ...props.filters,
                ...nextFilters,
                page: resetPage ? 1 : (nextFilters.page ?? props.filters.page),
            });
        },
        [props.filters, visitCustomers],
    );

    const resetFilters = useCallback(() => {
        applyQuery({
            date_from: null,
            date_to: null,
            direction: 'desc',
            search: null,
            sort: 'created_at',
            status: null,
        });
    }, [applyQuery]);

    const columns = useMemo(
        () =>
            getAdminCustomerColumns({
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
        data: props.customers,
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
                                visitCustomers(failedFilters ?? props.filters)
                            }
                            type="button"
                            variant="outline"
                        >
                            {props.adminUi.common.retry}
                        </Button>
                    </AlertDescription>
                </Alert>
            ) : null}

            <AdminCustomersToolbar
                adminUi={props.adminUi}
                filterOptions={props.filterOptions}
                filters={props.filters}
                isNavigating={isNavigating}
                key={queryScope}
                onFilterChange={(filters) => applyQuery(filters)}
                onResetFilters={resetFilters}
                table={table}
            />

            <AdminCustomersTable
                adminUi={props.adminUi}
                currentDirection={props.filters.direction}
                currentSort={props.filters.sort}
                isFiltered={hasActiveCustomerFilters(props.filters)}
                isNavigating={isNavigating}
                locale={props.locale}
                onResetFilters={resetFilters}
                table={table}
            />

            <AdminCustomersPagination
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
