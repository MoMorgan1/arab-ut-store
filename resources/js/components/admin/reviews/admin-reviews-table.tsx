import { Link } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import React from 'react';

import AdminReviewsMobileCard from '@/components/admin/reviews/admin-reviews-mobile-card';
import {
    ReviewAction,
    reviewOrderUrl,
    SourceBadge,
    StorefrontState,
} from '@/components/admin/reviews/admin-reviews-row-parts';
import AdminReviewStars from '@/components/admin/reviews/admin-reviews-stars';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { DATE_LOCALE } from '@/lib/date-locale';
import type { AdminReviewRow, AdminTranslations } from '@/types/admin';

export type AdminReviewsTableProps = {
    adminUi: AdminTranslations;
    canManage: boolean;
    isFiltered: boolean;
    isNavigating: boolean;
    onToggleVisibility: (review: AdminReviewRow) => void;
    orderUrlTemplate: string;
    reviews: AdminReviewRow[];
};

export default function AdminReviewsTable({
    adminUi,
    canManage,
    isFiltered,
    isNavigating,
    onToggleVisibility,
    orderUrlTemplate,
    reviews,
}: AdminReviewsTableProps) {
    const copy = adminUi.reviews;
    const dateFormatter = new Intl.DateTimeFormat(DATE_LOCALE, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    return (
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

            <div
                aria-label={copy.tableLabel}
                className="hidden rounded-lg border border-border bg-card shadow-xs md:block"
                role="region"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{copy.reviewer}</TableHead>
                            <TableHead>{copy.rating}</TableHead>
                            <TableHead>{copy.comment}</TableHead>
                            <TableHead>{copy.order}</TableHead>
                            <TableHead>{copy.source}</TableHead>
                            <TableHead>{copy.status}</TableHead>
                            <TableHead>{copy.createdAt}</TableHead>
                            <TableHead>{copy.actions}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {reviews.length > 0 ? (
                            reviews.map((review) => (
                                <TableRow key={review.id}>
                                    <TableCell>
                                        <span className="flex min-w-0 flex-col gap-0.5">
                                            <strong className="text-sm font-semibold text-foreground">
                                                <bdi>{review.reviewerName}</bdi>
                                            </strong>
                                            <small className="text-xs text-muted-foreground">
                                                <bdi>
                                                    {review.reviewerLocation ??
                                                        '—'}
                                                </bdi>
                                            </small>
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <AdminReviewStars
                                            label={copy.ratingValue.replace(
                                                ':rating',
                                                String(review.rating),
                                            )}
                                            rating={review.rating}
                                        />
                                    </TableCell>
                                    <TableCell className="max-w-[22rem]">
                                        <span
                                            className="block truncate text-sm text-muted-foreground"
                                            dir={
                                                review.bodyLocale === 'ar'
                                                    ? 'rtl'
                                                    : 'ltr'
                                            }
                                            title={review.excerpt}
                                        >
                                            {review.excerpt || '—'}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        {review.order ? (
                                            <Link
                                                className="text-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                                href={reviewOrderUrl(
                                                    orderUrlTemplate,
                                                    review.order.publicId,
                                                )}
                                            >
                                                <bdi>{review.order.number}</bdi>
                                            </Link>
                                        ) : (
                                            <span className="text-sm text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <SourceBadge
                                            copy={copy}
                                            review={review}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <StorefrontState
                                            copy={copy}
                                            review={review}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <span className="text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                            <bdi>
                                                {review.createdAt
                                                    ? dateFormatter.format(
                                                          new Date(
                                                              review.createdAt,
                                                          ),
                                                      )
                                                    : '—'}
                                            </bdi>
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <ReviewAction
                                            canManage={canManage}
                                            copy={copy}
                                            onToggleVisibility={
                                                onToggleVisibility
                                            }
                                            review={review}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))
                        ) : (
                            <TableRow>
                                <TableCell
                                    className="h-32 text-center"
                                    colSpan={8}
                                >
                                    <p className="text-sm text-muted-foreground">
                                        {isFiltered
                                            ? copy.noReviewsMatching
                                            : copy.noReviews}
                                    </p>
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>

            <div
                aria-label={copy.tableLabel}
                className="flex flex-col gap-3 md:hidden"
                role="list"
            >
                {reviews.length > 0 ? (
                    reviews.map((review) => (
                        <AdminReviewsMobileCard
                            adminUi={adminUi}
                            canManage={canManage}
                            dateFormatter={dateFormatter}
                            key={review.id}
                            onToggleVisibility={onToggleVisibility}
                            orderUrlTemplate={orderUrlTemplate}
                            review={review}
                        />
                    ))
                ) : (
                    <div className="rounded-lg border border-border bg-card p-6 text-center text-muted-foreground">
                        <p className="text-sm">
                            {isFiltered
                                ? copy.noReviewsMatching
                                : copy.noReviews}
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}
