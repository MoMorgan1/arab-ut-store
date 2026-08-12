import type { CoinsCredentialField, CoinsCredentials } from '@/types/coins';

type JsonRecord = Record<string, unknown>;

export type SbcCartSuccess = {
    cartCount: number;
    cartItemId: string;
    cartUrl: string;
};

type SubmitSbcCartInput = {
    cartUrl: string;
    credentials: CoinsCredentials;
    idempotencyKey: string;
    variantId: string;
};

const ULID_PATTERN = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/;
const VALIDATION_FIELDS: Readonly<Record<string, CoinsCredentialField>> = {
    credentials: 'email',
    'credentials.ea_email': 'email',
    'credentials.ea_password': 'password',
    'credentials.backup_codes': 'code-0',
    'credentials.backup_codes.0': 'code-0',
    'credentials.backup_codes.1': 'code-1',
    'credentials.backup_codes.2': 'code-2',
};

export class SbcCartRequestError extends Error {
    constructor(
        readonly code: string,
        readonly status: number,
        readonly conclusive: boolean,
        readonly validationFields: CoinsCredentialField[] = [],
    ) {
        super('SBC cart request failed.');
        this.name = 'SbcCartRequestError';
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

async function responsePayload(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

function responseErrorCode(payload: unknown): string {
    if (!isRecord(payload) || !isRecord(payload.error)) {
        return 'unsafe_response';
    }

    return typeof payload.error.code === 'string' && payload.error.code !== ''
        ? payload.error.code
        : 'unsafe_response';
}

function validationFields(payload: unknown): CoinsCredentialField[] {
    if (!isRecord(payload) || !isRecord(payload.errors)) {
        return [];
    }

    return [
        ...new Set(
            Object.keys(payload.errors).flatMap((field) => {
                const mapped = VALIDATION_FIELDS[field];

                return mapped === undefined ? [] : [mapped];
            }),
        ),
    ];
}

function safeSuccess(payload: unknown): SbcCartSuccess | null {
    if (!isRecord(payload) || !isRecord(payload.data)) {
        return null;
    }

    if (
        Object.keys(payload.data).sort().join(',') !==
        'cartCount,cartItemId,cartUrl'
    ) {
        return null;
    }

    const { cartCount, cartItemId, cartUrl } = payload.data;
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

    return {
        cartCount: Number(cartCount),
        cartItemId,
        cartUrl: `${safeCartUrl.pathname}${safeCartUrl.search}${safeCartUrl.hash}`,
    };
}

function requestBody(input: SubmitSbcCartInput) {
    return {
        variantId: input.variantId,
        credentials: {
            ea_email: input.credentials.eaEmail,
            ea_password: input.credentials.eaPassword,
            backup_codes: input.credentials.backupCodes,
        },
    };
}

export async function submitSbcCart(
    input: SubmitSbcCartInput,
): Promise<SbcCartSuccess> {
    const url = sameOriginUrl(input.cartUrl);

    if (url === null) {
        throw new SbcCartRequestError('unsafe_endpoint', 0, false);
    }

    const token = csrfToken();

    if (token === null) {
        throw new SbcCartRequestError('csrf_missing', 0, false);
    }

    let response: Response;

    try {
        response = await fetch(url, {
            body: JSON.stringify(requestBody(input)),
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
            throw new SbcCartRequestError('transport_error', 0, false);
        }

        throw error;
    }

    const payload = await responsePayload(response);

    if (response.status !== 201) {
        throw new SbcCartRequestError(
            response.status === 422
                ? 'validation_error'
                : responseErrorCode(payload),
            response.status,
            true,
            response.status === 422 ? validationFields(payload) : [],
        );
    }

    const success = safeSuccess(payload);

    if (success === null) {
        throw new SbcCartRequestError('unsafe_response', 201, true);
    }

    return success;
}
