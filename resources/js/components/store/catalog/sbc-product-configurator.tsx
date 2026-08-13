import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { formatMinorUnits } from '@/lib/money';
import { SbcCartRequestError, submitSbcCart } from '@/lib/sbc-cart-api';
import type { CoinsCredentialField, CoinsCredentials } from '@/types/coins';
import type {
    CatalogProduct,
    ProductTranslations,
} from '@/types/store-content';

type CredentialErrors = Partial<Record<CoinsCredentialField, string>>;
const CODE_FIELDS = ['code-0', 'code-1', 'code-2'] as const;
const EMPTY_CREDENTIALS: CoinsCredentials = {
    eaEmail: '',
    eaPassword: '',
    backupCodes: ['', '', ''],
};

function newAttemptKey(): string {
    return typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `sbc-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function initialVariant(product: CatalogProduct, currentUrl: string): string {
    const requested = new URL(
        currentUrl,
        'https://store.arab-ut.com',
    ).searchParams.get('variant');

    const requestedVariant = product.variants.find(
        (variant) => variant.id === requested && variant.price !== null,
    );

    if (requestedVariant !== undefined) {
        return requestedVariant.id;
    }

    return (
        product.variants.find(
            (variant) =>
                variant.platform === 'playstation' && variant.price !== null,
        )?.id ??
        product.variants.find((variant) => variant.price !== null)?.id ??
        ''
    );
}

function validate(
    credentials: CoinsCredentials,
    copy: ProductTranslations['sbc'],
): CredentialErrors {
    const errors: CredentialErrors = {};

    if (
        credentials.eaEmail.length > 254 ||
        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(credentials.eaEmail)
    ) {
        errors.email = copy.required_email;
    }

    if (
        credentials.eaPassword.length < 1 ||
        credentials.eaPassword.length > 128
    ) {
        errors.password = copy.required_password;
    }

    CODE_FIELDS.forEach((field, index) => {
        const code = credentials.backupCodes[index];

        if (!/^[0-9]{8}$/.test(code)) {
            errors[field] = copy.required_code;
        } else if (credentials.backupCodes.indexOf(code) !== index) {
            errors[field] = copy.duplicate_code;
        }
    });

    return errors;
}

export function SbcProductConfigurator({
    addUrl,
    currentUrl,
    locale,
    product,
    translations,
}: {
    addUrl: string;
    currentUrl: string;
    locale: 'ar' | 'en';
    product: CatalogProduct;
    translations: ProductTranslations;
}) {
    const [variantId, setVariantId] = useState(() =>
        initialVariant(product, currentUrl),
    );
    const [credentials, setCredentials] =
        useState<CoinsCredentials>(EMPTY_CREDENTIALS);
    const [errors, setErrors] = useState<CredentialErrors>({});
    const [passwordVisible, setPasswordVisible] = useState(false);
    const [state, setState] = useState<
        'idle' | 'loading' | 'success' | 'error'
    >('idle');
    const attemptKey = useRef(newAttemptKey());
    const fieldRefs = useRef<
        Partial<Record<CoinsCredentialField, HTMLInputElement | null>>
    >({});
    const pendingFocus = useRef<CoinsCredentialField | null>(null);
    const visitTimer = useRef<number | null>(null);
    const variant = product.variants.find((option) => option.id === variantId);
    const locked = state === 'loading' || state === 'success';

    useEffect(
        () => () => {
            if (visitTimer.current !== null) {
                window.clearTimeout(visitTimer.current);
            }
        },
        [],
    );

    useEffect(() => {
        if (state !== 'error' || pendingFocus.current === null) {
            return;
        }

        fieldRefs.current[pendingFocus.current]?.focus();
        pendingFocus.current = null;
    }, [state]);

    function updateCredential<Key extends keyof CoinsCredentials>(
        key: Key,
        value: CoinsCredentials[Key],
        field: CoinsCredentialField,
    ) {
        if (locked) {
            return;
        }

        setCredentials((current) => ({ ...current, [key]: value }));
        setErrors((current) => ({ ...current, [field]: undefined }));
        setState('idle');
    }

    function updateCode(index: 0 | 1 | 2, rawCode: string) {
        const backupCodes: [string, string, string] = [
            ...credentials.backupCodes,
        ];
        backupCodes[index] = rawCode.replace(/[^0-9]/g, '').slice(0, 8);
        updateCredential('backupCodes', backupCodes, `code-${index}`);
    }

    async function add() {
        const nextErrors = validate(credentials, translations.sbc);
        const firstError = Object.keys(nextErrors)[0] as
            CoinsCredentialField | undefined;
        setErrors(nextErrors);

        if (firstError !== undefined) {
            fieldRefs.current[firstError]?.focus();

            return;
        }

        if (
            variant === undefined ||
            variant.price === null ||
            state === 'loading'
        ) {
            return;
        }

        setState('loading');

        try {
            const result = await submitSbcCart({
                cartUrl: addUrl,
                credentials,
                idempotencyKey: attemptKey.current,
                variantId: variant.id,
            });
            attemptKey.current = newAttemptKey();
            setCredentials(EMPTY_CREDENTIALS);
            setState('success');
            window.dispatchEvent(
                new CustomEvent<number>('arabut:cart-count', {
                    detail: result.cartCount,
                }),
            );
            visitTimer.current = window.setTimeout(
                () => router.visit(result.cartUrl),
                420,
            );
        } catch (failure) {
            if (failure instanceof SbcCartRequestError) {
                if (failure.conclusive) {
                    attemptKey.current = newAttemptKey();
                }

                const firstRejected = failure.validationFields[0];

                if (firstRejected !== undefined) {
                    pendingFocus.current = firstRejected;
                }
            }

            setState('error');
        }
    }

    return (
        <div className="sbc-product-configurator">
            <fieldset className="sbc-product-platforms" disabled={locked}>
                <legend>{translations.sbc.platform_legend}</legend>
                <div>
                    {product.variants.map((option) => (
                        <label key={option.id}>
                            <input
                                checked={option.id === variantId}
                                disabled={option.price === null}
                                name="sbc-platform"
                                onChange={() => setVariantId(option.id)}
                                type="radio"
                                value={option.id}
                            />
                            <span>{option.name}</span>
                            <strong>
                                {option.price === null
                                    ? translations.unavailable_price
                                    : formatMinorUnits(
                                          option.price.amountMinor,
                                          option.price.currency,
                                          locale,
                                      )}
                            </strong>
                        </label>
                    ))}
                </div>
            </fieldset>

            <section
                aria-labelledby="sbc-credentials-title"
                className="sbc-product-credentials"
            >
                <h2 id="sbc-credentials-title">
                    {translations.sbc.credentials_title}
                </h2>
                <CredentialField
                    disabled={locked}
                    error={errors.email}
                    id="sbc-ea-email"
                    inputRef={(node) => {
                        fieldRefs.current.email = node;
                    }}
                    label={translations.sbc.email}
                    onChange={(value) =>
                        updateCredential('eaEmail', value, 'email')
                    }
                    type="email"
                    value={credentials.eaEmail}
                />
                <div className="sbc-credential-field">
                    <label htmlFor="sbc-ea-password">
                        {translations.sbc.password}
                    </label>
                    <div className="sbc-password-control">
                        <input
                            aria-describedby={
                                errors.password === undefined
                                    ? undefined
                                    : 'sbc-ea-password-error'
                            }
                            aria-invalid={errors.password !== undefined}
                            autoComplete="off"
                            data-1p-ignore="true"
                            data-lpignore="true"
                            dir="ltr"
                            disabled={locked}
                            id="sbc-ea-password"
                            onChange={(event) =>
                                updateCredential(
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
                                    ? translations.sbc.hide_password
                                    : translations.sbc.show_password
                            }
                            disabled={locked}
                            onClick={() =>
                                setPasswordVisible((visible) => !visible)
                            }
                            type="button"
                        >
                            <EyeIcon />
                        </button>
                    </div>
                    <FieldError
                        error={errors.password}
                        id="sbc-ea-password-error"
                    />
                </div>
                <fieldset className="sbc-backup-codes" disabled={locked}>
                    <legend>{translations.sbc.backup_codes}</legend>
                    <p>{translations.sbc.backup_help}</p>
                    <div>
                        {CODE_FIELDS.map((field, index) => {
                            const number = String(index + 1);
                            const label = translations.sbc.backup_code.replace(
                                ':number',
                                number,
                            );

                            return (
                                <div
                                    className="sbc-credential-field"
                                    key={field}
                                >
                                    <label htmlFor={`sbc-backup-${index}`}>
                                        {label}
                                    </label>
                                    <input
                                        aria-describedby={
                                            errors[field] === undefined
                                                ? undefined
                                                : `sbc-backup-${index}-error`
                                        }
                                        aria-invalid={
                                            errors[field] !== undefined
                                        }
                                        autoComplete="off"
                                        data-1p-ignore="true"
                                        data-lpignore="true"
                                        dir="ltr"
                                        id={`sbc-backup-${index}`}
                                        inputMode="numeric"
                                        maxLength={8}
                                        onChange={(event) =>
                                            updateCode(
                                                index as 0 | 1 | 2,
                                                event.currentTarget.value,
                                            )
                                        }
                                        pattern="[0-9]{8}"
                                        placeholder="12345678"
                                        ref={(node) => {
                                            fieldRefs.current[field] = node;
                                        }}
                                        spellCheck={false}
                                        value={credentials.backupCodes[index]}
                                    />
                                    <FieldError
                                        error={errors[field]}
                                        id={`sbc-backup-${index}-error`}
                                    />
                                </div>
                            );
                        })}
                    </div>
                </fieldset>
            </section>

            <dl className="sbc-product-summary">
                <div>
                    <dt>{translations.sbc.selected}</dt>
                    <dd className="sbc-product-summary__platform">
                        {variant?.name ?? '—'}
                    </dd>
                </div>
                <div>
                    <dt>{translations.sbc.total}</dt>
                    <dd>
                        {variant?.price === null || variant === undefined
                            ? translations.unavailable_price
                            : formatMinorUnits(
                                  variant.price.amountMinor,
                                  variant.price.currency,
                                  locale,
                              )}
                    </dd>
                </div>
            </dl>
            <button
                className="sbc-product-add"
                data-state={state}
                disabled={state === 'loading' || variant?.price == null}
                onClick={() => void add()}
                type="button"
            >
                {state === 'loading'
                    ? translations.adding
                    : translations.add_to_cart}
            </button>
            {state === 'success' ? (
                <p className="sbc-product-status" role="status">
                    {translations.sbc.success}
                </p>
            ) : null}
            {state === 'error' ? (
                <p className="sbc-product-status" role="alert">
                    {translations.add_error}
                </p>
            ) : null}
        </div>
    );
}

function CredentialField({
    disabled,
    error,
    id,
    inputRef,
    label,
    onChange,
    type,
    value,
}: {
    disabled: boolean;
    error: string | undefined;
    id: string;
    inputRef: (node: HTMLInputElement | null) => void;
    label: string;
    onChange: (value: string) => void;
    type: 'email';
    value: string;
}) {
    return (
        <div className="sbc-credential-field">
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
                disabled={disabled}
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
        <p id={id} role="alert">
            {error}
        </p>
    );
}

function EyeIcon() {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="20"
            viewBox="0 0 24 24"
            width="20"
        >
            <path
                d="M2 12s3-8 10-8 10 8 10 8-3 8-10 8-10-8-10-8Zm10 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"
                stroke="currentColor"
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="1.7"
            />
        </svg>
    );
}
