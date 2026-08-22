import type { AdminCustomersQueryState } from '@/types/admin';

export function buildCustomersQuery(
    filters: Partial<AdminCustomersQueryState>,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    if (filters.search !== undefined && filters.search !== null) {
        const trimmed = filters.search.trim();

        if (trimmed !== '') {
            query.search = trimmed;
        }
    }

    if (filters.status) {
        query.status = filters.status;
    }

    if (filters.date_from) {
        query.date_from = filters.date_from;
    }

    if (filters.date_to) {
        query.date_to = filters.date_to;
    }

    if (filters.sort && filters.sort !== 'created_at') {
        query.sort = filters.sort;
    }

    if (filters.direction && filters.direction !== 'desc') {
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

export function hasActiveCustomerFilters(
    filters: Partial<AdminCustomersQueryState>,
): boolean {
    return Boolean(
        (filters.search && filters.search.trim() !== '') ||
        filters.status ||
        filters.date_from ||
        filters.date_to,
    );
}
