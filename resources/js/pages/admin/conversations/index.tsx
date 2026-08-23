import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
    Globe,
    LoaderCircle,
    MessageSquare,
    Search,
    User,
    X,
    XCircle,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import type { FormEvent } from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    buildConversationsQuery,
    hasActiveConversationFilters,
} from '@/lib/admin-conversations-query';
import type {
    AdminConversationRow,
    AdminConversationsPageProps,
    AdminConversationsQueryState,
    AdminFilterOption,
} from '@/types/admin';

export default function AdminConversationsIndexPage() {
    const { props, url } = usePage<AdminConversationsPageProps>();
    const copy = props.adminUi.conversations;
    const pathname = new URL(url, window.location.origin).pathname;
    const isLocalized = pathname.startsWith('/en/admin');
    const basePath = isLocalized
        ? '/en/admin/conversations'
        : '/admin/conversations';

    const [isNavigating, setIsNavigating] = useState(false);
    const [queryFailed, setQueryFailed] = useState(false);
    const [search, setSearch] = useState(props.filters.q ?? '');

    const dateFormatter = new Intl.DateTimeFormat(props.locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    const visitConversations = useCallback(
        (filters: AdminConversationsQueryState) => {
            const showFailure = () => {
                setQueryFailed(true);
            };

            router.get(pathname, buildConversationsQuery(filters), {
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
                preserveScroll: true,
                preserveState: true,
                replace: true,
            });
        },
        [pathname],
    );

    const applyQuery = useCallback(
        (
            nextFilters: Partial<AdminConversationsQueryState>,
            resetPage = true,
        ) => {
            visitConversations({
                ...props.filters,
                ...nextFilters,
                page: resetPage ? 1 : (nextFilters.page ?? props.filters.page),
            });
        },
        [props.filters, visitConversations],
    );

    const resetFilters = useCallback(() => {
        setSearch('');
        applyQuery({
            locale: null,
            owner: null,
            q: null,
            status: null,
        });
    }, [applyQuery]);

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        applyQuery({ q: search.trim() || null });
    };

    const clearSearch = () => {
        setSearch('');
        applyQuery({ q: null });
    };

    const activeChips: Array<{
        key: string;
        label: string;
        onClear: () => void;
    }> = [];

    if (props.filters.q && props.filters.q.trim() !== '') {
        activeChips.push({
            key: 'q',
            label: `${copy.conversation}: "${props.filters.q.trim()}"`,
            onClear: clearSearch,
        });
    }

    if (props.filters.status) {
        const option = props.filterOptions.statuses.find(
            (o) => o.value === props.filters.status,
        );
        activeChips.push({
            key: 'status',
            label: `${copy.status}: ${option?.label ?? props.filters.status}`,
            onClear: () => applyQuery({ status: null }),
        });
    }

    if (props.filters.locale) {
        const option = props.filterOptions.locales.find(
            (o) => o.value === props.filters.locale,
        );
        activeChips.push({
            key: 'locale',
            label: `${copy.locale}: ${option?.label ?? props.filters.locale}`,
            onClear: () => applyQuery({ locale: null }),
        });
    }

    if (props.filters.owner) {
        const option = props.filterOptions.owners.find(
            (o) => o.value === props.filters.owner,
        );
        activeChips.push({
            key: 'owner',
            label: `${copy.owner}: ${option?.label ?? props.filters.owner}`,
            onClear: () => applyQuery({ owner: null }),
        });
    }

    const { currentPage, lastPage, perPage, total, from, to } =
        props.pagination;
    const canPreviousPage = currentPage > 1;
    const canNextPage = currentPage < lastPage;
    const isFiltered = hasActiveConversationFilters(props.filters);

    return (
        <article className="space-y-6" dir={props.direction}>
            <Head title={copy.headTitle} />

            <header className="flex flex-col gap-1 border-b border-border pb-5">
                <div className="flex items-center gap-2">
                    <MessageSquare
                        aria-hidden="true"
                        className="size-5 text-primary"
                    />
                    <h1 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">
                        {copy.title}
                    </h1>
                </div>
                <p className="max-w-prose text-sm leading-relaxed text-muted-foreground">
                    {copy.description}
                </p>
            </header>

            {queryFailed ? (
                <Alert variant="destructive">
                    <AlertCircle className="size-4" />
                    <AlertTitle>{copy.errorTitle}</AlertTitle>
                    <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
                        <span>{copy.loadFailed}</span>
                        <Button
                            className="min-h-11"
                            onClick={() => visitConversations(props.filters)}
                            type="button"
                            variant="outline"
                        >
                            {props.adminUi.common.retry}
                        </Button>
                    </AlertDescription>
                </Alert>
            ) : null}

            {/* Filter Toolbar */}
            <div className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <form
                        className="flex min-w-[220px] flex-1 items-center gap-2"
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
                                maxLength={64}
                                onChange={(e) => setSearch(e.target.value)}
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
                        allLabel={copy.allStatuses}
                        disabled={isNavigating}
                        label={copy.filterStatus}
                        onChange={(status) =>
                            applyQuery({
                                status: (status as 'open' | 'closed') || null,
                            })
                        }
                        options={props.filterOptions.statuses}
                        value={props.filters.status ?? null}
                    />

                    <FilterSelect
                        allLabel={copy.allLocales}
                        disabled={isNavigating}
                        label={copy.filterLocale}
                        onChange={(locale) =>
                            applyQuery({
                                locale: (locale as 'ar' | 'en') || null,
                            })
                        }
                        options={props.filterOptions.locales}
                        value={props.filters.locale ?? null}
                    />

                    <FilterSelect
                        allLabel={copy.allOwners}
                        disabled={isNavigating}
                        label={copy.filterOwner}
                        onChange={(owner) =>
                            applyQuery({
                                owner: (owner as 'guest' | 'customer') || null,
                            })
                        }
                        options={props.filterOptions.owners}
                        value={props.filters.owner ?? null}
                    />

                    {isFiltered ? (
                        <Button
                            className="min-h-11 text-sm md:text-xs"
                            disabled={isNavigating}
                            onClick={resetFilters}
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
                                    aria-label={`Remove filter ${chip.label}`}
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

            {/* Content Table & Mobile Cards */}
            <div aria-busy={isNavigating} className="relative">
                {isNavigating ? (
                    <div
                        aria-live="polite"
                        className="absolute inset-0 z-20 flex items-center justify-center rounded-lg bg-background/90"
                    >
                        <div className="flex items-center gap-2 rounded-md border border-border bg-popover px-4 py-2 text-sm font-medium text-popover-foreground shadow-md">
                            <LoaderCircle
                                aria-hidden="true"
                                className="size-4 animate-spin motion-reduce:hidden"
                            />
                            <span>{copy.loading}</span>
                        </div>
                    </div>
                ) : null}

                {/* Desktop Table */}
                <div
                    aria-label={copy.tableLabel}
                    className="hidden rounded-lg border border-border bg-card shadow-xs md:block"
                    role="region"
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-56 font-semibold">
                                    {copy.conversation}
                                </TableHead>
                                <TableHead className="w-28 font-semibold">
                                    {copy.status}
                                </TableHead>
                                <TableHead className="font-semibold">
                                    {copy.owner}
                                </TableHead>
                                <TableHead className="w-24 font-semibold">
                                    {copy.locale}
                                </TableHead>
                                <TableHead className="w-28 font-semibold">
                                    {copy.messageCount}
                                </TableHead>
                                <TableHead className="w-44 font-semibold">
                                    {copy.lastActivity}
                                </TableHead>
                                <TableHead className="w-44 font-semibold">
                                    {copy.createdAt}
                                </TableHead>
                                <TableHead className="w-28 text-end font-semibold">
                                    {copy.actions}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {props.rows.length > 0 ? (
                                props.rows.map((row) => (
                                    <TableRow key={row.publicId}>
                                        <TableCell className="font-semibold">
                                            <Link
                                                className="text-sm font-semibold text-foreground tabular-nums underline decoration-border underline-offset-4 transition-colors hover:text-primary hover:decoration-primary focus-visible:outline-2 focus-visible:outline-ring"
                                                href={`${basePath}/${row.publicId}`}
                                            >
                                                <bdi>{row.publicId}</bdi>
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <AdminBadge
                                                icon={
                                                    row.status === 'open'
                                                        ? CheckCircle2
                                                        : XCircle
                                                }
                                                variant={
                                                    row.status === 'open'
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                            >
                                                {row.status === 'open'
                                                    ? copy.statusOpen
                                                    : copy.statusClosed}
                                            </AdminBadge>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-1.5">
                                                {row.ownerType ===
                                                'customer' ? (
                                                    <>
                                                        <User
                                                            aria-hidden="true"
                                                            className="size-3.5 text-muted-foreground"
                                                        />
                                                        <span className="font-medium text-foreground">
                                                            <bdi>
                                                                {row.customerName ??
                                                                    copy.ownerCustomer}
                                                            </bdi>
                                                        </span>
                                                    </>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground italic">
                                                        {copy.ownerGuest}
                                                    </span>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <span className="inline-flex items-center gap-1 text-xs font-semibold text-muted-foreground uppercase">
                                                <Globe
                                                    aria-hidden="true"
                                                    className="size-3"
                                                />
                                                <span>{row.locale}</span>
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <span className="inline-flex items-center gap-1 text-xs font-medium text-foreground tabular-nums">
                                                <MessageSquare
                                                    aria-hidden="true"
                                                    className="size-3 text-muted-foreground"
                                                />
                                                <span>{row.messageCount}</span>
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                            {row.lastMessageAt ? (
                                                <bdi>
                                                    {dateFormatter.format(
                                                        new Date(
                                                            row.lastMessageAt,
                                                        ),
                                                    )}
                                                </bdi>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell className="text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                            <bdi>
                                                {dateFormatter.format(
                                                    new Date(row.createdAt),
                                                )}
                                            </bdi>
                                        </TableCell>
                                        <TableCell className="text-end">
                                            <Link
                                                className="inline-flex min-h-11 items-center justify-center rounded-md px-3 text-xs font-medium text-primary hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                                                href={`${basePath}/${row.publicId}`}
                                            >
                                                {copy.viewDetail}
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell
                                        className="h-32 text-center"
                                        colSpan={8}
                                    >
                                        <div className="flex flex-col items-center justify-center gap-2 text-muted-foreground">
                                            <p className="text-sm">
                                                {isFiltered
                                                    ? copy.noConversationsMatching
                                                    : copy.noConversations}
                                            </p>
                                            {isFiltered ? (
                                                <Button
                                                    className="min-h-11 text-xs"
                                                    onClick={resetFilters}
                                                    type="button"
                                                    variant="outline"
                                                >
                                                    {copy.resetFilters}
                                                </Button>
                                            ) : null}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                {/* Mobile Card List */}
                <div
                    aria-label={copy.tableLabel}
                    className="flex flex-col gap-3 md:hidden"
                    role="list"
                >
                    {props.rows.length > 0 ? (
                        props.rows.map((row) => (
                            <ConversationMobileCard
                                basePath={basePath}
                                copy={copy}
                                dateFormatter={dateFormatter}
                                key={row.publicId}
                                row={row}
                            />
                        ))
                    ) : (
                        <div className="rounded-lg border border-border bg-card p-6 text-center text-muted-foreground">
                            <p className="text-sm">
                                {isFiltered
                                    ? copy.noConversationsMatching
                                    : copy.noConversations}
                            </p>
                            {isFiltered ? (
                                <Button
                                    className="mt-3 min-h-11 text-xs"
                                    onClick={resetFilters}
                                    type="button"
                                    variant="outline"
                                >
                                    {copy.resetFilters}
                                </Button>
                            ) : null}
                        </div>
                    )}
                </div>
            </div>

            {/* Pagination Controls */}
            <div className="flex flex-wrap items-center justify-between gap-4 px-2 py-3 text-sm text-muted-foreground">
                <div className="flex-1 text-xs">
                    {total > 0 && from !== null && to !== null ? (
                        <>
                            {copy.showing}{' '}
                            <strong className="text-foreground tabular-nums">
                                {from}
                            </strong>{' '}
                            {copy.to}{' '}
                            <strong className="text-foreground tabular-nums">
                                {to}
                            </strong>{' '}
                            {copy.of}{' '}
                            <strong className="text-foreground tabular-nums">
                                {total}
                            </strong>{' '}
                            {copy.results}
                        </>
                    ) : null}
                </div>

                <div className="flex flex-wrap items-center gap-6">
                    <div className="flex items-center gap-2">
                        <span className="text-xs">{copy.perPage}</span>
                        <Select
                            disabled={isNavigating}
                            onValueChange={(val) =>
                                applyQuery(
                                    {
                                        per_page: Number(val) as
                                            15 | 25 | 50 | 100,
                                    },
                                    true,
                                )
                            }
                            value={String(perPage)}
                        >
                            <SelectTrigger
                                aria-label={copy.perPage}
                                className="h-9 min-h-[44px] w-[70px]"
                            >
                                <SelectValue placeholder={String(perPage)} />
                            </SelectTrigger>
                            <SelectContent
                                className="motion-reduce:animate-none"
                                side="top"
                            >
                                {props.filterOptions.perPageOptions.map(
                                    (opt) => (
                                        <SelectItem
                                            className="min-h-11"
                                            key={opt}
                                            value={String(opt)}
                                        >
                                            {opt}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </div>

                    <div
                        aria-live="polite"
                        className="flex items-center justify-center text-xs text-foreground"
                    >
                        {copy.page}{' '}
                        <strong className="mx-1">{currentPage}</strong>{' '}
                        {copy.of}{' '}
                        <strong className="mx-1">
                            {Math.max(1, lastPage)}
                        </strong>
                    </div>

                    <div className="flex items-center gap-1">
                        <Button
                            aria-label={copy.firstPage}
                            className="h-9 min-h-[44px] w-9 min-w-[44px] p-0"
                            disabled={!canPreviousPage || isNavigating}
                            onClick={() => applyQuery({ page: 1 }, false)}
                            type="button"
                            variant="outline"
                        >
                            <ChevronsLeft
                                aria-hidden="true"
                                className="h-4 w-4"
                            />
                        </Button>
                        <Button
                            aria-label={copy.previous}
                            className="h-9 min-h-[44px] w-9 min-w-[44px] p-0"
                            disabled={!canPreviousPage || isNavigating}
                            onClick={() =>
                                applyQuery({ page: currentPage - 1 }, false)
                            }
                            type="button"
                            variant="outline"
                        >
                            <ChevronLeft
                                aria-hidden="true"
                                className="h-4 w-4"
                            />
                        </Button>
                        <Button
                            aria-label={copy.next}
                            className="h-9 min-h-[44px] w-9 min-w-[44px] p-0"
                            disabled={!canNextPage || isNavigating}
                            onClick={() =>
                                applyQuery({ page: currentPage + 1 }, false)
                            }
                            type="button"
                            variant="outline"
                        >
                            <ChevronRight
                                aria-hidden="true"
                                className="h-4 w-4"
                            />
                        </Button>
                        <Button
                            aria-label={copy.lastPage}
                            className="h-9 min-h-[44px] w-9 min-w-[44px] p-0"
                            disabled={!canNextPage || isNavigating}
                            onClick={() =>
                                applyQuery({ page: lastPage }, false)
                            }
                            type="button"
                            variant="outline"
                        >
                            <ChevronsRight
                                aria-hidden="true"
                                className="h-4 w-4"
                            />
                        </Button>
                    </div>
                </div>
            </div>
        </article>
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

function ConversationMobileCard({
    basePath,
    copy,
    dateFormatter,
    row,
}: {
    basePath: string;
    copy: AdminConversationsPageProps['adminUi']['conversations'];
    dateFormatter: Intl.DateTimeFormat;
    row: AdminConversationRow;
}) {
    return (
        <div
            className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 text-card-foreground shadow-xs"
            role="listitem"
        >
            <div className="flex items-start justify-between gap-2">
                <div className="flex flex-col gap-0.5">
                    <Link
                        className="text-xs font-semibold text-foreground tabular-nums underline decoration-border underline-offset-4"
                        href={`${basePath}/${row.publicId}`}
                    >
                        <bdi>{row.publicId}</bdi>
                    </Link>
                    <span className="text-xs text-muted-foreground">
                        {row.ownerType === 'customer' ? (
                            <bdi>{row.customerName ?? copy.ownerCustomer}</bdi>
                        ) : (
                            copy.ownerGuest
                        )}
                    </span>
                </div>
                <AdminBadge
                    icon={row.status === 'open' ? CheckCircle2 : XCircle}
                    variant={row.status === 'open' ? 'success' : 'neutral'}
                >
                    {row.status === 'open'
                        ? copy.statusOpen
                        : copy.statusClosed}
                </AdminBadge>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border/60 pt-2 text-xs text-muted-foreground">
                <div className="flex items-center gap-3">
                    <span className="inline-flex items-center gap-1 font-semibold uppercase">
                        <Globe aria-hidden="true" className="size-3" />
                        <span>{row.locale}</span>
                    </span>
                    <span className="inline-flex items-center gap-1 font-medium text-foreground tabular-nums">
                        <MessageSquare
                            aria-hidden="true"
                            className="size-3 text-muted-foreground"
                        />
                        <span>{row.messageCount}</span>
                    </span>
                </div>
                <span className="tabular-nums">
                    <bdi>{dateFormatter.format(new Date(row.createdAt))}</bdi>
                </span>
            </div>

            <Link
                className="inline-flex min-h-11 items-center justify-center rounded-md border border-border bg-accent/40 text-xs font-semibold text-foreground transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
                href={`${basePath}/${row.publicId}`}
            >
                {copy.viewDetail}
            </Link>
        </div>
    );
}
