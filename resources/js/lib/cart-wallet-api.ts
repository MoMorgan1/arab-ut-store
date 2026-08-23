export class CartWalletError extends Error {
    readonly code: string;

    constructor(code: string = 'wallet_error') {
        super(`Wallet toggle failed: ${code}`);
        this.code = code;
    }
}

function endpoint(path: string): string {
    const url = new URL(path, window.location.origin);

    if (url.origin !== window.location.origin) {
        throw new Error('Unsafe wallet endpoint.');
    }

    return `${url.pathname}${url.search}`;
}

function csrfToken(): string {
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    if (token === undefined || token === '') {
        throw new Error('CSRF token is unavailable.');
    }

    return token;
}

export async function toggleCartWallet(
    path: string,
    use: boolean,
): Promise<boolean> {
    let response: Response;

    try {
        response = await fetch(endpoint(path), {
            body: JSON.stringify({ use }),
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            method: 'POST',
        });
    } catch {
        throw new CartWalletError('wallet_error');
    }

    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        throw new CartWalletError('wallet_error');
    }

    if (
        typeof payload !== 'object' ||
        payload === null ||
        !('data' in payload)
    ) {
        throw new CartWalletError('wallet_error');
    }

    const data = (payload as { data: unknown }).data as Record<string, unknown>;

    return typeof data.use_wallet === 'boolean' ? data.use_wallet : use;
}
