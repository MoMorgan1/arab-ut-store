import { describe, expect, it } from 'vitest';

import {
    getInitialCoinsConfig,
    getInitialFutChampionsConfig,
    getInitialRivalsRoute,
    getQueryString,
    readBooleanQueryParam,
    readNumericQueryParam,
    readQueryParam,
} from '@/lib/query-params';
import type { CoinsPlatformOption } from '@/types/coins';
import type { Division } from '@/types/manual-services';

const ladder: Division[] = ['7', '6', '5', '4', '3', '2', '1', 'elite'];
const rankOptions = [1, 2, 3, 4, 5, 6].map((rank) => ({ rank }));
const platforms: CoinsPlatformOption[] = [
    {
        deliveries: [
            {
                label: 'Normal',
                maximum: 2_000_000,
                minutesPerMillion: 150,
                value: 'normal',
            },
            {
                label: 'Fast',
                maximum: 20_000_000,
                minutesPerMillion: 45,
                value: 'fast',
            },
        ],
        iconUrls: ['/ps.webp', '/xbox.webp'],
        label: 'PlayStation and Xbox',
        maximum: 20_000_000,
        value: 'playstation',
    },
    {
        deliveries: [],
        iconUrls: ['/pc.svg'],
        label: 'PC',
        maximum: 2_000_000,
        value: 'pc',
    },
];
const amount = {
    tiers: [
        { upTo: 500_000, step: 10_000 },
        { upTo: 2_000_000, step: 50_000 },
        { upTo: 20_000_000, step: 250_000 },
    ],
    minimum: 50_000,
    roundingUnit: 5_000,
    presets: [50_000, 100_000, 500_000, 1_000_000],
};

