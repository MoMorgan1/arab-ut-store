export type StoredCartCredentials = {
    backupCodes: [string, string, string];
    companionMarketOpen: boolean;
    currentBalance: number | null;
    eaEmail: string;
    eaPassword: string;
    policyAccepted: boolean;
};

export type StoredManualCartCredentials = {
    platform: 'playstation' | 'pc';
    launcher: 'ea_app' | 'steam' | null;
    eaEmail: string;
    eaPassword: string;
    eaCodes: [string, string, string];
    playstationEmail: string;
    playstationPassword: string;
    playstationCodes: [string, string, string];
    steamUsername: string;
    steamPassword: string;
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

const EA_CODE_PATTERN = /^[0-9]{8}$/;
const PS_CODE_PATTERN = /^[A-Za-z0-9]{6}$/;

function isCodeTriple(
    value: unknown,
    pattern: RegExp,
): value is [string, string, string] {
    return (
        Array.isArray(value) &&
        value.length === 3 &&
        value.every((code) => typeof code === 'string' && pattern.test(code)) &&
        new Set(value).size === 3
    );
}

function isEmptyTriple(value: unknown): boolean {
    return (
        Array.isArray(value) &&
        value.length === 3 &&
        value.every((code) => code === '')
    );
}

function isManualCredentials(
    value: unknown,
): value is StoredManualCartCredentials {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return false;
    }

    const candidate = value as Record<string, unknown>;

    if (
        Object.keys(candidate).sort().join(',') !==
        'eaCodes,eaEmail,eaPassword,launcher,platform,playstationCodes,playstationEmail,playstationPassword,steamPassword,steamUsername'
    ) {
        return false;
    }

    if (candidate.platform !== 'playstation' && candidate.platform !== 'pc') {
        return false;
    }

    if (
        candidate.launcher !== null &&
        candidate.launcher !== 'ea_app' &&
        candidate.launcher !== 'steam'
    ) {
        return false;
    }

    if (
        (candidate.platform === 'pc') !==
        (candidate.launcher === 'ea_app' || candidate.launcher === 'steam')
    ) {
        return false;
    }

    // Fields the platform does not use arrive empty — they are display-only
    // here and never sent back. Only the side being edited must hold real
    // codes.
    const codesOk =
        isCodeTriple(candidate.eaCodes, EA_CODE_PATTERN) &&
        (candidate.platform === 'playstation'
            ? isCodeTriple(candidate.playstationCodes, PS_CODE_PATTERN)
            : isEmptyTriple(candidate.playstationCodes));

    return (
        codesOk &&
        typeof candidate.eaEmail === 'string' &&
        typeof candidate.eaPassword === 'string' &&
        typeof candidate.playstationEmail === 'string' &&
        typeof candidate.playstationPassword === 'string' &&
        typeof candidate.steamUsername === 'string' &&
        typeof candidate.steamPassword === 'string'
    );
}

/**
 * Loads the stored manual-service credentials for a cart line. The shape
 * mirrors the secret payload the server persists: every field is present
 * (empty when the platform does not use it) so the configurator can
 * prefill without branching on the platform first.
 */
export async function loadManualCartCredentials(
    path: string,
    signal: AbortSignal,
): Promise<StoredManualCartCredentials> {
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
        !isManualCredentials(payload.data)
    ) {
        throw new Error('Cart credentials are unavailable.');
    }

    return payload.data;
}

/**
 * Saves edited manual-service credentials. The platform and launcher live
 * on the stored line and are never sent — the server reuses those, so an
 * edit can never move a line to a platform it was not bought on.
 */
export async function updateManualCartCredentials(
    path: string,
    credentials: StoredManualCartCredentials,
): Promise<void> {
    const body: Record<string, unknown> =
        credentials.platform === 'playstation'
            ? {
                  playstation_email: credentials.playstationEmail,
                  playstation_password: credentials.playstationPassword,
                  ea_backup_codes: credentials.eaCodes,
                  playstation_backup_codes: credentials.playstationCodes,
              }
            : {
                  ea_email: credentials.eaEmail,
                  ea_password: credentials.eaPassword,
                  ea_backup_codes: credentials.eaCodes,
                  ...(credentials.launcher === 'steam'
                      ? {
                            steam_username: credentials.steamUsername,
                            steam_password: credentials.steamPassword,
                        }
                      : {}),
              };
    const response = await fetch(endpoint(path), {
        body: JSON.stringify(body),
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
