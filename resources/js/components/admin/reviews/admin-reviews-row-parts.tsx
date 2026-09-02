import { Eye, EyeOff } from 'lucide-react';
import React from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import { Button } from '@/components/ui/button';
import type { AdminReviewRow, AdminTranslations } from '@/types/admin';

type ReviewCopy = AdminTranslations['reviews'];

export function reviewOrderUrl(template: string, publicId: string): string {
    return template.replace('__ID__', publicId);
}

export function StorefrontState({
    copy,
    review,
}: {
    copy: ReviewCopy;
    review: AdminReviewRow;
}) {
    if (review.isVisible) {
        return (
            <AdminBadge icon={Eye} variant="success">
                {copy.stateVisible}
            </AdminBadge>
        );
    }

    // A rating below four never reaches the storefront, so "hidden" alone would
    // mislead: there is no button here that would make it appear.
    return review.rating >= 4 ? (
        <AdminBadge icon={EyeOff} variant="warning">
            {copy.stateHidden}
        </AdminBadge>
    ) : (
        <AdminBadge icon={EyeOff} variant="neutral">
            {copy.stateBelowThreshold}
        </AdminBadge>
    );
}

export function SourceBadge({
    copy,
    review,
}: {
    copy: ReviewCopy;
    review: AdminReviewRow;
}) {
    return (
        <AdminBadge variant={review.source === 'customer' ? 'info' : 'neutral'}>
            {review.source === 'customer'
                ? copy.sourceCustomer
                : copy.sourceArchive}
        </AdminBadge>
    );
}

export function ReviewAction({
    canManage,
    copy,
    onToggleVisibility,
    review,
}: {
    canManage: boolean;
    copy: ReviewCopy;
    onToggleVisibility: (review: AdminReviewRow) => void;
    review: AdminReviewRow;
}) {
    if (!canManage || (!review.isVisible && review.rating < 4)) {
        return <span className="text-sm text-muted-foreground">—</span>;
    }

    return (
        <Button
            className={
                review.isVisible
                    ? 'min-h-11 min-w-11 gap-1.5 text-xs font-medium'
                    : 'min-h-11 min-w-11 gap-1.5 text-xs font-medium'
            }
            onClick={() => onToggleVisibility(review)}
            type="button"
            variant={review.isVisible ? 'outline' : 'default'}
        >
            {review.isVisible ? (
                <>
                    <EyeOff aria-hidden="true" className="size-4" />
                    <span>{copy.hideFromStore}</span>
                </>
            ) : (
                <>
                    <Eye aria-hidden="true" className="size-4" />
                    <span>{copy.showInStore}</span>
                </>
            )}
        </Button>
    );
}
