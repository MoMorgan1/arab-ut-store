import { isUtcWireTimestamp, isWireUlid } from '@/lib/wire-validators';
import type {
    CoinsDeliveryValue,
    CoinsPlatformValue,
    CoinsQuote,
} from '@/types/coins';

type QuoteCoinsInput = {
    quoteUrl: string;
    platform: CoinsPlatformValue;
    delivery: CoinsDeliveryValue | null;
    expectedDisplayCurrency: string;
    quantity: number;
    signal: AbortSignal;
};

type JsonRecord = Record<string, unknown>;

export class CoinsQuoteRequestError extends Error {
    readonly status: number;
    readonly code: string | null;

    constructor(status: number, code: string | null) {
        super('Coins quote request failed.');
        this.name = 'CoinsQuoteRequestError';
        this.status = status;
        this.code = code;
    }
}

function isRecord(value: unknown): value is JsonRecord {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isPlatform(value: unknown): value is CoinsPlatformValue {
    return value === 'playstation' || value === 'pc';
}

function isDelivery(value: unknown): value is CoinsDeliveryValue | null {
    return value === null || value === 'normal' || value === 'fast';
}

function parseQuoteErrorCode(payload: unknown): string | null {
    if (!isRecord(payload) || !isRecord(payload.error)) {
        return null;
    }

    const { code, message } = payload.error;

    if (
        typeof code !== 'string' ||
        code.trim() === '' ||
        typeof message !== 'string' ||
        message.trim() === ''
    ) {
        return null;
    }

    return code;
}

function responseMatchesRequest(
    data: JsonRecord,
    request: Pick<QuoteCoinsInput, 'delivery' | 'platform' | 'quantity'>,
): boolean {
    if (
        data.platform !== request.platform ||
        data.delivery !== request.delivery ||
        data.quantity !== request.quantity
    ) {
        return false;
    }

    if (request.platform === 'pc') {
        return request.delivery === null && data.market === 'pc';
    }

    return request.delivery !== null && data.market === 'console';
}

function parseQuote(
    payload: unknown,
    request: Pick<
        QuoteCoinsInput,
        'delivery' | 'expectedDisplayCurrency' | 'platform' | 'quantity'
    >,
): CoinsQuote | null {
    if (!isRecord(payload) || !isRecord(payload.data)) {
        return null;
    }

    const data = payload.data;

    if (
        !isWireUlid(data.productId) ||
        !isWireUlid(data.variantId) ||
        !isPlatform(data.platform) ||
        (data.market !== 'console' && data.market !== 'pc') ||
        !isDelivery(data.delivery) ||
        typeof data.quantity !== 'number' ||
        !Number.isSafeInteger(data.quantity) ||
        !isUtcWireTimestamp(data.pricedAt) ||
        !isRecord(data.total) ||
        typeof data.total.amountHalalah !== 'number' ||
        !Number.isSafeInteger(data.total.amountHalalah) ||
        data.total.amountHalalah <= 0 ||
        data.total.amountHalalah % 100 !== 0 ||
        data.total.currency !== 'SAR' ||
        !isRecord(data.displayTotal) ||
        typeof data.displayTotal.amountMinor !== 'number' ||
        !Number.isSafeInteger(data.displayTotal.amountMinor) ||
        data.displayTotal.amountMinor <= 0 ||
        data.displayTotal.currency !== request.expectedDisplayCurrency
    ) {
        return null;
    }

    if (!responseMatchesRequest(data, request)) {
        return null;
    }

    return {
        delivery: data.delivery,
        displayTotal: {
            amountMinor: data.displayTotal.amountMinor,
            currency: data.displayTotal.currency,
        },
        market: data.market,
        platform: data.platform,
        pricedAt: data.pricedAt,
        productId: data.productId,
        quantity: data.quantity,
        total: {
            amountHalalah: data.total.amountHalalah,
            currency: data.total.currency,
        },
        variantId: data.variantId,
    };
}

async function readJson(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

export async function quoteCoins({
    quoteUrl,
    platform,
    delivery,
    expectedDisplayCurrency,
    quantity,
    signal,
}: QuoteCoinsInput): Promise<CoinsQuote> {
    const url = new URL(quoteUrl, window.location.origin);

    url.searchParams.set('platform', platform);
    url.searchParams.set('quantity', String(quantity));

    if (delivery !== null) {
        url.searchParams.set('delivery', delivery);
    }

    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        method: 'GET',
        signal,
    });
    const payload = await readJson(response);

    if (!response.ok) {
        if (response.status === 422) {
            throw new CoinsQuoteRequestError(422, null);
        }

        const code = parseQuoteErrorCode(payload);

        if (code === null) {
            throw new CoinsQuoteRequestError(503, 'coins_pricing_unavailable');
        }

        throw new CoinsQuoteRequestError(response.status, code);
    }

    const quote = parseQuote(payload, {
        delivery,
        expectedDisplayCurrency,
        platform,
        quantity,
    });

    if (quote === null) {
        throw new CoinsQuoteRequestError(503, 'coins_pricing_unavailable');
    }

    return quote;
}
