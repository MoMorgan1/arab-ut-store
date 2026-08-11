import { afterEach, describe, expect, it, vi } from 'vitest';

import { quoteCoins } from '@/lib/coins-api';

const validPcQuote = {
    delivery: null,
    market: 'pc',
    platform: 'pc',
    pricedAt: '2026-08-09T12:00:00Z',
    productId: '01K00000000000000000000000',
    quantity: 50_000,
    displayTotal: {
        amountMinor: 160,
        currency: 'USD',
    },
    total: {
        amountHalalah: 600,
        currency: 'SAR',
    },
    variantId: '01K00000000000000000000001',
};

function successfulResponse(data: Record<string, unknown>): Response {
    return new Response(JSON.stringify({ data }), {
        headers: { 'Content-Type': 'application/json' },
        status: 200,
    });
}

function failedResponse(payload: unknown, status = 503): Response {
    return new Response(JSON.stringify(payload), {
        headers: { 'Content-Type': 'application/json' },
        status,
    });
}

function requestPcQuote() {
    return quoteCoins({
        delivery: null,
        expectedDisplayCurrency: 'USD',
        platform: 'pc',
        quantity: 50_000,
        quoteUrl: '/en/coins/quote',
        signal: new AbortController().signal,
    });
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('quoteCoins response contract', () => {
    it('accepts a matching server-authoritative PC quote', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(successfulResponse(validPcQuote))),
        );

        await expect(requestPcQuote()).resolves.toEqual(validPcQuote);
    });

    it.each([
        ['missing display total', { ...validPcQuote, displayTotal: undefined }],
        [
            'zero display amount',
            {
                ...validPcQuote,
                displayTotal: { amountMinor: 0, currency: 'USD' },
            },
        ],
        [
            'unsafe display amount',
            {
                ...validPcQuote,
                displayTotal: {
                    amountMinor: Number.MAX_SAFE_INTEGER + 1,
                    currency: 'USD',
                },
            },
        ],
        [
            'mismatched display currency',
            {
                ...validPcQuote,
                displayTotal: { amountMinor: 160, currency: 'EUR' },
            },
        ],
        [
            'foreign halalah alias',
            {
                ...validPcQuote,
                displayTotal: { amountHalalah: 160, currency: 'USD' },
            },
        ],
    ])('fails closed on %s', async (_, data) => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(successfulResponse(data))),
        );

        await expect(requestPcQuote()).rejects.toMatchObject({
            code: 'coins_pricing_unavailable',
            status: 503,
        });
    });

    it('never sends display currency as quote input', async () => {
        const fetchMock = vi.fn((input: RequestInfo | URL) => {
            void input;

            return Promise.resolve(successfulResponse(validPcQuote));
        });
        vi.stubGlobal('fetch', fetchMock);

        await requestPcQuote();

        const url = new URL(
            String(fetchMock.mock.calls[0][0]),
            'https://arab-ut.test',
        );
        expect(url.searchParams.has('currency')).toBe(false);
    });

    it('accepts the UTC +00:00 timestamp emitted by the live quote endpoint', async () => {
        const quote = {
            ...validPcQuote,
            pricedAt: '2026-08-09T13:23:56+00:00',
        };

        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(successfulResponse(quote))),
        );

        await expect(requestPcQuote()).resolves.toEqual(quote);
    });

    it('reads the code from the nested backend error envelope', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    failedResponse({
                        error: {
                            code: 'coins_pricing_unavailable',
                            message: 'Pricing is unavailable.',
                        },
                    }),
                ),
            ),
        );

        await expect(requestPcQuote()).rejects.toMatchObject({
            code: 'coins_pricing_unavailable',
            status: 503,
        });
    });

    it.each([
        [
            'legacy top-level fields',
            { code: 'legacy_supplier_error', message: 'Unavailable' },
        ],
        ['missing message', { error: { code: 'supplier_error' } }],
        ['non-object error', { error: 'supplier_error' }],
    ])('fails closed on a malformed %s error envelope', async (_, payload) => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(failedResponse(payload))),
        );

        await expect(requestPcQuote()).rejects.toMatchObject({
            code: 'coins_pricing_unavailable',
            status: 503,
        });
    });

    it.each([
        ['requested platform', { ...validPcQuote, platform: 'playstation' }],
        ['supported platform set', { ...validPcQuote, platform: 'xbox' }],
        ['requested quantity', { ...validPcQuote, quantity: 100_000 }],
        ['platform and market tuple', { ...validPcQuote, market: 'console' }],
        ['PC delivery tuple', { ...validPcQuote, delivery: 'normal' }],
        [
            'positive amount',
            {
                ...validPcQuote,
                total: { amountHalalah: 0, currency: 'SAR' },
            },
        ],
        [
            'whole-SAR amount',
            {
                ...validPcQuote,
                total: { amountHalalah: 650, currency: 'SAR' },
            },
        ],
        ['product ULID', { ...validPcQuote, productId: 'not-a-ulid' }],
        [
            'variant ULID',
            { ...validPcQuote, variantId: '01K0000000000000000000000I' },
        ],
        [
            'UTC timestamp',
            { ...validPcQuote, pricedAt: '2026-08-09T15:00:00+03:00' },
        ],
        [
            'malformed UTC timestamp',
            { ...validPcQuote, pricedAt: '2026-08-09T12:00:00+00' },
        ],
        [
            'calendar-valid timestamp',
            { ...validPcQuote, pricedAt: '2026-02-30T12:00:00Z' },
        ],
    ])(
        'fails closed when a 200 response violates the %s contract',
        async (_, data) => {
            vi.stubGlobal(
                'fetch',
                vi.fn(() => Promise.resolve(successfulResponse(data))),
            );

            await expect(requestPcQuote()).rejects.toMatchObject({
                code: 'coins_pricing_unavailable',
                status: 503,
            });
        },
    );

    it('rejects a console quote whose delivery differs from the request', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    successfulResponse({
                        ...validPcQuote,
                        delivery: 'fast',
                        market: 'console',
                        platform: 'playstation',
                    }),
                ),
            ),
        );

        await expect(
            quoteCoins({
                delivery: 'normal',
                expectedDisplayCurrency: 'USD',
                platform: 'playstation',
                quantity: 50_000,
                quoteUrl: '/en/coins/quote',
                signal: new AbortController().signal,
            }),
        ).rejects.toMatchObject({
            code: 'coins_pricing_unavailable',
            status: 503,
        });
    });
});
