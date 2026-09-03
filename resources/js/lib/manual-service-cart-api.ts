export type ManualServiceCartSuccess = {
    cartCount: number;
    cartItemId: string;
    cartTotalHalalah?: number;
    cartUrl: string;
};

const ULID_PATTERN = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/;

export class ManualServiceCartError extends Error {
    constructor(
        readonly code: string,
        readonly status: number,
        readonly conclusive: boolean,
        readonly cartUrl?: string,
    ) {
        super('Manual service cart request failed.');
        this.name = 'ManualServiceCartError';
    }
}

export async function submitManualServiceCart(
    endpoint: string,
    form: FormData,
    idempotencyKey: string,
): Promise<ManualServiceCartSuccess> {
    const url = sameOriginUrl(endpoint);
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    if (url === null) {
        throw new ManualServiceCartError('unsafe_endpoint', 0, false);
    }

    if (token === undefined || token === '') {
        throw new ManualServiceCartError('csrf_missing', 0, false);
    }

    let response: Response;

    try {
        response = await fetch(url, {
            body: form,
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Idempotency-Key': idempotencyKey,
                'X-CSRF-TOKEN': token,
            },
            method: 'POST',
        });
    } catch (error) {
        if (error instanceof TypeError) {
            throw new ManualServiceCartError('transport_error', 0, false);
        }

        throw error;
    }

    const body = await response.json().catch(() => null);

    if (response.status !== 201) {
        throw new ManualServiceCartError(
            errorCode(body),
            response.status,
            true,
            errorCartUrl(body),
        );
    }

    const success = safeSuccess(body);

    if (success === null) {
        throw new ManualServiceCartError('unsafe_response', 201, true);
    }

    return success;
}

function sameOriginUrl(path: string): URL | null {
    const url = new URL(path, window.location.origin);

    return url.origin === window.location.origin ? url : null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function errorCode(body: unknown): string {
    if (!isRecord(body) || !isRecord(body.error)) {
        return 'unsafe_response';
    }

    return typeof body.error.code === 'string' && body.error.code !== ''
        ? body.error.code
        : 'unsafe_response';
}

function errorCartUrl(body: unknown): string | undefined {
    if (!isRecord(body) || !isRecord(body.error)) {
        return undefined;
    }

    const { cartUrl } = body.error;

    if (typeof cartUrl !== 'string') {
        return undefined;
    }

    const safeCartUrl = sameOriginUrl(cartUrl);

    return safeCartUrl === null
        ? undefined
        : `${safeCartUrl.pathname}${safeCartUrl.search}${safeCartUrl.hash}`;
}

function safeMinorTotal(body: unknown): number | undefined {
    if (!isRecord(body) || !isRecord(body.data)) {
        return undefined;
    }

    const { cartTotalHalalah } = body.data;

    return typeof cartTotalHalalah === 'number' &&
        Number.isSafeInteger(cartTotalHalalah) &&
        cartTotalHalalah >= 0
        ? cartTotalHalalah
        : undefined;
}

function safeSuccess(body: unknown): ManualServiceCartSuccess | null {
    if (!isRecord(body) || !isRecord(body.data)) {
        return null;
    }

    const keys = Object.keys(body.data).sort().join(',');

    if (
        keys !== 'cartCount,cartItemId,cartUrl' &&
        keys !== 'cartCount,cartItemId,cartTotalHalalah,cartUrl'
    ) {
        return null;
    }

    const { cartCount, cartItemId, cartUrl } = body.data;
    const safeCartUrl =
        typeof cartUrl === 'string' ? sameOriginUrl(cartUrl) : null;

    if (
        !Number.isSafeInteger(cartCount) ||
        Number(cartCount) < 1 ||
        typeof cartItemId !== 'string' ||
        !ULID_PATTERN.test(cartItemId) ||
        safeCartUrl === null
    ) {
        return null;
    }

    const cartTotalHalalah = safeMinorTotal(body);

    return {
        cartCount: Number(cartCount),
        cartItemId,
        cartUrl: `${safeCartUrl.pathname}${safeCartUrl.search}${safeCartUrl.hash}`,
        ...(cartTotalHalalah === undefined ? {} : { cartTotalHalalah }),
    };
}
