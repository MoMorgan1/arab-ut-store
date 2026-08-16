type PlayStationOrderCredentials = {
    platform: 'playstation';
    playstationEmail: string;
    playstationPassword: string;
    eaBackupCodes: [string, string, string];
    playstationBackupCodes: [string, string, string];
};

type PcOrderCredentials = {
    platform: 'pc';
    pcStore: 'ea_app' | 'steam';
    eaEmail: string;
    eaPassword: string;
    eaBackupCodes: [string, string, string];
    steamUsername?: string;
    steamPassword?: string;
};

export type OrderCredentials = PlayStationOrderCredentials | PcOrderCredentials;

export async function loadOrderCredentials(
    url: string,
): Promise<OrderCredentials> {
    const response = await fetch(url, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error('order_credentials_unavailable');
    }

    const body: unknown = await response.json();
    const data = isRecord(body) ? body.data : null;

    if (!isRecord(data) || !threeStrings(data.eaBackupCodes)) {
        throw new Error('order_credentials_invalid');
    }

    if (
        data.platform === 'playstation' &&
        typeof data.playstationEmail === 'string' &&
        typeof data.playstationPassword === 'string' &&
        threeStrings(data.playstationBackupCodes)
    ) {
        return {
            platform: 'playstation',
            playstationEmail: data.playstationEmail,
            playstationPassword: data.playstationPassword,
            eaBackupCodes: data.eaBackupCodes,
            playstationBackupCodes: data.playstationBackupCodes,
        };
    }

    if (
        data.platform === 'pc' &&
        (data.pcStore === 'ea_app' || data.pcStore === 'steam') &&
        typeof data.eaEmail === 'string' &&
        typeof data.eaPassword === 'string' &&
        (data.pcStore !== 'steam' ||
            (typeof data.steamUsername === 'string' &&
                typeof data.steamPassword === 'string'))
    ) {
        return {
            platform: 'pc',
            pcStore: data.pcStore,
            eaEmail: data.eaEmail,
            eaPassword: data.eaPassword,
            eaBackupCodes: data.eaBackupCodes,
            ...(data.pcStore === 'steam'
                ? {
                      steamUsername: data.steamUsername as string,
                      steamPassword: data.steamPassword as string,
                  }
                : {}),
        };
    }

    throw new Error('order_credentials_invalid');
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function threeStrings(value: unknown): value is [string, string, string] {
    return (
        Array.isArray(value) &&
        value.length === 3 &&
        value.every((entry) => typeof entry === 'string')
    );
}
