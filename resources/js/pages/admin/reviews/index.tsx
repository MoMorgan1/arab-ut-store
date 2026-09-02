import { Head, router } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import React, { useState } from 'react';

import AdminReviewVisibilityDialog from '@/components/admin/reviews/admin-review-visibility-dialog';
import AdminReviewsPagination from '@/components/admin/reviews/admin-reviews-pagination';
import AdminReviewsTable from '@/components/admin/reviews/admin-reviews-table';
import AdminReviewsToolbar from '@/components/admin/reviews/admin-reviews-toolbar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    buildReviewsQuery,
    hasActiveReviewFilters,
} from '@/lib/admin-reviews-query';
import type {
    AdminReviewRow,
    AdminReviewsPageProps,
    AdminReviewsQueryState,
} from '@/types/admin';

export default function AdminReviewsIndex(props: AdminReviewsPageProps) {
    const copy = props.adminUi.reviews;
    const canManage = props.permissions.includes('marketing.manage');

    const [isNavigating, setIsNavigating] = useState(false);
    const [queryFailed, setQueryFailed] = useState(false);
    const [feedbackMessage, setFeedbackMessage] = useState<string | null>(null);
    const [conflictAlert, setConflictAlert] = useState<string | null>(null);
    const [selectedReview, setSelectedReview] = useState<AdminReviewRow | null>(
        null,
    );
    const [dialogOpen, setDialogOpen] = useState(false);

    const visitReviews = (
        nextFilters: Partial<AdminReviewsQueryState>,
        preservePage = true,
    ) => {
        const merged: AdminReviewsQueryState = {
            ...props.filters,
            ...nextFilters,
        };

        if (!preservePage) {
            merged.page = 1;
        }

        setIsNavigating(true);
        setQueryFailed(false);
        setFeedbackMessage(null);
        setConflictAlert(null);

        router.get(window.location.pathname, buildReviewsQuery(merged), {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                setQueryFailed(true);
                setIsNavigating(false);
            },
            onFinish: () => {
                setIsNavigating(false);
            },
        });
    };

    const applyQuery = (
        patch: Partial<AdminReviewsQueryState>,
        preservePage = false,
    ) => {
        visitReviews(patch, preservePage);
    };

    const resetFilters = () => {
        visitReviews(
            {
                search: null,
                status: 'all',
                rating: 'all',
                source: 'all',
                per_page: 15,
                page: 1,
            },
            false,
        );
    };

    const handleToggleVisibility = (review: AdminReviewRow) => {
        setSelectedReview(review);
        setDialogOpen(true);
    };

    const handleVisibilitySuccess = (result: { visible: boolean }) => {
        setFeedbackMessage(
            result.visible
                ? copy.visibilityShownMessage
                : copy.visibilityHiddenMessage,
        );
        router.reload({ only: ['reviews'] });
    };

    // The 409 body reports the server's current state, but the reload below is
    // what resyncs the whole page, so the handler deliberately takes no argument.
    const handleVisibilityConflict = () => {
        setConflictAlert(copy.visibilityConflictError);
        router.reload({ only: ['reviews'] });
    };

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
                            onClick={() => visitReviews(props.filters)}
                            type="button"
                            variant="outline"
                        >
                            {props.adminUi.common.retry}
                        </Button>
                    </AlertDescription>
                </Alert>
            ) : null}

            <AdminReviewsToolbar
                adminUi={props.adminUi}
                filterOptions={props.filterOptions}
                filters={props.filters}
                isNavigating={isNavigating}
                key={JSON.stringify(props.filters)}
                onFilterChange={(filters) => applyQuery(filters)}
                onResetFilters={resetFilters}
            />

            <AdminReviewsTable
                adminUi={props.adminUi}
                canManage={canManage}
                isFiltered={hasActiveReviewFilters(props.filters)}
                isNavigating={isNavigating}
                onToggleVisibility={handleToggleVisibility}
                orderUrlTemplate={props.orderUrlTemplate}
                reviews={props.reviews}
            />

            <AdminReviewsPagination
                adminUi={props.adminUi}
                direction={props.direction}
                isNavigating={isNavigating}
                onPageChange={(page) => applyQuery({ page }, true)}
                onPerPageChange={(per_page) => applyQuery({ per_page })}
                pagination={props.pagination}
                perPageOptions={props.filterOptions.perPageOptions}
            />

            <AdminReviewVisibilityDialog
                adminUi={props.adminUi}
                onConflict={handleVisibilityConflict}
                onOpenChange={setDialogOpen}
                onSuccess={handleVisibilitySuccess}
                open={dialogOpen}
                review={selectedReview}
                visibilityUrlTemplate={props.visibilityUrlTemplate}
            />
        </article>
    );
}
