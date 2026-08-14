type JsonRecord = Record<string, unknown>;

export type PaylinkCheckoutSuccess = {
    orderUrl: string;
    paymentUrl: string | null;
    status: 'pending' | 'paid' | 'cancelled';
};

const IDEMPOTENCY_KEY_PATTERN = /^[A-Za-z0-9._:-]{1,128}$/;
const ORDER_PATH_PATTERN = /^\/(?:en\/)?orders\/[0-7][0-9A-HJKMNP-TV-Z]{25}$/;
const CHECKOUT_PATH_PATTERN = /^\/(?:en\/)?checkout\/paylink$/;
const PAYMENT_START_PATH_PATTERN =
    /^\/(?:en\/)?orders\/[0-7][0-9A-HJKMNP-TV-Z]{25}\/payments\/paylink$/;

export class PaylinkCheckoutError extends Error {
    constructor(
        readonly code: string,
        readonly status: number,
        readonly conclusive: boolean,
    ) {
        super('Paylink checkout request failed.');
        this.name = 'PaylinkCheckoutError';
    }
}

function isRecord(value: unknown): value is JsonRecord {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function sameOriginUrl(path: string): URL | null {
    try {
        const url = new URL(path, window.location.origin);

        return url.origin === window.location.origin ? url : null;
    } catch {
        return null;
    }
}

function csrfToken(): string | null {
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return token === undefined || token === '' ? null : token;
}

async function responsePayload(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

function errorCode(payload: unknown): string {
    if (!isRecord(payload) || !isRecord(payload.error)) {
        return 'unsafe_response';
    }

    return typeof payload.error.code === 'string' && payload.error.code !== ''
        ? payload.error.code
        : 'unsafe_response';
}

function safeSuccess(payload: unknown): PaylinkCheckoutSuccess | null {
    if (!isRecord(payload) || !isRecord(payload.data)) {
        return null;
    }

    if (
        Object.keys(payload.data).sort().join(',') !==
        'orderUrl,paymentUrl,status'
    ) {
        return null;
    }

    const { orderUrl, paymentUrl, status } = payload.data;
    const safeOrderUrl =
        typeof orderUrl === 'string' ? sameOriginUrl(orderUrl) : null;

    if (
        safeOrderUrl === null ||
        !ORDER_PATH_PATTERN.test(safeOrderUrl.pathname)
    ) {
        return null;
    }

    if (!['pending', 'paid', 'cancelled'].includes(String(status))) {
        return null;
    }

    let normalizedPaymentUrl: string | null = null;

    if (status === 'pending') {
        let safePaymentUrl: URL;

        try {
            safePaymentUrl = new URL(String(paymentUrl));
        } catch {
            return null;
        }

        if (
            typeof paymentUrl !== 'string' ||
            safePaymentUrl.protocol !== 'https:' ||
            safePaymentUrl.hostname !== 'payment.paylink.sa' ||
            !safePaymentUrl.pathname.startsWith('/pay/') ||
            safePaymentUrl.username !== '' ||
            safePaymentUrl.password !== ''
        ) {
            return null;
        }

        normalizedPaymentUrl = safePaymentUrl.toString();
    } else if (paymentUrl !== null) {
        return null;
    }

    return {
        orderUrl: `${safeOrderUrl.pathname}${safeOrderUrl.search}${safeOrderUrl.hash}`,
        paymentUrl: normalizedPaymentUrl,
        status: status as PaylinkCheckoutSuccess['status'],
    };
}

export async function startPaylinkCheckout(
    path: string,
    idempotencyKey: string,
): Promise<PaylinkCheckoutSuccess> {
    const url = sameOriginUrl(path);

    if (
        url === null ||
        !CHECKOUT_PATH_PATTERN.test(url.pathname) ||
        !IDEMPOTENCY_KEY_PATTERN.test(idempotencyKey)
    ) {
        throw new PaylinkCheckoutError('unsafe_endpoint', 0, false);
    }

    return submitPaylinkCheckout(url, idempotencyKey);
}

export async function resumePaylinkCheckout(
    path: string,
): Promise<PaylinkCheckoutSuccess> {
    const url = sameOriginUrl(path);

    if (url === null || !PAYMENT_START_PATH_PATTERN.test(url.pathname)) {
        throw new PaylinkCheckoutError('unsafe_endpoint', 0, false);
    }

    return submitPaylinkCheckout(url, null);
}

async function submitPaylinkCheckout(
    url: URL,
    idempotencyKey: string | null,
): Promise<PaylinkCheckoutSuccess> {
    const token = csrfToken();

    if (token === null) {
        throw new PaylinkCheckoutError('csrf_missing', 0, false);
    }

    let response: Response;

    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
    };

    if (idempotencyKey !== null) {
        headers['Idempotency-Key'] = idempotencyKey;
    }

    try {
        response = await fetch(url, {
            body: '{}',
            cache: 'no-store',
            credentials: 'same-origin',
            headers,
            method: 'POST',
        });
    } catch (error) {
        if (error instanceof TypeError) {
            throw new PaylinkCheckoutError('transport_error', 0, false);
        }

        throw error;
    }

    const payload = await responsePayload(response);

    if (response.status !== 200 && response.status !== 201) {
        throw new PaylinkCheckoutError(
            errorCode(payload),
            response.status,
            true,
        );
    }

    const success = safeSuccess(payload);

    if (success === null) {
        throw new PaylinkCheckoutError('unsafe_response', 201, true);
    }

    return success;
}

export function navigateToHostedPayment(paymentUrl: string): void {
    window.location.assign(paymentUrl);
}

export function navigateToOrder(orderUrl: string): void {
    window.location.assign(orderUrl);
}
