export type StoredCartCredentials = {
    backupCodes: [string, string, string];
    companionMarketOpen: boolean;
    currentBalance: number | null;
    eaEmail: string;
    eaPassword: string;
    policyAccepted: boolean;
};

function endpoint(path: string): string {
    const url = new URL(path, window.location.origin);

    if (url.origin !== window.location.origin) {
        throw new Error('Unsafe cart credentials endpoint.');
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

function isStoredCredentials(value: unknown): value is StoredCartCredentials {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return false;
    }

    const candidate = value as Record<string, unknown>;
    const codes = candidate.backupCodes;

    return (
        Object.keys(candidate).sort().join(',') ===
            'backupCodes,companionMarketOpen,currentBalance,eaEmail,eaPassword,policyAccepted' &&
        typeof candidate.eaEmail === 'string' &&
        typeof candidate.eaPassword === 'string' &&
        (candidate.currentBalance === null ||
            (Number.isSafeInteger(candidate.currentBalance) &&
                Number(candidate.currentBalance) >= 0 &&
                Number(candidate.currentBalance) <= 100_000_000)) &&
        typeof candidate.companionMarketOpen === 'boolean' &&
        typeof candidate.policyAccepted === 'boolean' &&
        Array.isArray(codes) &&
        codes.length === 3 &&
        codes.every(
            (code) => typeof code === 'string' && /^[0-9]{8}$/.test(code),
        )
    );
}

export async function loadCartCredentials(
    path: string,
    signal: AbortSignal,
): Promise<StoredCartCredentials> {
    const response = await fetch(endpoint(path), {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        signal,
    });
    const payload: unknown = await response.json();

    if (
        !response.ok ||
        typeof payload !== 'object' ||
        payload === null ||
        !('data' in payload) ||
        !isStoredCredentials(payload.data)
    ) {
        throw new Error('Cart credentials are unavailable.');
    }

    return payload.data;
}

export async function updateCartCredentials(
    path: string,
    credentials: StoredCartCredentials,
): Promise<void> {
    const response = await fetch(endpoint(path), {
        body: JSON.stringify({
            backup_codes: credentials.backupCodes,
            ...(credentials.companionMarketOpen && credentials.policyAccepted
                ? {
                      companion_market_open: true,
                      ...(credentials.currentBalance === null
                          ? {}
                          : { current_balance: credentials.currentBalance }),
                      policy_accepted: true,
                  }
                : {}),
            ea_email: credentials.eaEmail,
            ea_password: credentials.eaPassword,
        }),
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        method: 'PATCH',
    });

    if (response.status !== 204) {
        throw new Error('Cart credentials could not be updated.');
    }
}
