import { useEffect, useRef } from 'react';
import type { Dispatch } from 'react';

import { CoinsQuoteRequestError, quoteCoins } from '@/lib/coins-api';
import type { CoinsDeliveryValue, CoinsPlatformValue } from '@/types/coins';

import type { CoinsConfiguratorAction } from './configurator-state';

type UseCoinsQuoteRequestOptions = {
    active: boolean;
    delivery: CoinsDeliveryValue | null;
    dispatch: Dispatch<CoinsConfiguratorAction>;
    expectedDisplayCurrency: string;
    platform: CoinsPlatformValue | null;
    quantity: number | null;
    quoteUrl: string;
};

function isAbortError(error: unknown): boolean {
    return error instanceof Error && error.name === 'AbortError';
}

export function useCoinsQuoteRequest({
    active,
    delivery,
    dispatch,
    expectedDisplayCurrency,
    platform,
    quantity,
    quoteUrl,
}: UseCoinsQuoteRequestOptions) {
    const activeController = useRef<AbortController | null>(null);
    const requestVersion = useRef(0);

    useEffect(() => {
        const version = ++requestVersion.current;

        if (!active || platform === null || quantity === null) {
            return;
        }

        const controller = new AbortController();
        activeController.current = controller;
        dispatch({ type: 'quote-loading' });

        void quoteCoins({
            delivery,
            expectedDisplayCurrency,
            platform,
            quantity,
            quoteUrl,
            signal: controller.signal,
        })
            .then((quote) => {
                if (
                    controller.signal.aborted ||
                    version !== requestVersion.current
                ) {
                    return;
                }

                dispatch({ quote, type: 'quote-succeeded' });
            })
            .catch((error: unknown) => {
                if (
                    controller.signal.aborted ||
                    version !== requestVersion.current ||
                    isAbortError(error)
                ) {
                    return;
                }

                dispatch({
                    type:
                        error instanceof CoinsQuoteRequestError &&
                        error.status === 422
                            ? 'quote-validation'
                            : 'quote-unavailable',
                });
            });

        return () => {
            controller.abort();

            if (activeController.current === controller) {
                activeController.current = null;
            }
        };
    }, [
        active,
        delivery,
        dispatch,
        expectedDisplayCurrency,
        platform,
        quantity,
        quoteUrl,
    ]);

    return function invalidateQuoteRequest() {
        requestVersion.current += 1;
        activeController.current?.abort();
        activeController.current = null;
    };
}
