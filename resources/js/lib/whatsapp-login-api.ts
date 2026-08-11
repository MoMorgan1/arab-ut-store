type JsonRecord = Record<string, unknown>;

function isRecord(value: unknown): value is JsonRecord {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function csrfToken(): string {
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    if (token === undefined || token === '') {
        throw new Error('csrf_unavailable');
    }

    return token;
}

function endpoint(path: string): URL {
    const url = new URL(path, window.location.origin);

    if (url.origin !== window.location.origin) {
        throw new Error('unsafe_endpoint');
    }

    return url;
}

async function post(path: string, body: JsonRecord): Promise<unknown> {
    const response = await fetch(endpoint(path), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });
    const payload = await response.json().catch(() => null);

    if (!response.ok) {
        throw new Error(
            response.status === 422 ? 'invalid_code' : 'unavailable',
        );
    }

    return payload;
}

export async function sendWhatsAppLoginCode(
    path: string,
    phone: string,
): Promise<void> {
    const payload = await post(path, { phone });

    if (
        !isRecord(payload) ||
        !isRecord(payload.data) ||
        payload.data.sent !== true
    ) {
        throw new Error('unsafe_response');
    }
}

export async function verifyWhatsAppLoginCode(
    path: string,
    phone: string,
    code: string,
): Promise<string> {
    const payload = await post(path, { phone, code });

    if (!isRecord(payload) || !isRecord(payload.data)) {
        throw new Error('unsafe_response');
    }

    const redirectUrl = payload.data.redirectUrl;

    if (typeof redirectUrl !== 'string') {
        throw new Error('unsafe_response');
    }

    const url = endpoint(redirectUrl);

    return `${url.pathname}${url.search}${url.hash}`;
}
