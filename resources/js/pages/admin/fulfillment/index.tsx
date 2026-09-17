import { Head, router, useHttp, usePage } from '@inertiajs/react';
import { CircleCheck, RotateCcw } from 'lucide-react';
import { useCallback, useState } from 'react';

import AdminFulfillmentDetailSheet from '@/components/admin/fulfillment/admin-fulfillment-detail-sheet';
import AdminFulfillmentPagination from '@/components/admin/fulfillment/admin-fulfillment-pagination';
import AdminFulfillmentResendDialog from '@/components/admin/fulfillment/admin-fulfillment-resend-dialog';
import AdminFulfillmentTable from '@/components/admin/fulfillment/admin-fulfillment-table';
import AdminFulfillmentToolbar from '@/components/admin/fulfillment/admin-fulfillment-toolbar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    buildFulfillmentQuery,
    hasActiveFulfillmentFilters,
} from '@/lib/admin-fulfillment-query';
import type {
    AdminFulfillmentAction,
    AdminFulfillmentPageProps,
    AdminFulfillmentQueryState,
    AdminFulfillmentRow,
    AdminFulfillmentSort,
} from '@/types/admin';

/** What the last press actually did, in the words the audit row uses. */
type ResendResult = {
    outcome: string;
    action: AdminFulfillmentAction;
};

/**
 * The server's own outcome word, or null when the answer carried none.
 *
 * The body is JSON either way - the controller answers `{data: {outcome}}` on
 * every status it returns - but a proxy or a thrown exception can still hand
 * back something else, so this reads defensively rather than asserting.
 */
function outcomeOf(response: { data?: unknown }): string | null {
    const body =
        typeof response.data === 'string'
            ? safeParse(response.data)
            : response.data;

    if (body === null || typeof body !== 'object' || !('data' in body)) {
        return null;
    }

    const inner = (body as { data?: unknown }).data;

    if (inner === null || typeof inner !== 'object' || !('outcome' in inner)) {
        return null;
    }

    const outcome = (inner as { outcome?: unknown }).outcome;

    return typeof outcome === 'string' && outcome !== '' ? outcome : null;
}

