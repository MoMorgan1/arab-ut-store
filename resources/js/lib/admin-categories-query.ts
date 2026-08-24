import type { AdminCategoriesQueryState } from '@/types/admin';

export function buildCategoriesQuery(
    filters: Partial<AdminCategoriesQueryState>,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    if (filters.search !== undefined && filters.search !== null) {
        const trimmed = filters.search.trim();

        if (trimmed !== '') {
            query.search = trimmed;
        }
    }

    if (filters.visibility) {
        query.visibility = filters.visibility;
    }

    if (filters.source) {
        query.source = filters.source;
    }

    if (filters.sort && filters.sort !== 'sort_order') {
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

export function hasActiveCategoryFilters(
    filters: Partial<AdminCategoriesQueryState>,
): boolean {
    return Boolean(
        (filters.search && filters.search.trim() !== '') ||
        filters.visibility ||
        filters.source,
    );
}
