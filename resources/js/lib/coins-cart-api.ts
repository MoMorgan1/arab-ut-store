import type {
    CoinsCredentialField,
    CoinsCredentials,
    CoinsDeliveryValue,
    CoinsPlatformValue,
} from '@/types/coins';

type JsonRecord = Record<string, unknown>;

type SubmitCoinsCartInput = {
    cartUrl: string;
    credentials: CoinsCredentials;
    delivery: CoinsDeliveryValue | null;
    idempotencyKey: string;
    platform: CoinsPlatformValue;
    quantity: number;
};

export type CoinsCartSuccess = {
    cartCount: number;
    cartItemId: string;
    cartUrl: string;
};

const ULID_PATTERN = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/;

export class CoinsCartRequestError extends Error {
    readonly code: string;
    readonly conclusive: boolean;
    readonly status: number;
    readonly validationFields: CoinsCredentialField[];

    constructor(
        code: string,
        status: number,
        conclusive: boolean,
        validationFields: CoinsCredentialField[] = [],
    ) {
        super('Coins cart request failed.');
        this.name = 'CoinsCartRequestError';
        this.code = code;
        this.conclusive = conclusive;
        this.status = status;
        this.validationFields = validationFields;
    }
}

const CREDENTIAL_VALIDATION_FIELDS: Readonly<
    Record<string, CoinsCredentialField>
> = {
    credentials: 'email',
    'credentials.backup_codes': 'code-0',
    'credentials.backup_codes.0': 'code-0',
    'credentials.backup_codes.1': 'code-1',
    'credentials.backup_codes.2': 'code-2',
    'credentials.ea_email': 'email',
    'credentials.ea_password': 'password',
    'credentials.current_balance': 'current-balance',
    'credentials.companion_market_open': 'companion',
    'credentials.policy_accepted': 'policy',
};

function isRecord(candidate: unknown): candidate is JsonRecord {
    return (
        typeof candidate === 'object' &&
        candidate !== null &&
        !Array.isArray(candidate)
    );
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

    const code = payload.error.code;

    return typeof code === 'string' && code !== '' ? code : 'unsafe_response';
}

function credentialValidationFields(payload: unknown): CoinsCredentialField[] {
    if (!isRecord(payload) || !isRecord(payload.errors)) {
        return [];
    }

    return [
        ...new Set(
            Object.keys(payload.errors).flatMap((field) => {
                const credentialField = CREDENTIAL_VALIDATION_FIELDS[field];

                return credentialField === undefined ? [] : [credentialField];
            }),
        ),
    ];
}

function safeSuccess(payload: unknown): CoinsCartSuccess | null {
    if (!isRecord(payload) || !isRecord(payload.data)) {
        return null;
    }

    const { cartCount, cartItemId, cartUrl } = payload.data;
    const safeCartUrl = typeof cartUrl === 'string' && sameOriginUrl(cartUrl);

    if (
        !Number.isSafeInteger(cartCount) ||
        Number(cartCount) < 1 ||
        typeof cartItemId !== 'string' ||
        !ULID_PATTERN.test(cartItemId) ||
        safeCartUrl === false ||
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

function requestBody(input: SubmitCoinsCartInput) {
    return {
        credentials: {
            backup_codes: input.credentials.backupCodes,
            companion_market_open:
                input.credentials.companionMarketOpen === true,
            ...(input.credentials.currentBalance === undefined ||
            input.credentials.currentBalance === ''
                ? {}
                : {
                      current_balance: Number(input.credentials.currentBalance),
                  }),
            ea_email: input.credentials.eaEmail,
            ea_password: input.credentials.eaPassword,
            policy_accepted: input.credentials.policyAccepted === true,
        },
        ...(input.delivery === null ? {} : { delivery: input.delivery }),
        platform: input.platform,
        quantity: input.quantity,
    };
}

async function sendRequest(
    url: URL,
    token: string,
    input: SubmitCoinsCartInput,
): Promise<Response> {
    try {
        return await fetch(url, {
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
            throw new CoinsCartRequestError('transport_error', 0, false);
        }

        throw error;
    }
}

export async function submitCoinsCart(
    input: SubmitCoinsCartInput,
): Promise<CoinsCartSuccess> {
    const url = sameOriginUrl(input.cartUrl);

    if (url === null) {
        throw new CoinsCartRequestError('unsafe_endpoint', 0, false);
    }

    const token = csrfToken();

    if (token === null) {
        throw new CoinsCartRequestError('csrf_missing', 0, false);
    }

    const response = await sendRequest(url, token, input);
    const payload = await responsePayload(response);

    if (response.status !== 201) {
        throw new CoinsCartRequestError(
            response.status === 422
                ? 'validation_error'
                : responseErrorCode(payload),
            response.status,
            true,
            response.status === 422 ? credentialValidationFields(payload) : [],
        );
    }

    const success = safeSuccess(payload);

    if (success === null) {
        throw new CoinsCartRequestError('unsafe_response', 201, true);
    }

    return success;
}