describe('Query parameter helpers', () => {
    describe('getQueryString', () => {
        it('extracts query string correctly from various URL formats', () => {
            expect(getQueryString('?a=1&b=2')).toBe('a=1&b=2');
            expect(
                getQueryString(
                    '/rivals?currentDivision=5&targetDivision=elite',
                ),
            ).toBe('currentDivision=5&targetDivision=elite');
            expect(
                getQueryString('https://example.com/coins?platform=pc#hash'),
            ).toBe('platform=pc');
            expect(getQueryString('a=1&b=2')).toBe('a=1&b=2');
            expect(getQueryString('')).toBe('');
            expect(getQueryString('?')).toBe('');
            expect(getQueryString(null)).toBe('');
            expect(getQueryString(undefined)).toBe('');
            expect(getQueryString(12345)).toBe('');
            expect(getQueryString({ foo: 'bar' })).toBe('');
        });
    });

    describe('readQueryParam', () => {
        const allowed = ['playstation', 'pc'] as const;

        it('returns matching value when parameter is valid and allowed', () => {
            expect(readQueryParam('?platform=pc', 'platform', allowed)).toBe(
                'pc',
            );
            expect(
                readQueryParam('?platform=playstation', 'platform', allowed),
            ).toBe('playstation');
        });

        it('returns undefined when parameter is missing or empty', () => {
            expect(
                readQueryParam('?other=1', 'platform', allowed),
            ).toBeUndefined();
            expect(
                readQueryParam('?platform=', 'platform', allowed),
            ).toBeUndefined();
            expect(
                readQueryParam('?platform', 'platform', allowed),
            ).toBeUndefined();
            expect(readQueryParam('', 'platform', allowed)).toBeUndefined();
            expect(readQueryParam(null, 'platform', allowed)).toBeUndefined();
        });

        it('returns undefined when value is out of allowed set', () => {
            expect(
                readQueryParam('?platform=xbox', 'platform', allowed),
            ).toBeUndefined();
            expect(
                readQueryParam('?platform=nintendo', 'platform', allowed),
            ).toBeUndefined();
        });

        it('supports ReadonlySet', () => {
            const set = new Set(['playstation', 'pc'] as const);
            expect(readQueryParam('?platform=pc', 'platform', set)).toBe('pc');
            expect(
                readQueryParam('?platform=xbox', 'platform', set),
            ).toBeUndefined();
        });

        it('never throws on hostile or malformed input', () => {
            expect(
                readQueryParam('?platform=%E0%A4%A', 'platform', allowed),
            ).toBeUndefined();
            expect(
                readQueryParam('?platform=%%25', 'platform', allowed),
            ).toBeUndefined();
            expect(
                readQueryParam('?__proto__=polluted', '__proto__', [
                    'polluted',
                ] as const),
            ).toBe('polluted');
            expect(
                readQueryParam(
                    '?platform=' + 'a'.repeat(100_000),
                    'platform',
                    allowed,
                ),
            ).toBeUndefined();
            expect(
                readQueryParam(
                    '?platform=playstation\u0000&extra=1',
                    'platform',
                    allowed,
                ),
            ).toBeUndefined();
            expect(readQueryParam('?platform=pc', '', allowed)).toBeUndefined();
            expect(
                readQueryParam('?platform=pc', null, allowed),
            ).toBeUndefined();
        });
    });

    describe('readNumericQueryParam', () => {
        it('parses valid integer parameter correctly', () => {
            expect(readNumericQueryParam('?rank=3', 'rank')).toBe(3);
            expect(readNumericQueryParam('?quantity=500000', 'quantity')).toBe(
                500_000,
            );
            expect(
                readNumericQueryParam('?rank=1', 'rank', [1, 2, 3, 4, 5, 6]),
            ).toBe(1);
        });

        it('returns undefined for non-numeric or float inputs', () => {
            expect(readNumericQueryParam('?rank=3.5', 'rank')).toBeUndefined();
            expect(readNumericQueryParam('?rank=abc', 'rank')).toBeUndefined();
            expect(readNumericQueryParam('?rank=3e4', 'rank')).toBeUndefined();
            expect(readNumericQueryParam('?rank=0x10', 'rank')).toBeUndefined();
            expect(readNumericQueryParam('?rank=', 'rank')).toBeUndefined();
            expect(readNumericQueryParam('', 'rank')).toBeUndefined();
            expect(readNumericQueryParam(null, 'rank')).toBeUndefined();
        });

        it('validates against allowed numbers when specified', () => {
            expect(
                readNumericQueryParam('?rank=7', 'rank', [1, 2, 3, 4, 5, 6]),
            ).toBeUndefined();
            expect(
                readNumericQueryParam('?rank=0', 'rank', [1, 2, 3, 4, 5, 6]),
            ).toBeUndefined();
        });

        it('never throws on hostile or malformed input', () => {
            expect(readNumericQueryParam('?rank=%zz', 'rank')).toBeUndefined();
            expect(
                readNumericQueryParam('?rank=' + '9'.repeat(100), 'rank'),
            ).toBeUndefined();
        });
    });

    describe('readBooleanQueryParam', () => {
        it.each([
            ['?urgent=true', true],
            ['?urgent=1', true],
            ['?urgent=TRUE', true],
            ['?urgent=false', false],
            ['?urgent=0', false],
            ['?urgent=FALSE', false],
        ])('parses %s as %s', (query, expected) => {
            expect(readBooleanQueryParam(query, 'urgent')).toBe(expected);
        });

        it.each([
            ['?urgent=yes'],
            ['?urgent=no'],
            ['?urgent=2'],
            ['?urgent=maybe'],
            ['?urgent='],
            ['?other=1'],
            [''],
            [null],
            [undefined],
        ])('returns undefined for invalid boolean %s', (query) => {
            expect(readBooleanQueryParam(query, 'urgent')).toBeUndefined();
        });
    });

    describe('getInitialRivalsRoute', () => {
        it('applies valid route on ladder', () => {
            expect(
                getInitialRivalsRoute(
                    '?currentDivision=6&targetDivision=2',
                    ladder,
                ),
            ).toEqual({ from: '6', to: '2' });
            expect(
                getInitialRivalsRoute(
                    '?currentDivision=7&targetDivision=6',
                    ladder,
                ),
            ).toEqual({ from: '7', to: '6' });
            expect(
                getInitialRivalsRoute(
                    '?currentDivision=5&targetDivision=elite',
                    ladder,
                ),
            ).toEqual({ from: '5', to: 'elite' });
            expect(
                getInitialRivalsRoute(
                    '?currentDivision=1&targetDivision=elite',
                    ladder,
                ),
            ).toEqual({ from: '1', to: 'elite' });
        });

        it('ignores reverse route wholesale and preserves defaults', () => {
            expect(
                getInitialRivalsRoute(
                    '?currentDivision=2&targetDivision=6',
                    ladder,
                ),
            ).toEqual({ from: '5', to: 'elite' });
            expect(
                getInitialRivalsRoute(
                    '?currentDivision=elite&targetDivision=1',
                    ladder,
                ),
            ).toEqual({ from: '5', to: 'elite' });
        });

        it('ignores same division route wholesale and preserves defaults', () => {
            expect(
                getInitialRivalsRoute(
                    '?currentDivision=3&targetDivision=3',
                    ladder,
                ),
            ).toEqual({ from: '5', to: 'elite' });
            expect(
                getInitialRivalsRoute(
                    '?currentDivision=elite&targetDivision=elite',
                    ladder,
                ),
            ).toEqual({ from: '5', to: 'elite' });
        });

        it('ignores half-valid pair when one parameter is missing', () => {
            expect(getInitialRivalsRoute('?currentDivision=6', ladder)).toEqual(
                { from: '5', to: 'elite' },
            );
            expect(getInitialRivalsRoute('?targetDivision=2', ladder)).toEqual({
                from: '5',
                to: 'elite',
            });
        });

        it('ignores half-valid pair when one parameter is out-of-ladder', () => {
            expect(
                getInitialRivalsRoute(
                    '?currentDivision=6&targetDivision=banana',
                    ladder,
                ),
            ).toEqual({ from: '5', to: 'elite' });
            expect(
                getInitialRivalsRoute(
                    '?currentDivision=99&targetDivision=2',
                    ladder,
                ),
            ).toEqual({ from: '5', to: 'elite' });
        });

        it('never throws on hostile input and keeps defaults', () => {
            expect(
                getInitialRivalsRoute(
                    '?currentDivision=%E0%A4%A&__proto__=polluted',
                    ladder,
                ),
            ).toEqual({ from: '5', to: 'elite' });
        });
    });

    describe('getInitialFutChampionsConfig', () => {
        it('applies valid rank and urgent option', () => {
            expect(
                getInitialFutChampionsConfig(
                    '?rank=1&urgent=true',
                    rankOptions,
                ),
            ).toEqual({ rank: 1, urgent: true });
            expect(
                getInitialFutChampionsConfig('?rank=6&urgent=1', rankOptions),
            ).toEqual({ rank: 6, urgent: true });
            expect(
                getInitialFutChampionsConfig('?rank=2&urgent=0', rankOptions),
            ).toEqual({ rank: 2, urgent: false });
            expect(
                getInitialFutChampionsConfig(
                    '?rank=4&urgent=false',
                    rankOptions,
                ),
            ).toEqual({ rank: 4, urgent: false });
        });

        it('degrades missing or invalid values independently to defaults', () => {
            expect(
                getInitialFutChampionsConfig(
                    '?rank=99&urgent=true',
                    rankOptions,
                ),
            ).toEqual({ rank: 3, urgent: true });
            expect(
                getInitialFutChampionsConfig(
                    '?rank=2&urgent=banana',
                    rankOptions,
                ),
            ).toEqual({ rank: 2, urgent: false });
            expect(
                getInitialFutChampionsConfig('?rank=abc', rankOptions),
            ).toEqual({ rank: 3, urgent: false });
            expect(getInitialFutChampionsConfig('', rankOptions)).toEqual({
                rank: 3,
                urgent: false,
            });
        });
    });

    describe('getInitialCoinsConfig', () => {
        it('prefills PC platform and valid quantity, landing on amount step', () => {
            expect(
                getInitialCoinsConfig(
                    '?platform=pc&quantity=500000',
                    amount,
                    platforms,
                ),
            ).toEqual({
                deliveryValue: null,
                lastValidQuantity: 500_000,
                platformValue: 'pc',
                step: 'amount',
            });
        });

        it('prefills PlayStation platform, fast delivery, and valid quantity, landing on amount step', () => {
            expect(
                getInitialCoinsConfig(
                    '?platform=playstation&delivery=fast&quantity=1000000',
                    amount,
                    platforms,
                ),
            ).toEqual({
                deliveryValue: 'fast',
                lastValidQuantity: 1_000_000,
                platformValue: 'playstation',
                step: 'amount',
            });
        });

        it('prefills PlayStation platform without delivery, landing on delivery step', () => {
            expect(
                getInitialCoinsConfig(
                    '?platform=playstation',
                    amount,
                    platforms,
                ),
            ).toEqual({
                deliveryValue: null,
                lastValidQuantity: 50_000,
                platformValue: 'playstation',
                step: 'delivery',
            });
        });

        it('degrades invalid quantity exceeding platform maximum to minimum default', () => {
            expect(
                getInitialCoinsConfig(
                    '?platform=pc&quantity=50000000',
                    amount,
                    platforms,
                ),
            ).toEqual({
                deliveryValue: null,
                lastValidQuantity: 50_000,
                platformValue: 'pc',
                step: 'amount',
            });
        });

        it('degrades invalid quantity below minimum or non-step to minimum default', () => {
            expect(
                getInitialCoinsConfig(
                    '?platform=pc&quantity=35000',
                    amount,
                    platforms,
                ),
            ).toEqual({
                deliveryValue: null,
                lastValidQuantity: 50_000,
                platformValue: 'pc',
                step: 'amount',
            });
            expect(
                getInitialCoinsConfig(
                    '?platform=pc&quantity=55555',
                    amount,
                    platforms,
                ),
            ).toEqual({
                deliveryValue: null,
                lastValidQuantity: 50_000,
                platformValue: 'pc',
                step: 'amount',
            });
        });

        it('ignores invalid platform and stays on platform step', () => {
            expect(
                getInitialCoinsConfig(
                    '?platform=nintendo&quantity=500000',
                    amount,
                    platforms,
                ),
            ).toEqual({
                deliveryValue: null,
                lastValidQuantity: 500_000,
                platformValue: null,
                step: 'platform',
            });
        });
    });
});
