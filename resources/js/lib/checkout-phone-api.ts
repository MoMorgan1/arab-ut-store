type JsonRecord = Record<string, unknown>;

const PHONE_PATTERN = /^\+[1-9][0-9]{7,14}$/;
const CODE_PATTERN = /^[0-9]{6}$/;

export class CheckoutPhoneError extends Error {
    constructor(
        readonly code: string,
        readonly status: number,
    ) {
        super('Checkout phone verification failed.');
        this.name = 'CheckoutPhoneError';
    }
}

function isRecord(value: unknown): value is JsonRecord {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function endpoint(path: string): URL | null {
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

function serverErrorCode(payload: unknown): string {
    if (!isRecord(payload) || !isRecord(payload.error)) {
        return 'unsafe_response';
    }

    return typeof payload.error.code === 'string' && payload.error.code !== ''
        ? payload.error.code
        : 'unsafe_response';
}

async function post(
    path: string,
    body: Record<string, string>,
    successKey: 'sent' | 'verified',
): Promise<void> {
    const url = endpoint(path);
    const token = csrfToken();

    if (url === null) {
        throw new CheckoutPhoneError('unsafe_endpoint', 0);
    }

    if (token === null) {
        throw new CheckoutPhoneError('csrf_missing', 0);
    }

    let response: Response;

    try {
        response = await fetch(url, {
            body: JSON.stringify(body),
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
            },
            method: 'POST',
        });
    } catch (error) {
        if (error instanceof TypeError) {
            throw new CheckoutPhoneError('transport_error', 0);
        }

        throw error;
    }

    let payload: unknown;

    try {
        payload = await response.json();
    } catch {
        payload = null;
    }

    if (!response.ok) {
        throw new CheckoutPhoneError(serverErrorCode(payload), response.status);
    }

    if (
        !isRecord(payload) ||
        !isRecord(payload.data) ||
        Object.keys(payload.data).join(',') !== successKey ||
        payload.data[successKey] !== true
    ) {
        throw new CheckoutPhoneError('unsafe_response', response.status);
    }
}

export async function sendCheckoutPhoneCode(
    path: string,
    phone: string,
): Promise<void> {
    if (!PHONE_PATTERN.test(phone)) {
        throw new CheckoutPhoneError('invalid_input', 0);
    }

    await post(path, { phone }, 'sent');
}

export async function verifyCheckoutPhoneCode(
    path: string,
    phone: string,
    code: string,
): Promise<void> {
    if (!PHONE_PATTERN.test(phone) || !CODE_PATTERN.test(code)) {
        throw new CheckoutPhoneError('invalid_input', 0);
    }

    await post(path, { code, phone }, 'verified');
}

export function reloadAfterPhoneVerification(): void {
    window.location.reload();
}
