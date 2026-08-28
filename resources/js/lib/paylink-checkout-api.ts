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

export type PaylinkRepricing = {
    couponRemoved: boolean;
    orderTotalHalalah: number;
    payableHalalah: number;
    previousOrderTotalHalalah: number;
    previousPayableHalalah: number;
};

export class PaylinkCheckoutError extends Error {
    constructor(
        readonly code: string,
        readonly status: number,
        readonly conclusive: boolean,
        // Present only on `cart_repriced`: the figures the confirmation dialog
        // needs. Parsed as strictly as the success body, since it decides what
        // the customer is asked to agree to pay.
        readonly repricing: PaylinkRepricing | null = null,
    ) {
        super('Paylink checkout request failed.');
        this.name = 'PaylinkCheckoutError';
    }
}

function safeRepricing(payload: unknown): PaylinkRepricing | null {
    if (!isRecord(payload) || !isRecord(payload.repricing)) {
        return null;
    }

    const repricing = payload.repricing;

    if (
        Object.keys(repricing).sort().join(',') !==
        'couponRemoved,orderTotalHalalah,payableHalalah,previousOrderTotalHalalah,previousPayableHalalah'
    ) {
        return null;
    }

    if (typeof repricing.couponRemoved !== 'boolean') {
        return null;
    }

    const amounts = [
        repricing.orderTotalHalalah,
        repricing.payableHalalah,
        repricing.previousOrderTotalHalalah,
        repricing.previousPayableHalalah,
    ];

    if (
        amounts.some(
            (amount) =>
                typeof amount !== 'number' ||
                !Number.isInteger(amount) ||
                amount < 0,
        )
    ) {
        return null;
    }

    return {
        couponRemoved: repricing.couponRemoved,
        orderTotalHalalah: repricing.orderTotalHalalah as number,
        payableHalalah: repricing.payableHalalah as number,
        previousOrderTotalHalalah:
            repricing.previousOrderTotalHalalah as number,
        previousPayableHalalah: repricing.previousPayableHalalah as number,
    };
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
    expectedPayableHalalah: number,
    expectedOrderTotalHalalah: number,
): Promise<PaylinkCheckoutSuccess> {
    const url = sameOriginUrl(path);

    if (
        url === null ||
        !CHECKOUT_PATH_PATTERN.test(url.pathname) ||
        !IDEMPOTENCY_KEY_PATTERN.test(idempotencyKey)
    ) {
        throw new PaylinkCheckoutError('unsafe_endpoint', 0, false);
    }

    return submitPaylinkCheckout(
        url,
        idempotencyKey,
        expectedPayableHalalah,
        expectedOrderTotalHalalah,
    );
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

function halalahHeader(amount: number | undefined): string | null {
    return typeof amount === 'number' && Number.isInteger(amount) && amount >= 0
        ? String(amount)
        : null;
}

async function submitPaylinkCheckout(
    url: URL,
    idempotencyKey: string | null,
    expectedPayableHalalah?: number,
    expectedOrderTotalHalalah?: number,
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

    // Both totals the customer was actually shown. The order total is sent as
    // well as the cash payable because the wallet absorbs movement in the
    // payable: a fully covered cart owes zero cash whatever the order total
    // does, so the payable alone cannot prove the price did not change.
    const payable = halalahHeader(expectedPayableHalalah);
    const orderTotal = halalahHeader(expectedOrderTotalHalalah);

    if (payable !== null) {
        headers['X-Expected-Total-Halalah'] = payable;
    }

    if (orderTotal !== null) {
        headers['X-Expected-Order-Total-Halalah'] = orderTotal;
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
        // Throttling answers with Laravel's own shape, not our error envelope,
        // so it would otherwise read as an unparseable response.
        const code =
            response.status === 429 ? 'too_many_requests' : errorCode(payload);
        const repricing =
            code === 'cart_repriced' ? safeRepricing(payload) : null;

        // A repricing the client cannot read is not something to confirm.
        if (code === 'cart_repriced' && repricing === null) {
            throw new PaylinkCheckoutError(
                'unsafe_response',
                response.status,
                true,
            );
        }

        throw new PaylinkCheckoutError(code, response.status, true, repricing);
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
