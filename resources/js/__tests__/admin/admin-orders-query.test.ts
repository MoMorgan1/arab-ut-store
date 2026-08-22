import { describe, expect, it } from 'vitest';

import { buildOrdersQuery, hasActiveFilters } from '@/lib/admin-orders-query';

describe('admin-orders-query', () => {
    describe('buildOrdersQuery', () => {
        it('returns empty object when all filters are default or empty', () => {
            const query = buildOrdersQuery({
                search: '',
                status: null,
                service: null,
                platform: null,
                payment_status: null,
                date_from: null,
                date_to: null,
                sort: 'placed_at',
                direction: 'desc',
                per_page: 15,
                page: 1,
            });

            expect(query).toEqual({});
        });

        it('includes trimmed search and active filters', () => {
            const query = buildOrdersQuery({
                search: '  AUT-1001  ',
                status: 'received',
                service: 'coins',
                platform: 'playstation',
                payment_status: 'paid',
                date_from: '2026-08-01',
                date_to: '2026-08-20',
                sort: 'total',
                direction: 'asc',
                per_page: 50,
                page: 2,
            });

            expect(query).toEqual({
                search: 'AUT-1001',
                status: 'received',
                service: 'coins',
                platform: 'playstation',
                payment_status: 'paid',
                date_from: '2026-08-01',
                date_to: '2026-08-20',
                sort: 'total',
                direction: 'asc',
                per_page: 50,
                page: 2,
            });
        });

        it('ignores whitespace-only search string', () => {
            const query = buildOrdersQuery({
                search: '     ',
                status: 'completed',
            });

            expect(query).toEqual({
                status: 'completed',
            });
            expect(query).not.toHaveProperty('search');
        });
    });

    describe('hasActiveFilters', () => {
        it('returns false when no filter is active', () => {
            expect(
                hasActiveFilters({
                    search: '',
                    status: null,
                    service: null,
                    platform: null,
                    payment_status: null,
                    date_from: null,
                    date_to: null,
                }),
            ).toBe(false);
        });

        it('returns true when search is non-empty', () => {
            expect(hasActiveFilters({ search: 'AUT' })).toBe(true);
        });

        it.each([
            ['status', { status: 'received' }],
            ['service', { service: 'coins' }],
            ['platform', { platform: 'pc' }],
            ['payment status', { payment_status: 'failed' }],
            ['start date', { date_from: '2026-08-01' }],
            ['end date', { date_to: '2026-08-20' }],
        ])('returns true when the %s filter is set', (_label, filters) => {
            expect(hasActiveFilters(filters)).toBe(true);
        });
    });
});
