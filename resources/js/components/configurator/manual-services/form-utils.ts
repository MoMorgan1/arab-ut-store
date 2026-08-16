import type {
    ManualCredentialsDraft,
    ManualServicePlatform,
    PcLauncher,
} from '@/types/manual-services';

export function appendCredentials(
    form: FormData,
    platform: ManualServicePlatform,
    launcher: PcLauncher | null,
    credentials: ManualCredentialsDraft,
) {
    if (platform === 'playstation') {
        form.set(
            'credentials[playstation_email]',
            credentials.playstationEmail,
        );
        form.set(
            'credentials[playstation_password]',
            credentials.playstationPassword,
        );
        appendCodes(form, 'ea_backup_codes', credentials.eaCodes);
        appendCodes(
            form,
            'playstation_backup_codes',
            credentials.playstationCodes,
        );

        return;
    }

    form.set('credentials[ea_email]', credentials.eaEmail);
    form.set('credentials[ea_password]', credentials.eaPassword);
    appendCodes(form, 'ea_backup_codes', credentials.eaCodes);

    if (launcher === 'steam') {
        form.set('credentials[steam_username]', credentials.steamUsername);
        form.set('credentials[steam_password]', credentials.steamPassword);
    }
}

export function validSquadImage(
    file: File | null,
): 'required' | 'type' | 'size' | null {
    if (file === null) {
        return 'required';
    }

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        return 'type';
    }

    return file.size > 5 * 1024 * 1024 ? 'size' : null;
}

export function validManualCredentials(
    platform: ManualServicePlatform,
    launcher: PcLauncher | null,
    credentials: ManualCredentialsDraft,
): boolean {
    const eaCodesValid = validCodes(credentials.eaCodes, /^[0-9]{8}$/);

    if (platform === 'playstation') {
        return (
            validEmail(credentials.playstationEmail) &&
            credentials.playstationPassword !== '' &&
            eaCodesValid &&
            validCodes(credentials.playstationCodes, /^[A-Za-z0-9]{6}$/)
        );
    }

    return (
        launcher !== null &&
        validEmail(credentials.eaEmail) &&
        credentials.eaPassword !== '' &&
        eaCodesValid &&
        (launcher !== 'steam' ||
            (credentials.steamUsername.trim() !== '' &&
                credentials.steamPassword !== ''))
    );
}

export function newManualAttemptKey(): string {
    return typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `manual-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function appendCodes(
    form: FormData,
    key: string,
    codes: [string, string, string],
) {
    codes.forEach((code, index) =>
        form.set(`credentials[${key}][${index}]`, code),
    );
}

function validEmail(value: string): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function validCodes(
    values: [string, string, string],
    pattern: RegExp,
): boolean {
    return (
        values.every((value) => pattern.test(value)) &&
        new Set(values).size === 3
    );
}
