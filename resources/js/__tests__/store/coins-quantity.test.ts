import { describe, expect, it } from 'vitest';

import {
    clampAndSnapQuantity,
    coinsConfiguratorReducer,
    createInitialConfiguratorState,
} from '@/components/configurator/coins/configurator-state';
import {
    acceptsQuantity,
    nearestStopIndex,
    sliderStops,
} from '@/lib/coins-quantity';
import { formatCoins, formatCompactCoins, formatMinorUnits } from '@/lib/money';

const quote = {
    delivery: null,
    market: 'pc' as const,
    platform: 'pc' as const,
    pricedAt: '2026-08-09T12:00:00Z',
    productId: '01K00000000000000000000000',
    quantity: 50_000,
    displayTotal: { amountMinor: 160, currency: 'USD' },
    total: { amountHalalah: 600, currency: 'SAR' as const },
    variantId: '01K00000000000000000000001',
};

describe('Coins quantity controls', () => {
    it.each([
        [1, 50_000],
        [52_499, 50_000],
        [52_500, 55_000],
        // 155,000 sits between two band steps and survives untouched: the
        // bands move the slider, they no longer decide what can be bought.
        [155_000, 155_000],
        [2_004_999, 2_005_000],
    ])('clamps and rounds %i to %i', (input, expected) => {
        expect(clampAndSnapQuantity(input, 50_000, 20_000_000, 5_000)).toBe(
            expected,
        );
    });

    it('uses the selected delivery maximum', () => {
        expect(
            clampAndSnapQuantity(20_500_000, 50_000, 20_000_000, 5_000),
        ).toBe(20_000_000);
    });

    it('refuses bounds it cannot round against', () => {
        expect(() =>
            clampAndSnapQuantity(100_000, 50_000, 20_000_000, 0),
        ).toThrow(RangeError);
    });

    it('parks the slider thumb on the stop nearest a typed amount', () => {
        // Without this the thumb fell back to index zero and told the customer
        // their order had jumped to the minimum when it had not.
        const stops = sliderStops(
            50_000,
            [{ upTo: 500_000, step: 10_000 }],
            500_000,
        );

        expect(stops[nearestStopIndex(155_000, stops)]).toBe(160_000);
        expect(stops[nearestStopIndex(150_000, stops)]).toBe(150_000);
        expect(stops[nearestStopIndex(51_000, stops)]).toBe(50_000);
    });

    it.each([
        [155_000, true],
        [152_000, false],
        [45_000, false],
        [20_005_000, false],
    ])('accepts %i as buyable: %s', (quantity, expected) => {
        expect(acceptsQuantity(quantity, 50_000, 20_000_000, 5_000)).toBe(
            expected,
        );
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
        'uses Latin digits for exact %s amount, compact, and minor-unit output',
        (locale) => {
            const values = [
                formatCoins(1_234_567, locale),
                formatCompactCoins(500_000, locale),
                formatMinorUnits(1, 'EUR', locale),
                formatMinorUnits(650, 'SAR', locale),
            ];

            expect(values).toEqual(
                expect.arrayContaining([
                    '1,234,567',
                    '500K',
                    expect.stringContaining('0.01'),
                    expect.stringContaining('6.50'),
                ]),
            );
            expect(values.join(' ')).toContain('EUR');
            expect(values.join(' ')).toContain('SAR');
            expect(values.join(' ')).not.toMatch(/[٠-٩]/);
        },
    );

    it.each([
        ['USD', 123_456_789, '1,234,567.89'],
        ['EUR', 10, '0.10'],
        ['GBP', 1, '0.01'],
    ])(
        'formats %s minor units without binary division',
        (currency, amount, expected) => {
            expect(formatMinorUnits(amount, currency, 'en')).toContain(
                expected,
            );
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
