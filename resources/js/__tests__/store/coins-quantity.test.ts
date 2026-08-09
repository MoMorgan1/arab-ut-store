import { describe, expect, it } from 'vitest';

import {
    clampAndSnapQuantity,
    coinsConfiguratorReducer,
    createInitialConfiguratorState,
} from '@/components/configurator/coins/configurator-state';
import { formatCompactCoins } from '@/lib/money';

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
        expect(formatCompactCoins(input)).toBe(expected);
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
