import type { AdminReviewsQueryState } from '@/types/admin';

/**
 * Only non-default values reach the URL, so the canonical list address stays
 * `/admin/reviews` and a shared link carries exactly the filters it shows.
 */
export function buildReviewsQuery(
    state: AdminReviewsQueryState,
): Record<string, string> {
    const query: Record<string, string> = {};

    if (state.search && state.search.trim() !== '') {
        query.search = state.search.trim();
    }

    if (state.status && state.status !== 'all') {
        query.status = state.status;
    }

    if (state.rating && state.rating !== 'all') {
        query.rating = state.rating;
    }

    if (state.source && state.source !== 'all') {
        query.source = state.source;
    }

    if (state.per_page && state.per_page !== 15) {
        query.per_page = String(state.per_page);
    }

    if (state.page && state.page > 1) {
        query.page = String(state.page);
    }

    return query;
}

export function hasActiveReviewFilters(state: AdminReviewsQueryState): boolean {
    return (
        (state.search ?? '') !== '' ||
        (state.status ?? 'all') !== 'all' ||
        (state.rating ?? 'all') !== 'all' ||
        (state.source ?? 'all') !== 'all'
    );
}
