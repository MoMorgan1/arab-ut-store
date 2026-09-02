import { Link } from '@inertiajs/react';
import React from 'react';

import {
    ReviewAction,
    reviewOrderUrl,
    SourceBadge,
    StorefrontState,
} from '@/components/admin/reviews/admin-reviews-row-parts';
import AdminReviewStars from '@/components/admin/reviews/admin-reviews-stars';
import type { AdminReviewRow, AdminTranslations } from '@/types/admin';

export type AdminReviewsMobileCardProps = {
    adminUi: AdminTranslations;
    canManage: boolean;
    dateFormatter: Intl.DateTimeFormat;
    onToggleVisibility: (review: AdminReviewRow) => void;
    orderUrlTemplate: string;
    review: AdminReviewRow;
};

export default function AdminReviewsMobileCard({
    adminUi,
    canManage,
    dateFormatter,
    onToggleVisibility,
    orderUrlTemplate,
    review,
}: AdminReviewsMobileCardProps) {
    const copy = adminUi.reviews;

    return (
        <article
            className="flex flex-col gap-2.5 rounded-lg border border-border bg-card p-3.5 text-card-foreground"
            role="listitem"
        >
            <div className="flex flex-wrap items-start justify-between gap-2 border-b border-border/60 pb-2.5">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <span className="text-sm font-bold text-foreground">
                        <bdi>{review.reviewerName}</bdi>
                    </span>
                    <span className="text-xs text-muted-foreground">
                        <bdi>{review.reviewerLocation ?? '—'}</bdi>
                    </span>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                    <AdminReviewStars
                        label={copy.ratingValue.replace(
                            ':rating',
                            String(review.rating),
                        )}
                        rating={review.rating}
                    />
                    <StorefrontState copy={copy} review={review} />
                </div>
            </div>

            <p
                className="line-clamp-3 text-sm text-muted-foreground"
                dir={review.bodyLocale === 'ar' ? 'rtl' : 'ltr'}
            >
                {review.excerpt || '—'}
            </p>

            <div className="grid grid-cols-2 gap-2 border-t border-border/40 pt-2.5 text-xs">
                <div>
                    <span className="text-muted-foreground">
                        {copy.order}:{' '}
                    </span>
                    {review.order ? (
                        <Link
                            className="font-semibold text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            href={reviewOrderUrl(
                                orderUrlTemplate,
                                review.order.publicId,
                            )}
                        >
                            <bdi>{review.order.number}</bdi>
                        </Link>
                    ) : (
                        <span className="font-semibold text-foreground">—</span>
                    )}
                </div>
                <div>
                    <span className="text-muted-foreground">
                        {copy.createdAt}:{' '}
                    </span>
                    <span className="font-semibold text-foreground tabular-nums">
                        <bdi>
                            {review.createdAt
                                ? dateFormatter.format(
                                      new Date(review.createdAt),
                                  )
                                : '—'}
                        </bdi>
                    </span>
                </div>
            </div>

            <div className="flex items-center justify-between gap-2 border-t border-border/60 pt-2.5">
                <SourceBadge copy={copy} review={review} />
                <ReviewAction
                    canManage={canManage}
                    copy={copy}
                    onToggleVisibility={onToggleVisibility}
                    review={review}
                />
            </div>
        </article>
    );
}
