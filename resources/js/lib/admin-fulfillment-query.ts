import type { AdminFulfillmentQueryState } from '@/types/admin';

/**
 * The URL this filter state asks for, with the defaults left out.
 *
 * Same shape as `buildOrdersQuery`, and omitting defaults for the same reason:
 * a durable query string an operator can paste into chat should carry what
 * they chose, not the whole form. The two defaults that differ from the orders
 * list are the sort (`paid_at`, the only clock every row has) and its
 * direction (`asc`, because this is a queue and a queue is read from the
 * front).
 */
export function buildFulfillmentQuery(
    filters: Partial<AdminFulfillmentQueryState>,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    if (filters.search !== undefined && filters.search !== null) {
        const trimmed = filters.search.trim();

        if (trimmed !== '') {
            query.search = trimmed;
        }
    }

    for (const key of [
        'supplier',
        'phase',
        'status',
        'alarm',
        'hold',
        'service',
        'paid_from',
        'paid_to',
    ] as const) {
        const value = filters[key];

        if (value) {
            query[key] = value;
        }
    }

    if (filters.sort && filters.sort !== 'paid_at') {
        query.sort = filters.sort;
    }

    if (filters.direction && filters.direction !== 'asc') {
        query.direction = filters.direction;
    }

    if (filters.per_page && Number(filters.per_page) !== 15) {
        query.per_page = Number(filters.per_page);
    }

    if (filters.page && Number(filters.page) > 1) {
        query.page = Number(filters.page);
    }

    return query;
}

export function hasActiveFulfillmentFilters(
    filters: Partial<AdminFulfillmentQueryState>,
): boolean {
    return Boolean(
        (filters.search && filters.search.trim() !== '') ||
        filters.supplier ||
        filters.phase ||
        filters.status ||
        filters.alarm ||
        filters.hold ||
        filters.service ||
        filters.paid_from ||
        filters.paid_to,
    );
}
