import { describe, expect, it } from 'vitest';

import {
    clampAndSnapQuantity,
    coinsConfiguratorReducer,
    createInitialConfiguratorState,
} from '@/components/configurator/coins/configurator-state';
import { formatCoins, formatCompactCoins, formatHalalah } from '@/lib/money';

const quote = {
    delivery: null,
    market: 'pc' as const,
    platform: 'pc' as const,
    pricedAt: '2026-08-09T12:00:00Z',
    productId: '01K00000000000000000000000',
    quantity: 50_000,
    total: { amountHalalah: 600, currency: 'SAR' as const },
    variantId: '01K00000000000000000000001',
};

describe('Coins quantity controls', () => {
    it.each([
        [1, 50_000],
        [54_999, 50_000],
        [55_000, 60_000],
        [2_004_999, 2_000_000],
    ])('clamps and snaps %i to %i', (input, expected) => {
        expect(clampAndSnapQuantity(input, 50_000, 2_000_000, 10_000)).toBe(
            expected,
        );
    });

    it('uses the selected delivery maximum', () => {
        expect(
            clampAndSnapQuantity(20_500_000, 50_000, 20_000_000, 10_000),
        ).toBe(20_000_000);
    });

    it.each([
        [50_000, '50K'],
        [500_000, '500K'],
        [1_000_000, '1M'],
        [5_000_000, '5M'],
    ])('formats %i as %s', (input, expected) => {
        expect(formatCompactCoins(input, 'en')).toBe(expected);
    });

    it.each(['ar', 'en'] as const)(
        'uses Latin digits for %s amount, compact, and SAR price output',
        (locale) => {
            const values = [
                formatCoins(1_234_567, locale),
                formatCompactCoins(500_000, locale),
                formatHalalah(650, 'SAR', locale),
            ];

            expect(values).toEqual(
                expect.arrayContaining([
                    '1,234,567',
                    '500K',
                    expect.stringContaining('6.50'),
                ]),
            );
            expect(values.join(' ')).toContain('SAR');
            expect(values.join(' ')).not.toMatch(/[٠-٩]/);
        },
    );

    it('preserves a successful quote as refreshing for valid quantity work', () => {
        const successful = {
            ...createInitialConfiguratorState(50_000),
            quoteState: { quote, status: 'success' as const },
            step: 'amount' as const,
        };
        const changed = coinsConfiguratorReducer(successful, {
            type: 'quantity-changed',
            validQuantity: 100_000,
            value: '100000',
        });
        const loading = coinsConfiguratorReducer(changed, {
            type: 'quote-loading',
        });

        expect(changed.quoteState).toEqual({
            quote,
            status: 'refreshing',
        });
        expect(loading.quoteState).toEqual({
            quote,
            status: 'refreshing',
        });
    });

    it('clears a retained quote on selection changes and quote errors', () => {
        const refreshing = {
            ...createInitialConfiguratorState(50_000),
            platformValue: 'pc' as const,
            quoteState: { quote, status: 'refreshing' as const },
            step: 'amount' as const,
        };
        const selectionChanged = coinsConfiguratorReducer(refreshing, {
            clampMessage: 'clamped',
            maximum: 20_000_000,
            selectionMessage: 'selected',
            type: 'platform-chosen',
            value: 'playstation',
        });
        const unavailable = coinsConfiguratorReducer(refreshing, {
            type: 'quote-unavailable',
        });

        expect(selectionChanged.quoteState).toEqual({ status: 'idle' });
        expect(unavailable.quoteState).toEqual({ status: 'unavailable' });
    });

    it('restores the last valid quantity when a commit follows invalid typing', () => {
        const initial = createInitialConfiguratorState(50_000);
        const typed = coinsConfiguratorReducer(initial, {
            type: 'quantity-changed',
            value: '',
            validQuantity: null,
        });
        const committed = coinsConfiguratorReducer(typed, {
            type: 'quantity-committed',
            value: typed.lastValidQuantity,
        });

        expect(committed.quantityInput).toBe('50000');
        expect(committed.lastValidQuantity).toBe(50_000);
    });
});