function safeParse(raw: string): unknown {
    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

export default function AdminFulfillmentPage() {
    const { props, url } = usePage<AdminFulfillmentPageProps>();
    const copy = props.adminUi.fulfillment;
    const pathname = new URL(url, window.location.origin).pathname;

    const [isNavigating, setIsNavigating] = useState(false);
    const [queryFailed, setQueryFailed] = useState(false);
    const [failedFilters, setFailedFilters] = useState<AdminFulfillmentQueryState | null>(null);
    const [openRow, setOpenRow] = useState<AdminFulfillmentRow | null>(null);
    const [pending, setPending] = useState<{
        row: AdminFulfillmentRow;
        action: AdminFulfillmentAction;
    } | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [result, setResult] = useState<ResendResult | null>(null);

    const visit = useCallback(
        (filters: AdminFulfillmentQueryState) => {
            const showFailure = () => {
                setQueryFailed(true);
                setFailedFilters(filters);
            };

            router.get(pathname, buildFulfillmentQuery(filters), {
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
        (next: Partial<AdminFulfillmentQueryState>, resetPage = true) => {
            visit({
                ...props.filters,
                ...next,
                page: resetPage ? 1 : (next.page ?? props.filters.page),
            });
        },
        [props.filters, visit],
    );

    const resetFilters = useCallback(() => {
        applyQuery({
            alarm: null,
            direction: 'asc',
            hold: null,
            paid_from: null,
            paid_to: null,
            phase: null,
            search: null,
            service: null,
            sort: 'paid_at',
            status: null,
            supplier: null,
        });
    }, [applyQuery]);

    const resendHttp = useHttp<
        { action: string; reason_code: string },
        { data: { outcome: string; action: string } }
    >('post', props.resendUrlTemplate, { action: 'send', reason_code: 'never_placed' });

    /**
     * Sends the press, then reports the word the SERVER used.
     *
     * The outcome is never derived here. A press that re-opened an outbox row
     * is `queued` and has placed nothing, and only the Action knows whether
     * the guarded update affected a row - so the banner reads
     * `data.outcome`, the same string the audit row carries. Guessing it in
     * the browser is how a screen ends up congratulating somebody for a
     * placement that never happened.
     *
     * Every non-2xx answer is a state rather than a crash: 409 for a row that
     * moved or a press already running, 503 for a supplier that refused.
     * Returning false from the handlers is what stops Inertia turning those
     * into an error modal.
     */
    const confirmResend = useCallback(
        async (reasonCode: string) => {
            if (pending === null) {
                return;
            }

            const { row, action } = pending;
            const target = props.resendUrlTemplate.replace('__ID__', row.id);

            setSubmitting(true);
            setResult(null);

            try {
                await resendHttp.submit('post', target, {
                    data: { action, reason_code: reasonCode },
                    headers: { Accept: 'application/json' },
                    onFinish: () => {
                        setSubmitting(false);
                        setPending(null);
                    },
                    onHttpException: (response) => {
                        setResult({
                            action,
                            outcome:
                                outcomeOf(response) ??
                                (response.status === 503 ? 'refused' : 'not_actionable'),
                        });

                        return false;
                    },
                    onNetworkError: () => {
                        setResult({ action, outcome: 'refused' });

                        return false;
                    },
                    onSuccess: (response) => {
                        setResult({ action, outcome: outcomeOf(response) ?? 'queued' });
                        setOpenRow(null);
                        router.reload({ only: ['items', 'pagination'] });
                    },
                });
            } catch {
                // Reported through the callbacks above.
            }
        },
        [pending, props.resendUrlTemplate, resendHttp],
    );

    const startAction = useCallback(
        (row: AdminFulfillmentRow, action: AdminFulfillmentAction) => {
            setResult(null);
            setPending({ action, row });
        },
        [],
    );

    return (
        <article className="space-y-6" dir={props.direction}>
            <Head title={copy.headTitle} />

            <header className="flex flex-col gap-4 border-b border-border pb-5 md:flex-row md:items-start md:justify-between">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">
                        {copy.title}
                    </h1>
                    <p className="max-w-prose text-sm leading-relaxed text-muted-foreground">
                        {copy.description}
                    </p>
                </div>
                {props.canAct ? (
                    <Button
                        className="min-h-11 w-full gap-2 md:w-auto"
                        disabled={isNavigating}
                        onClick={() => applyQuery({}, false)}
                        type="button"
                        variant="outline"
                    >
                        <RotateCcw aria-hidden="true" className="size-4" />
                        <span>{copy.refresh}</span>
                    </Button>
                ) : null}
            </header>

            {queryFailed ? (
                <Alert variant="destructive">
                    <AlertTitle>{copy.errorTitle}</AlertTitle>
                    <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
                        <span>{copy.loadFailed}</span>
                        <Button
                            className="min-h-11"
                            onClick={() => visit(failedFilters ?? props.filters)}
                            type="button"
                            variant="outline"
                        >
                            {props.adminUi.common.retry}
                        </Button>
                    </AlertDescription>
                </Alert>
            ) : null}

            {result ? <ResultBanner copy={copy} onDismiss={() => setResult(null)} result={result} /> : null}

            <AdminFulfillmentToolbar
                adminUi={props.adminUi}
                filterOptions={props.filterOptions}
                filters={props.filters}
                isNavigating={isNavigating}
                onFilterChange={(next) => applyQuery(next)}
                onResetFilters={resetFilters}
            />

            <AdminFulfillmentTable
                adminUi={props.adminUi}
                canAct={props.canAct}
                canSeeCost={props.canSeeCost}
                currentDirection={props.filters.direction}
                currentSort={props.filters.sort}
                isFiltered={hasActiveFulfillmentFilters(props.filters)}
                isNavigating={isNavigating}
                items={props.items}
                locale={props.locale}
                onAction={startAction}
                onOpenRow={setOpenRow}
                onResetFilters={resetFilters}
                onSortChange={(sort: AdminFulfillmentSort, direction) =>
                    applyQuery({ direction, sort })
                }
                pendingRowId={submitting ? (pending?.row.id ?? null) : null}
            />

            <AdminFulfillmentPagination
                adminUi={props.adminUi}
                direction={props.direction}
                isNavigating={isNavigating}
                onPageChange={(page) => applyQuery({ page }, false)}
                onPerPageChange={(perPage) =>
                    applyQuery({ per_page: perPage as 15 | 25 | 50 | 100 })
                }
                pagination={props.pagination}
                perPageOptions={props.filterOptions.perPageOptions}
            />

            {openRow ? (
                <AdminFulfillmentDetailSheet
                    adminUi={props.adminUi}
                    canAct={props.canAct}
                    canSeeCost={props.canSeeCost}
                    isPending={submitting && pending?.row.id === openRow.id}
                    locale={props.locale}
                    onAction={startAction}
                    onClose={() => setOpenRow(null)}
                    orderUrlTemplate={props.orderUrlTemplate}
                    row={openRow}
                />
            ) : null}

            {pending ? (
                <AdminFulfillmentResendDialog
                    action={pending.action}
                    copy={copy}
                    isSubmitting={submitting}
                    onCancel={() => {
                        if (!submitting) {
                            setPending(null);
                        }
                    }}
                    onConfirm={confirmResend}
                    reasonCodes={props.filterOptions.reasonCodes}
                    row={pending.row}
                />
            ) : null}
        </article>
    );
}

/**
 * What the press did, said the way the database would say it.
 *
 * `queued` is the one that matters and the easiest to get wrong: a successful
 * send re-opens an outbox row and places nothing, so the banner says the alarm
 * stays open rather than congratulating anybody. A refusal says what was NOT
 * done - `AGENTS.md` Failures rule 3 - and never suggests the row changed.
 */
function ResultBanner({
    copy,
    onDismiss,
    result,
}: {
    copy: AdminFulfillmentPageProps['adminUi']['fulfillment'];
    onDismiss: () => void;
    result: ResendResult;
}) {
    // Only a supplier refusal reads as a failure. A busy lock and a stale row
    // are states, and colouring them red teaches an operator to ignore red.
    const tone = result.outcome === 'refused' ? 'destructive' : 'default';

    const [title, body] = (() => {
        switch (result.outcome) {
            case 'queued':
                return [copy.result.queuedTitle, copy.result.queuedBody];
            case 'resume_accepted':
                return [copy.result.resumeTitle, copy.result.resumeBody];
            case 'retry_accepted':
                return [copy.result.retryTitle, copy.result.retryBody];
            case 'refused':
                return [copy.result.refusedTitle, copy.result.refusedBody];
            // Neither of these is an error: the per-item lock refused a second
            // press, or the publisher already has the outbox row. Nothing was
            // sent twice, and the row is untouched.
            case 'busy':
            case 'in_flight':
                return [copy.result.busyTitle, copy.result.busyBody];
            default:
                return [copy.result.staleTitle, copy.result.staleBody];
        }
    })();

    return (
        <Alert variant={tone}>
            <CircleCheck aria-hidden="true" className="size-4" />
            <AlertTitle>{title}</AlertTitle>
            <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
                <span>{body}</span>
                <Button
                    className="min-h-11"
                    onClick={onDismiss}
                    type="button"
                    variant="outline"
                >
                    {copy.detail.close}
                </Button>
            </AlertDescription>
        </Alert>
    );
}
