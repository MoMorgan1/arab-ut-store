import type { AdminConversationsQueryState } from '@/types/admin';

export function buildConversationsQuery(
    filters: Partial<AdminConversationsQueryState>,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    if (filters.q !== undefined && filters.q !== null) {
        const trimmed = filters.q.trim();

        if (trimmed !== '') {
            query.q = trimmed;
        }
    }

    if (filters.status) {
        query.status = filters.status;
    }

    if (filters.locale) {
        query.locale = filters.locale;
    }

    if (filters.owner) {
        query.owner = filters.owner;
    }

    if (filters.per_page && Number(filters.per_page) !== 15) {
        query.per_page = Number(filters.per_page);
    }

    if (filters.page && Number(filters.page) > 1) {
        query.page = Number(filters.page);
    }

    return query;
}

export function hasActiveConversationFilters(
    filters: Partial<AdminConversationsQueryState>,
): boolean {
    return Boolean(
        (filters.q && filters.q.trim() !== '') ||
        filters.status ||
        filters.locale ||
        filters.owner,
    );
}
