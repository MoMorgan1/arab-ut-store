import { useEffect, useRef, useState } from 'react';
import type { Ref } from 'react';

import { formatInteger } from '@/lib/money';
import type {
    CoinsCredentialField,
    CoinsCredentials,
    CoinsQuoteViewState,
    CoinsStoreTranslations,
} from '@/types/coins';

import { interpolate } from './configurator-copy';

type CredentialErrors = Partial<Record<CoinsCredentialField, string>>;

type CredentialsStepProps = {
    credentials: CoinsCredentials;
    focusRef: Ref<HTMLHeadingElement>;
    locale: 'ar' | 'en';
    onBack: () => void;
    onCancel: () => void;
    onChange: (credentials: CoinsCredentials) => void;
    onContinue: () => void;
    quoteState: CoinsQuoteViewState;
    rejectedFields: CoinsCredentialField[];
    translations: CoinsStoreTranslations;
};

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const BACKUP_CODE_FIELDS = [
    'code-0',
    'code-1',
    'code-2',
] as const satisfies readonly CoinsCredentialField[];

function validateCredentials(
    credentials: CoinsCredentials,
    translations: CoinsStoreTranslations['credentials'],
): CredentialErrors {
    const errors: CredentialErrors = {};

    if (
        credentials.eaEmail.length > 254 ||
        !EMAIL_PATTERN.test(credentials.eaEmail)
    ) {
        errors.email = translations.required_email;
    }

    if (
        credentials.eaPassword.length < 1 ||
        credentials.eaPassword.length > 128
    ) {
        errors.password = translations.required_password;
    }

    BACKUP_CODE_FIELDS.forEach((field, index) => {
        const code = credentials.backupCodes[index];

        if (!/^[0-9]{8}$/.test(code)) {
            errors[field] = translations.required_code;
        } else if (credentials.backupCodes.indexOf(code) !== index) {
            errors[field] = translations.duplicate_code;
        }
    });

    return errors;
}

export function emptyCoinsCredentials(): CoinsCredentials {
    return {
        backupCodes: ['', '', ''],
        eaEmail: '',
        eaPassword: '',
    };
}

