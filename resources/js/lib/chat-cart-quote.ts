import type { CoinsDeliveryValue, CoinsPlatformValue } from '@/types/coins';

export type ChatCartQuote = {
    amountMinor: number;
    currency: string;
};

type FetchQuoteInput = {
    delivery: CoinsDeliveryValue | null;
    platform: CoinsPlatformValue;
    quantity: number;
};

const CURRENCY_PATTERN = /^[A-Z]{3}$/;

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * Reads the customer-facing total out of a quote response.
 *
 * The panel puts a number in front of someone about to pay it, so a response
 * that is not exactly the expected shape yields no price rather than a
 * plausible-looking one.
 */
function safeDisplayTotal(payload: unknown): ChatCartQuote | null {
    if (!isRecord(payload) || !isRecord(payload.data)) {
        return null;
    }

    const displayTotal = payload.data.displayTotal;

    if (!isRecord(displayTotal)) {
        return null;
    }

    const { amountMinor, currency } = displayTotal;

    if (
        !Number.isSafeInteger(amountMinor) ||
        Number(amountMinor) < 0 ||
        typeof currency !== 'string' ||
        !CURRENCY_PATTERN.test(currency)
    ) {
        return null;
    }

    return { amountMinor: Number(amountMinor), currency };
}

/**
 * Prices one configured coins selection against the store's own quote
 * endpoint — the same source the configurator uses — so the panel never shows
 * a price frozen into chat history.
 *
 * Returns null for anything other than a well-formed quote, including the
 * 503 the endpoint returns while pricing is unavailable.
 */
export async function fetchChatCartQuote(
    input: FetchQuoteInput,
    signal?: AbortSignal,
): Promise<ChatCartQuote | null> {
    const url = new URL('/coins/quote', window.location.origin);
    url.searchParams.set('platform', input.platform);
    url.searchParams.set('quantity', String(input.quantity));

    if (input.delivery !== null) {
        url.searchParams.set('delivery', input.delivery);
    }

    let response: Response;

    try {
        response = await fetch(url, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal,
        });
    } catch {
        return null;
    }

    if (response.status !== 200) {
        return null;
    }

    try {
        return safeDisplayTotal(await response.json());
    } catch {
        return null;
    }
}
