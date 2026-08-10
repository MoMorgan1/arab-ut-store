import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { useReducer } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    coinsConfiguratorReducer,
    createInitialConfiguratorState,
} from '@/components/configurator/coins/configurator-state';
import { useCoinsQuoteRequest } from '@/components/configurator/coins/use-coins-quote-request';

type Deferred<T> = {
    promise: Promise<T>;
    resolve: (value: T) => void;
};

function deferred<T>(): Deferred<T> {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((complete) => {
        resolve = complete;
    });

    return { promise, resolve };
}

function quoteResponse(quantity: number, amountMinor: number): Response {
    return new Response(
        JSON.stringify({
            data: {
                delivery: null,
                displayTotal: { amountMinor, currency: 'USD' },
                market: 'pc',
                platform: 'pc',
                pricedAt: '2026-08-10T12:00:00Z',
                productId: '01K00000000000000000000000',
                quantity,
                total: { amountHalalah: 600, currency: 'SAR' },
                variantId: '01K00000000000000000000001',
            },
        }),
        { headers: { 'Content-Type': 'application/json' }, status: 200 },
    );
}

function errorResponse(status: number): Response {
    return new Response(
        JSON.stringify({
            error: {
                code: 'coins_pricing_unavailable',
                message: 'Pricing is unavailable.',
            },
        }),
        { headers: { 'Content-Type': 'application/json' }, status },
    );
}

function QuoteRequestHarness({
    active = true,
    quantity = 50_000,
}: {
    active?: boolean;
    quantity?: number;
}) {
    const [state, dispatch] = useReducer(
        coinsConfiguratorReducer,
        createInitialConfiguratorState(50_000, null),
    );
    const invalidate = useCoinsQuoteRequest({
        active,
        delivery: null,
        dispatch,
        expectedDisplayCurrency: 'USD',
        platform: 'pc',
        quantity,
        quoteUrl: '/en/coins/quote',
    });
    const quote =
        state.quoteState.status === 'success' ||
        state.quoteState.status === 'refreshing'
            ? state.quoteState.quote
            : null;

    return (
        <>
            <output aria-label="Quote status">{state.quoteState.status}</output>
            {quote === null ? null : (
                <output aria-label="Quote total">
                    {quote.displayTotal.amountMinor}
                </output>
            )}
            <button onClick={invalidate} type="button">
                Invalidate quote
            </button>
        </>
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    cleanup();
});

describe('useCoinsQuoteRequest compatibility lifecycle', () => {
    it('moves from loading to the exact successful quote', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(quoteResponse(50_000, 6))),
        );

        render(<QuoteRequestHarness />);

        expect(screen.getByLabelText('Quote status')).toHaveTextContent(
            'loading',
        );
        expect(await screen.findByLabelText('Quote total')).toHaveTextContent(
            '6',
        );
        expect(screen.getByLabelText('Quote status')).toHaveTextContent(
            'success',
        );
    });

    it.each([
        [422, 'validation'],
        [503, 'unavailable'],
    ])('maps HTTP %i to the %s state', async (status, expectedState) => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(errorResponse(status))),
        );

        render(<QuoteRequestHarness />);

        expect(await screen.findByText(expectedState)).toBeVisible();
    });

    it('fails a mismatched successful response closed', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(quoteResponse(100_000, 7))),
        );

        render(<QuoteRequestHarness />);

        expect(await screen.findByText('unavailable')).toBeVisible();
        expect(screen.queryByLabelText('Quote total')).not.toBeInTheDocument();
    });

    it('aborts on unmount without publishing the late response', async () => {
        const pending = deferred<Response>();
        let requestSignal: AbortSignal | undefined;
        vi.stubGlobal(
            'fetch',
            vi.fn((_input: RequestInfo | URL, init?: RequestInit) => {
                requestSignal = init?.signal ?? undefined;

                return pending.promise;
            }),
        );
        const { unmount } = render(<QuoteRequestHarness />);

        unmount();
        pending.resolve(quoteResponse(50_000, 6));
        await act(async () => Promise.resolve());

        expect(requestSignal?.aborted).toBe(true);
        expect(screen.queryByLabelText('Quote total')).not.toBeInTheDocument();
    });

    it('ignores a stale response after a quantity change', async () => {
        const first = deferred<Response>();
        const second = deferred<Response>();
        let requestCount = 0;
        vi.stubGlobal(
            'fetch',
            vi.fn(() => {
                requestCount += 1;

                return requestCount === 1 ? first.promise : second.promise;
            }),
        );
        const { rerender } = render(<QuoteRequestHarness />);

        rerender(<QuoteRequestHarness quantity={100_000} />);
        await act(async () => {
            first.resolve(quoteResponse(50_000, 6));
            await Promise.resolve();
        });
        expect(screen.queryByLabelText('Quote total')).not.toBeInTheDocument();

        second.resolve(quoteResponse(100_000, 7));
        expect(await screen.findByLabelText('Quote total')).toHaveTextContent(
            '7',
        );
    });

    it('invalidates an active request and ignores its late response', async () => {
        const pending = deferred<Response>();
        let requestSignal: AbortSignal | undefined;
        vi.stubGlobal(
            'fetch',
            vi.fn((_input: RequestInfo | URL, init?: RequestInit) => {
                requestSignal = init?.signal ?? undefined;

                return pending.promise;
            }),
        );
        render(<QuoteRequestHarness />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Invalidate quote' }),
        );
        pending.resolve(quoteResponse(50_000, 6));
        await act(async () => Promise.resolve());

        expect(requestSignal?.aborted).toBe(true);
        expect(screen.getByLabelText('Quote status')).toHaveTextContent(
            'loading',
        );
        expect(screen.queryByLabelText('Quote total')).not.toBeInTheDocument();
    });
});