export function CredentialsStep({
    credentials,
    focusRef,
    locale,
    onBack,
    onCancel,
    onChange,
    onContinue,
    quoteState,
    rejectedFields,
    translations,
}: CredentialsStepProps) {
    const [errors, setErrors] = useState<CredentialErrors>(() =>
        credentialErrors(rejectedFields, translations.credentials),
    );
    const [passwordVisible, setPasswordVisible] = useState(false);
    const fieldRefs = useRef<
        Partial<Record<CoinsCredentialField, HTMLInputElement | null>>
    >({ email: null, password: null });
    const quoteMessage = credentialsQuoteMessage(quoteState, translations);

    useEffect(() => {
        return () => {
            fieldRefs.current = { email: null, password: null };
        };
    }, []);

    useEffect(() => {
        const firstRejectedField = rejectedFields[0];

        if (firstRejectedField === undefined) {
            return;
        }

        fieldRefs.current[firstRejectedField]?.focus();
    }, [rejectedFields]);

    function updateField(
        field: 'eaEmail' | 'eaPassword',
        value: string,
        errorField: CoinsCredentialField,
    ) {
        setErrors((current) => ({ ...current, [errorField]: undefined }));
        onChange({ ...credentials, [field]: value });
    }

    function updateCode(
        index: number,
        rawCode: string,
        field: CoinsCredentialField,
    ) {
        const backupCodes = [
            ...credentials.backupCodes,
        ] as CoinsCredentials['backupCodes'];
        backupCodes[index] = rawCode.replace(/[^0-9]/g, '').slice(0, 8);
        setErrors((current) => ({ ...current, [field]: undefined }));
        onChange({ ...credentials, backupCodes });
    }

    function submitCredentials() {
        const nextErrors = validateCredentials(
            credentials,
            translations.credentials,
        );
        const firstError = Object.keys(nextErrors)[0] as
            CoinsCredentialField | undefined;

        setErrors(nextErrors);

        if (firstError === undefined) {
            onContinue();
        } else {
            fieldRefs.current[firstError]?.focus();
        }
    }

    return (
        <section
            aria-labelledby="coins-credentials-title"
            className="coins-step"
        >
            <h2
                className="coins-step__title"
                id="coins-credentials-title"
                ref={focusRef}
                tabIndex={-1}
            >
                {translations.credentials.title}
            </h2>
            <div className="coins-credentials-form">
                <CredentialInput
                    error={errors.email}
                    id="coins-ea-email"
                    inputRef={(node) => {
                        fieldRefs.current.email = node;
                    }}
                    label={translations.credentials.email}
                    onChange={(value) => updateField('eaEmail', value, 'email')}
                    type="email"
                    value={credentials.eaEmail}
                />

                <div className="coins-credential-field">
                    <label htmlFor="coins-ea-password">
                        {translations.credentials.password}
                    </label>
                    <div className="coins-password-control">
                        <input
                            aria-describedby={
                                errors.password === undefined
                                    ? undefined
                                    : 'coins-ea-password-error'
                            }
                            aria-invalid={errors.password !== undefined}
                            autoComplete="off"
                            data-1p-ignore="true"
                            data-lpignore="true"
                            dir="ltr"
                            id="coins-ea-password"
                            onChange={(event) =>
                                updateField(
                                    'eaPassword',
                                    event.currentTarget.value,
                                    'password',
                                )
                            }
                            ref={(node) => {
                                fieldRefs.current.password = node;
                            }}
                            spellCheck={false}
                            type={passwordVisible ? 'text' : 'password'}
                            value={credentials.eaPassword}
                        />
                        <button
                            aria-label={
                                passwordVisible
                                    ? translations.credentials.hide_password
                                    : translations.credentials.show_password
                            }
                            onClick={() =>
                                setPasswordVisible((visible) => !visible)
                            }
                            type="button"
                        >
                            <EyeIcon hidden={passwordVisible} />
                        </button>
                    </div>
                    <FieldError
                        error={errors.password}
                        id="coins-ea-password-error"
                    />
                </div>

                <fieldset className="coins-backup-codes">
                    <legend>{translations.credentials.backup_codes}</legend>
                    <p>{translations.credentials.backup_help}</p>
                    <div className="coins-backup-codes__grid">
                        {BACKUP_CODE_FIELDS.map((field, index) => {
                            const code = credentials.backupCodes[index];
                            const label = interpolate(
                                translations.credentials.backup_code,
                                { number: formatInteger(index + 1, locale) },
                            );

                            return (
                                <div
                                    className="coins-credential-field coins-backup-code"
                                    key={field}
                                >
                                    <label
                                        className="sr-only"
                                        htmlFor={`coins-backup-${index}`}
                                    >
                                        {label}
                                    </label>
                                    <span
                                        aria-hidden="true"
                                        className="coins-backup-code__number"
                                    >
                                        {formatInteger(index + 1, locale)}
                                    </span>
                                    <input
                                        aria-describedby={
                                            errors[field] === undefined
                                                ? undefined
                                                : `coins-backup-${index}-error`
                                        }
                                        aria-invalid={
                                            errors[field] !== undefined
                                        }
                                        autoComplete="off"
                                        data-1p-ignore="true"
                                        data-lpignore="true"
                                        dir="ltr"
                                        id={`coins-backup-${index}`}
                                        inputMode="numeric"
                                        maxLength={8}
                                        onChange={(event) =>
                                            updateCode(
                                                index,
                                                event.currentTarget.value,
                                                field,
                                            )
                                        }
                                        pattern="[0-9]{8}"
                                        placeholder="12345678"
                                        ref={(node) => {
                                            fieldRefs.current[field] = node;
                                        }}
                                        spellCheck={false}
                                        type="text"
                                        value={code}
                                    />
                                    <FieldError
                                        error={errors[field]}
                                        id={`coins-backup-${index}-error`}
                                    />
                                </div>
                            );
                        })}
                    </div>
                </fieldset>
            </div>

            {quoteMessage === null ? null : (
                <p
                    className="coins-credentials__quote-state"
                    id="coins-credentials-quote-state"
                    role={
                        quoteState.status === 'unavailable' ||
                        quoteState.status === 'validation'
                            ? 'alert'
                            : 'status'
                    }
                >
                    {quoteMessage}
                </p>
            )}

            <div className="coins-step__actions coins-step__actions--split">
                <button
                    className="coins-secondary-action"
                    onClick={onBack}
                    type="button"
                >
                    {translations.actions.back}
                </button>
                <button
                    aria-describedby={
                        quoteMessage === null
                            ? undefined
                            : 'coins-credentials-quote-state'
                    }
                    className="coins-primary-action"
                    disabled={quoteState.status !== 'success'}
                    onClick={submitCredentials}
                    type="button"
                >
                    {translations.actions.continue}
                </button>
            </div>
            <button
                className="coins-clear-action"
                onClick={onCancel}
                type="button"
            >
                {translations.credentials.clear}
            </button>
        </section>
    );
}

