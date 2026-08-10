import { describe, expect, it } from 'vitest';

import {
    parseCoinsQuoteSchedules,
    quoteFromSchedule,
} from '@/lib/coins-quote-schedule';
import type { CoinsPlatformOption, CoinsQuoteSchedule } from '@/types/coins';

const amount = {
    increment: 10_000,
    minimum: 50_000,
    presets: [50_000, 100_000, 500_000, 1_000_000],
};

const platforms: CoinsPlatformOption[] = [
    {
        deliveries: [
            {
                label: 'Normal',
                maximum: 70_000,
                minutesPerMillion: 150,
                value: 'normal',
            },
            {
                label: 'Fast',
                maximum: 80_000,
                minutesPerMillion: 45,
                value: 'fast',
            },
        ],
        iconUrls: [],
        label: 'PS / Xbox',
        maximum: 80_000,
        value: 'playstation',
    },
    {
        deliveries: [],
        iconUrls: [],
        label: 'PC',
        maximum: 70_000,
        value: 'pc',
    },
];

function schedule(
    platform: 'playstation' | 'pc',
    delivery: 'normal' | 'fast' | null,
    maximum: number,
): CoinsQuoteSchedule {
    const entryCount = (maximum - amount.minimum) / amount.increment + 1;

    return {
        delivery,
        displayCurrency: 'USD',
        displayTotalsMinor: Array.from(
            { length: entryCount },
            (_, index) => 100 + index,
        ),
        increment: amount.increment,
        market: platform === 'pc' ? 'pc' : 'console',
        maximum,
        minimum: amount.minimum,
        platform,
        pricedAt: '2026-08-10T12:00:00+00:00',
        priceVersion: 7,
        productId: '01K00000000000000000000000',
        totalsHalalah: Array.from(
            { length: entryCount },
            (_, index) => 500 + index * 100,
        ),
        variantId:
            platform === 'pc'
                ? '01K00000000000000000000001'
                : '01K00000000000000000000002',
    };
}

function schedules() {
    return {
        pc: schedule('pc', null, 70_000),
        'playstation:fast': schedule('playstation', 'fast', 80_000),
        'playstation:normal': schedule('playstation', 'normal', 70_000),
    };
}

describe('Coins quote schedule', () => {
    it.each([
        [50_000, 500, 100],
        [60_000, 600, 101],
        [70_000, 700, 102],
    ])(
        'returns the exact indexed quote for %i',
        (quantity, amountHalalah, amountMinor) => {
            const quote = quoteFromSchedule(schedules().pc, quantity);

            expect(quote).toMatchObject({
                delivery: null,
                displayTotal: { amountMinor, currency: 'USD' },
                platform: 'pc',
                priceVersion: 7,
                quantity,
                total: { amountHalalah, currency: 'SAR' },
            });
        },
    );

    it.each([49_999, 55_000, 70_001, 60_000.5, Number.NaN])(
        'rejects illegal quantity %s',
        (quantity) => {
            expect(quoteFromSchedule(schedules().pc, quantity)).toBeNull();
        },
    );

    it.each([
        ['short authoritative totals', { totalsHalalah: [500, 600] }],
        ['long display totals', { displayTotalsMinor: [100, 101, 102, 103] }],
        ['zero indexed authoritative total', { totalsHalalah: [500, 0, 700] }],
        [
            'unsafe indexed display total',
            { displayTotalsMinor: [100, Number.MAX_SAFE_INTEGER + 1, 102] },
        ],
        ['invalid display currency', { displayCurrency: 'US' }],
        ['invalid timestamp', { pricedAt: '2026-08-10T15:00:00+03:00' }],
        ['invalid product ULID', { productId: 'not-a-ulid' }],
        ['invalid price version', { priceVersion: 0 }],
    ])('fails closed on %s', (_, change) => {
        expect(
            quoteFromSchedule(
                { ...schedules().pc, ...change } as CoinsQuoteSchedule,
                60_000,
            ),
        ).toBeNull();
    });

    it('validates the complete homepage schedule contract once', () => {
        const parsed = parseCoinsQuoteSchedules(
            schedules(),
            'USD',
            amount,
            platforms,
        );

        expect(parsed.pc).not.toBeNull();
        expect(parsed['playstation:normal']).not.toBeNull();
        expect(parsed['playstation:fast']).not.toBeNull();
    });

    it('rejects a contract with an unexpected root key', () => {
        const parsed = parseCoinsQuoteSchedules(
            { ...schedules(), extra: schedules().pc },
            'USD',
            amount,
            platforms,
        );

        expect(parsed).toEqual({
            pc: null,
            'playstation:fast': null,
            'playstation:normal': null,
        });
    });

    it('rejects a contract with a missing root key', () => {
        const complete = schedules();
        const missingPc = {
            'playstation:fast': complete['playstation:fast'],
            'playstation:normal': complete['playstation:normal'],
        };
        const parsed = parseCoinsQuoteSchedules(
            missingPc,
            'USD',
            amount,
            platforms,
        );

        expect(parsed).toEqual({
            pc: null,
            'playstation:fast': null,
            'playstation:normal': null,
        });
    });

    it('fails only a schedule that contains an undeclared field', () => {
        const parsed = parseCoinsQuoteSchedules(
            {
                ...schedules(),
                pc: { ...schedules().pc, unexpected: 'not contracted' },
            },
            'USD',
            amount,
            platforms,
        );

        expect(parsed.pc).toBeNull();
        expect(parsed['playstation:normal']).not.toBeNull();
        expect(parsed['playstation:fast']).not.toBeNull();
    });

    it('fails the complete snapshot closed when schedule timestamps differ', () => {
        const parsed = parseCoinsQuoteSchedules(
            {
                ...schedules(),
                'playstation:fast': {
                    ...schedules()['playstation:fast'],
                    pricedAt: '2026-08-10T12:00:01Z',
                },
            },
            'USD',
            amount,
            platforms,
        );

        expect(parsed).toEqual({
            pc: null,
            'playstation:fast': null,
            'playstation:normal': null,
        });
    });

    it('fails the snapshot closed when the remaining valid schedules have different timestamps', () => {
        const parsed = parseCoinsQuoteSchedules(
            {
                ...schedules(),
                pc: { ...schedules().pc, unexpected: 'not contracted' },
                'playstation:fast': {
                    ...schedules()['playstation:fast'],
                    pricedAt: '2026-08-10T12:00:01Z',
                },
            },
            'USD',
            amount,
            platforms,
        );

        expect(parsed).toEqual({
            pc: null,
            'playstation:fast': null,
            'playstation:normal': null,
        });
    });

    it.each([
        ['display currency', { displayCurrency: 'EUR' }],
        ['minimum', { minimum: 40_000 }],
        ['maximum', { maximum: 80_000 }],
        ['increment', { increment: 5_000 }],
        ['market', { market: 'console' }],
        ['delivery', { delivery: 'fast' }],
        ['platform', { platform: 'playstation' }],
        ['unsafe unselected total', { totalsHalalah: [500, 600, 0] }],
    ])('fails only the malformed PC mode on mismatched %s', (_, change) => {
        const parsed = parseCoinsQuoteSchedules(
            {
                ...schedules(),
                pc: { ...schedules().pc, ...change },
            },
            'USD',
            amount,
            platforms,
        );

        expect(parsed.pc).toBeNull();
        expect(parsed['playstation:normal']).not.toBeNull();
        expect(parsed['playstation:fast']).not.toBeNull();
    });
});
