export type AdminMfaApiErrorCode =
    | 'invalid_endpoint'
    | 'csrf_missing'
    | 'invalid_input'
    | 'password_confirmation_required'
    | 'unauthenticated'
    | 'forbidden'
    | 'rate_limited'
    | 'validation'
    | 'invalid_response'
    | 'network'
    | 'server';

export class AdminMfaApiError extends Error {
    constructor(
        public readonly code: AdminMfaApiErrorCode,
        message: string,
        public readonly status?: number,
        public readonly fieldErrors?: Record<string, string>,
    ) {
        super(message);
        this.name = 'AdminMfaApiError';
    }
}

export type AdminMfaQrCode = {
    svg: string;
    url: string;
};

export async function enableAdminMfa(path: string): Promise<void> {
    await mutate(path, {});
}

export async function confirmAdminMfa(
    path: string,
    code: string,
): Promise<void> {
    if (!/^\d{6}$/.test(code)) {
        throw new AdminMfaApiError(
            'invalid_input',
            'The authenticator code must contain six digits.',
        );
    }

    await mutate(path, { code });
}

export async function loadAdminMfaQrCode(
    path: string,
): Promise<AdminMfaQrCode> {
    const payload = await requestJson(path, {
        headers: { Accept: 'application/json' },
        method: 'GET',
    });

    if (!isExactObject(payload, ['svg', 'url'])) {
        throw invalidResponse();
    }

    if (typeof payload.svg !== 'string' || typeof payload.url !== 'string') {
        throw invalidResponse();
    }

    return { svg: payload.svg, url: payload.url };
}

export async function loadAdminMfaRecoveryCodes(
    path: string,
): Promise<string[]> {
    const payload = await requestJson(path, {
        headers: { Accept: 'application/json' },
        method: 'GET',
    });

    if (!Array.isArray(payload) || !payload.every(isRecoveryCode)) {
        throw invalidResponse();
    }

    return [...payload];
}

export async function regenerateAdminMfaRecoveryCodes(
    path: string,
): Promise<void> {
    await mutate(path, {});
}

export type AdminMfaRevokedTrustedDevices = {
    revoked: number;
};

export async function forgetAdminMfaTrustedDevices(
    path: string,
): Promise<AdminMfaRevokedTrustedDevices> {
    const payload = await requestJson(path, {
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        method: 'DELETE',
    });

    if (!isRecord(payload) || typeof payload.revoked !== 'number') {
        throw invalidResponse();
    }

    return { revoked: payload.revoked };
}

async function mutate(
    path: string,
    body: Record<string, string>,
): Promise<void> {
    const payload = await requestJson(path, {
        body: JSON.stringify(body),
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        method: 'POST',
    });

    if (payload !== '') {
        throw invalidResponse();
    }
}

async function requestJson(
    path: string,
    init: Pick<RequestInit, 'body' | 'headers' | 'method'>,
): Promise<unknown> {
    const endpoint = relativeEndpoint(path);
    let response: Response;

    try {
        response = await fetch(endpoint, {
            ...init,
            cache: 'no-store',
            credentials: 'same-origin',
        });
    } catch {
        throw new AdminMfaApiError(
            'network',
            'The security request could not reach the server.',
        );
    }

    const payload = await readJson(response);

    if (!response.ok) {
        throw responseFailure(response.status, payload);
    }

    return payload;
}

function relativeEndpoint(path: string): string {
    if (
        !path.startsWith('/') ||
        path.startsWith('//') ||
        path.includes('\\') ||
        /[\r\n]/.test(path)
    ) {
        throw new AdminMfaApiError(
            'invalid_endpoint',
            'The MFA endpoint must be a same-origin relative URL.',
        );
    }

    const parsed = new URL(path, window.location.origin);

    if (
        parsed.origin !== window.location.origin ||
        `${parsed.pathname}${parsed.search}${parsed.hash}` !== path
    ) {
        throw new AdminMfaApiError(
            'invalid_endpoint',
            'The MFA endpoint must be a same-origin relative URL.',
        );
    }

    return path;
}

function csrfToken(): string {
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    if (!token) {
        throw new AdminMfaApiError(
            'csrf_missing',
            'The security token is unavailable. Refresh the page and try again.',
        );
    }

    return token;
}

async function readJson(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        throw invalidResponse(response.status);
    }
}

function responseFailure(status: number, payload: unknown): AdminMfaApiError {
    if (status === 423) {
        return new AdminMfaApiError(
            'password_confirmation_required',
            'Password confirmation is required.',
            status,
        );
    }

    if (status === 401) {
        return new AdminMfaApiError(
            'unauthenticated',
            'Authentication is required.',
            status,
        );
    }

    if (status === 403) {
        return new AdminMfaApiError(
            'forbidden',
            'Access is forbidden.',
            status,
        );
    }

    if (status === 429) {
        return new AdminMfaApiError(
            'rate_limited',
            'Too many security requests were sent.',
            status,
        );
    }

    if (status === 422) {
        return new AdminMfaApiError(
            'validation',
            messageFrom(payload) ?? 'The security request was invalid.',
            status,
            fieldErrorsFrom(payload),
        );
    }

    return new AdminMfaApiError(
        'server',
        messageFrom(payload) ?? 'The security request failed.',
        status,
    );
}

function fieldErrorsFrom(payload: unknown): Record<string, string> | undefined {
    if (!isRecord(payload) || !isRecord(payload.errors)) {
        return undefined;
    }

    const errors = Object.entries(payload.errors).flatMap(
        ([field, messages]) =>
            Array.isArray(messages) && typeof messages[0] === 'string'
                ? [[field, messages[0]] as const]
                : [],
    );

    return errors.length > 0 ? Object.fromEntries(errors) : undefined;
}

function messageFrom(payload: unknown): string | undefined {
    return isRecord(payload) && typeof payload.message === 'string'
        ? payload.message
        : undefined;
}

function isExactObject(
    value: unknown,
    keys: readonly string[],
): value is Record<string, unknown> {
    return (
        isRecord(value) &&
        Object.keys(value).length === keys.length &&
        keys.every((key) => Object.hasOwn(value, key))
    );
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isRecoveryCode(value: unknown): value is string {
    return typeof value === 'string' && value.length > 0;
}

function invalidResponse(status?: number): AdminMfaApiError {
    return new AdminMfaApiError(
        'invalid_response',
        'The server returned an invalid MFA response.',
        status,
    );
}