function credentialErrorMessage(
    field: CoinsCredentialField,
    translations: CoinsStoreTranslations['credentials'],
): string {
    if (field === 'email') {
        return translations.required_email;
    }

    return field === 'password'
        ? translations.required_password
        : translations.required_code;
}

function credentialErrors(
    fields: CoinsCredentialField[],
    translations: CoinsStoreTranslations['credentials'],
): CredentialErrors {
    return Object.fromEntries(
        fields.map((field) => [
            field,
            credentialErrorMessage(field, translations),
        ]),
    );
}

function credentialsQuoteMessage(
    state: CoinsQuoteViewState,
    translations: CoinsStoreTranslations,
): string | null {
    switch (state.status) {
        case 'success':
            return null;
        case 'unavailable':
            return translations.quote.unavailable;
        case 'validation':
            return translations.quote.validation_error;
        case 'refreshing':
            return translations.quote.refreshing;
        case 'idle':
        case 'loading':
            return translations.quote.loading;
    }
}

type CredentialInputProps = {
    error: string | undefined;
    id: string;
    inputRef: (node: HTMLInputElement | null) => void;
    label: string;
    onChange: (value: string) => void;
    type: 'email';
    value: string;
};

function CredentialInput(props: CredentialInputProps) {
    const { error, id, inputRef, label, onChange, type, value } = props;

    return (
        <div className="coins-credential-field">
            <label htmlFor={id}>{label}</label>
            <input
                aria-describedby={
                    error === undefined ? undefined : `${id}-error`
                }
                aria-invalid={error !== undefined}
                autoComplete="off"
                data-1p-ignore="true"
                data-lpignore="true"
                dir="ltr"
                id={id}
                onChange={(event) => onChange(event.currentTarget.value)}
                ref={inputRef}
                spellCheck={false}
                type={type}
                value={value}
            />
            <FieldError error={error} id={`${id}-error`} />
        </div>
    );
}

function FieldError({ error, id }: { error: string | undefined; id: string }) {
    return error === undefined ? null : (
        <p className="coins-field-error" id={id} role="alert">
            {error}
        </p>
    );
}

function EyeIcon({ hidden }: { hidden: boolean }) {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="20"
            viewBox="0 0 24 24"
            width="20"
        >
            <path
                d={
                    hidden
                        ? 'M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 4.2A10.7 10.7 0 0 1 12 4c7 0 10 8 10 8a18 18 0 0 1-2.1 3.3M6.6 6.6C3.5 8.5 2 12 2 12s3 8 10 8a10.7 10.7 0 0 0 5.4-1.4'
                        : 'M2 12s3-8 10-8 10 8 10 8-3 8-10 8-10-8-10-8Zm10 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z'
                }
                stroke="currentColor"
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="1.7"
            />
        </svg>
    );
}
