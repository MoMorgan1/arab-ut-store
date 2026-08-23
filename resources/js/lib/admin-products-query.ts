import type { AdminProductsQueryState } from '@/types/admin';

export function buildProductsQuery(
    filters: Partial<AdminProductsQueryState>,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    if (filters.search !== undefined && filters.search !== null) {
        const trimmed = filters.search.trim();

        if (trimmed !== '') {
            query.search = trimmed;
        }
    }

    if (filters.service_type) {
        query.service_type = filters.service_type;
    }

    if (filters.authority) {
        query.authority = filters.authority;
    }

    if (filters.source) {
        query.source = filters.source;
    }

    if (filters.visibility) {
        query.visibility = filters.visibility;
    }

    if (filters.archived && filters.archived !== 'active') {
        query.archived = filters.archived;
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

export function hasActiveProductFilters(
    filters: Partial<AdminProductsQueryState>,
): boolean {
    return Boolean(
        (filters.search && filters.search.trim() !== '') ||
        filters.service_type ||
        filters.authority ||
        filters.source ||
        filters.visibility ||
        (filters.archived && filters.archived !== 'active'),
    );
}
