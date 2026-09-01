import { newAttemptKey } from '@/lib/attempt-key';
import type {
    ManualCredentialsDraft,
    ManualServiceCommonTranslations,
    ManualServicePlatform,
    PcLauncher,
} from '@/types/manual-services';

export type ManualFormErrors = Partial<Record<string, string>>;

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const EA_CODE_PATTERN = /^[0-9]{8}$/;
const PS_CODE_PATTERN = /^[A-Za-z0-9]{6}$/;

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
    const eaCodesValid = validCodes(credentials.eaCodes, EA_CODE_PATTERN);

    if (platform === 'playstation') {
        return (
            validEmail(credentials.playstationEmail) &&
            credentials.playstationPassword !== '' &&
            eaCodesValid &&
            validCodes(credentials.playstationCodes, PS_CODE_PATTERN)
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

export function manualCredentialErrors(
    platform: ManualServicePlatform,
    launcher: PcLauncher | null,
    credentials: ManualCredentialsDraft,
    translations: ManualServiceCommonTranslations,
): ManualFormErrors {
    const errors: ManualFormErrors = {};

    if (platform === 'playstation') {
        if (!credentials.playstationEmail.trim()) {
            errors.playstationEmail = translations.required_field;
        } else if (!validEmail(credentials.playstationEmail)) {
            errors.playstationEmail = translations.invalid_email;
        }

        if (!credentials.playstationPassword) {
            errors.playstationPassword = translations.required_field;
        }

        credentials.playstationCodes.forEach((code, index) => {
            const key = `playstationCode-${index}`;

            if (!code.trim()) {
                errors[key] = translations.required_field;
            } else if (!PS_CODE_PATTERN.test(code)) {
                errors[key] = translations.invalid_playstation_code;
            } else if (
                credentials.playstationCodes.filter((c) => c === code).length >
                1
            ) {
                errors[key] = translations.duplicate_codes;
            }
        });
    } else {
        if (launcher === null) {
            errors.launcher = translations.required_field;
        }

        if (!credentials.eaEmail.trim()) {
            errors.eaEmail = translations.required_field;
        } else if (!validEmail(credentials.eaEmail)) {
            errors.eaEmail = translations.invalid_email;
        }

        if (!credentials.eaPassword) {
            errors.eaPassword = translations.required_field;
        }

        if (launcher === 'steam') {
            if (!credentials.steamUsername.trim()) {
                errors.steamUsername = translations.required_field;
            }

            if (!credentials.steamPassword) {
                errors.steamPassword = translations.required_field;
            }
        }
    }

    credentials.eaCodes.forEach((code, index) => {
        const key = `eaCode-${index}`;

        if (!code.trim()) {
            errors[key] = translations.required_field;
        } else if (!EA_CODE_PATTERN.test(code)) {
            errors[key] = translations.invalid_ea_code;
        } else if (credentials.eaCodes.filter((c) => c === code).length > 1) {
            errors[key] = translations.duplicate_codes;
        }
    });

    return errors;
}

export function validateManualFieldOnBlur(
    field: string,
    value: string,
    platform: ManualServicePlatform,
    launcher: PcLauncher | null,
    credentials: ManualCredentialsDraft,
    translations: ManualServiceCommonTranslations,
): string | undefined {
    if (value.trim() === '') {
        return undefined;
    }

    if (field === 'playstationEmail' || field === 'eaEmail') {
        if (!validEmail(value)) {
            return translations.invalid_email;
        }

        return undefined;
    }

    if (field.startsWith('eaCode-')) {
        const index = Number(field.replace('eaCode-', ''));

        if (!EA_CODE_PATTERN.test(value)) {
            return translations.invalid_ea_code;
        }

        const updatedCodes = [...credentials.eaCodes] as [
            string,
            string,
            string,
        ];
        updatedCodes[index] = value;

        if (updatedCodes.filter((c) => c === value && c !== '').length > 1) {
            return translations.duplicate_codes;
        }

        return undefined;
    }

    if (field.startsWith('playstationCode-')) {
        const index = Number(field.replace('playstationCode-', ''));

        if (!PS_CODE_PATTERN.test(value)) {
            return translations.invalid_playstation_code;
        }

        const updatedCodes = [...credentials.playstationCodes] as [
            string,
            string,
            string,
        ];
        updatedCodes[index] = value;

        if (updatedCodes.filter((c) => c === value && c !== '').length > 1) {
            return translations.duplicate_codes;
        }

        return undefined;
    }

    return undefined;
}

export function newManualAttemptKey(): string {
    return newAttemptKey('manual');
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
    return EMAIL_PATTERN.test(value);
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
