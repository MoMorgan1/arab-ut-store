type JsonRecord = Record<string, unknown>;

export type CatalogCartSuccess = {
    cartCount: number;
    cartItemId: string;
    cartTotalHalalah?: number;
    cartUrl: string;
};

type SubmitCatalogCartInput = {
    cartUrl: string;
    idempotencyKey: string;
    variantId: string;
};

const ULID_PATTERN = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/;

export class CatalogCartRequestError extends Error {
    readonly code: string;
    readonly conclusive: boolean;
    readonly status: number;
    readonly cartUrl?: string;

    constructor(
        code: string,
        status: number,
        conclusive: boolean,
        cartUrl?: string,
    ) {
        super('Catalog cart request failed.');
        this.name = 'CatalogCartRequestError';
        this.code = code;
        this.status = status;
        this.conclusive = conclusive;
        this.cartUrl = cartUrl;
    }
}

function isRecord(value: unknown): value is JsonRecord {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function sameOriginUrl(path: string): URL | null {
    const url = new URL(path, window.location.origin);

    return url.origin === window.location.origin ? url : null;
}

function csrfToken(): string | null {
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return token === undefined || token === '' ? null : token;
}

async function payload(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
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

function safeSuccess(body: unknown): CatalogCartSuccess | null {
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

export async function submitCatalogCart(
    input: SubmitCatalogCartInput,
): Promise<CatalogCartSuccess> {
    const url = sameOriginUrl(input.cartUrl);

    if (url === null) {
        throw new CatalogCartRequestError('unsafe_endpoint', 0, false);
    }

    const token = csrfToken();

    if (token === null) {
        throw new CatalogCartRequestError('csrf_missing', 0, false);
    }

    let response: Response;

    try {
        response = await fetch(url, {
            body: JSON.stringify({ variantId: input.variantId }),
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'Idempotency-Key': input.idempotencyKey,
                'X-CSRF-TOKEN': token,
            },
            method: 'POST',
        });
    } catch (error) {
        if (error instanceof TypeError) {
            throw new CatalogCartRequestError('transport_error', 0, false);
        }

        throw error;
    }

    const body = await payload(response);

    if (response.status !== 201) {
        throw new CatalogCartRequestError(
            errorCode(body),
            response.status,
            true,
            errorCartUrl(body),
        );
    }

    const success = safeSuccess(body);

    if (success === null) {
        throw new CatalogCartRequestError('unsafe_response', 201, true);
    }

    return success;
}
