import { useEffect, useRef, useState } from 'react';
import type { Ref } from 'react';
import { createPortal } from 'react-dom';

import { formatInteger } from '@/lib/money';
import type {
    CoinsCredentialField,
    CoinsCredentials,
    CoinsDeliveryValue,
    CoinsPlatformValue,
    CoinsQuoteViewState,
    CoinsStoreTranslations,
} from '@/types/coins';

import { interpolate } from './configurator-copy';

type CredentialErrors = Partial<Record<CoinsCredentialField, string>>;

type CredentialsStepProps = {
    credentials: CoinsCredentials;
    delivery: CoinsDeliveryValue | null;
    focusRef: Ref<HTMLHeadingElement>;
    locale: 'ar' | 'en';
    onBack: () => void;
    onCancel: () => void;
    onChange: (credentials: CoinsCredentials) => void;
    onContinue: () => void;
    platform: CoinsPlatformValue;
    quoteState: CoinsQuoteViewState;
    rejectedFields: CoinsCredentialField[];
    requiresCurrentBalance: boolean;
    termsUrl: string;
    translations: CoinsStoreTranslations;
    warrantyUrl: string;
};

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const BACKUP_CODE_FIELDS = [
    'code-0',
    'code-1',
    'code-2',
] as const satisfies readonly CoinsCredentialField[];

function validateCredentials(
    credentials: CoinsCredentials,
    requiresBalance: boolean,
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

    if (
        requiresBalance &&
        (!/^[0-9]+$/.test(credentials.currentBalance ?? '') ||
            Number(credentials.currentBalance) > 100_000_000)
    ) {
        errors['current-balance'] = translations.required_balance;
    }

    if (credentials.companionMarketOpen !== true) {
        errors.companion = translations.required_companion;
    }

    if (credentials.policyAccepted !== true) {
        errors.policy = translations.required_policy;
    }

    return errors;
}

export function emptyCoinsCredentials(): CoinsCredentials {
    return {
        backupCodes: ['', '', ''],
        eaEmail: '',
        eaPassword: '',
        currentBalance: '',
        companionMarketOpen: false,
        policyAccepted: false,
    };
}

export function CredentialsStep({
    credentials,
    delivery,
    focusRef,
    locale,
    onBack,
    onCancel,
    onChange,
    onContinue,
    platform,
    quoteState,
    rejectedFields,
    requiresCurrentBalance,
    termsUrl,
    translations,
    warrantyUrl,
}: CredentialsStepProps) {
    const [errors, setErrors] = useState<CredentialErrors>(() =>
        credentialErrors(rejectedFields, translations.credentials),
    );
    const [passwordVisible, setPasswordVisible] = useState(false);
    const [marketModalOpen, setMarketModalOpen] = useState(false);
    const marketGuideTriggerRef = useRef<HTMLButtonElement | null>(null);
    const marketModalCloseRef = useRef<HTMLButtonElement | null>(null);
    const fieldRefs = useRef<
        Partial<Record<CoinsCredentialField, HTMLInputElement | null>>
    >({ email: null, password: null });
    const quoteMessage = credentialsQuoteMessage(quoteState, translations);
    // The admin decides whether fast console orders state their balance;
    // the platform/delivery pair only says when the policy can apply.
    const requiresBalance =
        requiresCurrentBalance &&
        platform === 'playstation' &&
        delivery === 'fast';

    useEffect(() => {
        return () => {
            fieldRefs.current = { email: null, password: null };
        };
    }, []);

    useEffect(() => {
        if (!marketModalOpen) {
            return;
        }

        const previousOverflow = document.body.style.overflow;

        function closeMarketModal() {
            marketGuideTriggerRef.current?.focus();
            setMarketModalOpen(false);
        }

        function handleKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                closeMarketModal();
            }
        }

        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', handleKeyDown);
        marketModalCloseRef.current?.focus();

        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [marketModalOpen]);

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

    function updateBalance(rawBalance: string) {
        setErrors((current) => ({
            ...current,
            'current-balance': undefined,
        }));
        onChange({
            ...credentials,
            currentBalance: rawBalance.replace(/[^0-9]/g, '').slice(0, 9),
        });
    }

    function updateConfirmation(
        key: 'companionMarketOpen' | 'policyAccepted',
        field: 'companion' | 'policy',
        checked: boolean,
    ) {
        setErrors((current) => ({ ...current, [field]: undefined }));
        onChange({ ...credentials, [key]: checked });
    }

    /**
     * Blur-time validation for one field. Empty fields stay quiet so
     * tabbing through the form does not scold before anything was typed;
     * submit still validates everything.
     */
    function validateFieldOnBlur(field: CoinsCredentialField, value: string) {
        if (value === '') {
            return;
        }

        const nextErrors = validateCredentials(
            credentials,
            requiresBalance,
            translations.credentials,
        );

        setErrors((current) => ({ ...current, [field]: nextErrors[field] }));
    }

    function submitCredentials() {
        const nextErrors = validateCredentials(
            credentials,
            requiresBalance,
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
                    onBlur={() =>
                        validateFieldOnBlur('email', credentials.eaEmail)
                    }
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
                            onBlur={() =>
                                validateFieldOnBlur(
                                    'password',
                                    credentials.eaPassword,
                                )
                            }
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
                                        onBlur={() =>
                                            validateFieldOnBlur(field, code)
                                        }
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

                {requiresBalance ? (
                    <div className="coins-credential-field coins-current-balance">
                        <label htmlFor="coins-current-balance">
                            {translations.credentials.current_balance}
                        </label>
                        <p id="coins-current-balance-help">
                            {translations.credentials.current_balance_help}
                        </p>
                        <input
                            aria-describedby={`coins-current-balance-help${
                                errors['current-balance'] === undefined
                                    ? ''
                                    : ' coins-current-balance-error'
                            }`}
                            aria-invalid={
                                errors['current-balance'] !== undefined
                            }
                            dir="ltr"
                            id="coins-current-balance"
                            inputMode="numeric"
                            maxLength={9}
                            onBlur={() =>
                                validateFieldOnBlur(
                                    'current-balance',
                                    credentials.currentBalance ?? '',
                                )
                            }
                            onChange={(event) =>
                                updateBalance(event.currentTarget.value)
                            }
                            ref={(node) => {
                                fieldRefs.current['current-balance'] = node;
                            }}
                            type="text"
                            value={credentials.currentBalance ?? ''}
                        />
                        <FieldError
                            error={errors['current-balance']}
                            id="coins-current-balance-error"
                        />
                    </div>
                ) : null}

                <div className="coins-fulfillment-confirmations">
                    <div
                        className={
                            errors.companion === undefined
                                ? 'coins-confirmation-field'
                                : 'coins-confirmation-field is-invalid'
                        }
                    >
                        <label htmlFor="coins-companion-market">
                            <input
                                aria-describedby={`coins-companion-help${
                                    errors.companion === undefined
                                        ? ''
                                        : ' coins-companion-error'
                                }`}
                                aria-invalid={errors.companion !== undefined}
                                checked={
                                    credentials.companionMarketOpen === true
                                }
                                id="coins-companion-market"
                                onChange={(event) =>
                                    updateConfirmation(
                                        'companionMarketOpen',
                                        'companion',
                                        event.currentTarget.checked,
                                    )
                                }
                                ref={(node) => {
                                    fieldRefs.current.companion = node;
                                }}
                                type="checkbox"
                            />
                            <span>
                                {translations.credentials.companion_market_open}
                            </span>
                        </label>
                        <p id="coins-companion-help">
                            {translations.credentials.companion_help}
                        </p>
                        <FieldError
                            error={errors.companion}
                            id="coins-companion-error"
                        />
                        <button
                            aria-expanded={marketModalOpen}
                            aria-haspopup="dialog"
                            className="market-help-hint"
                            onClick={() => setMarketModalOpen(true)}
                            ref={marketGuideTriggerRef}
                            type="button"
                        >
                            {translations.credentials.market_guide}
                        </button>
                    </div>

                    <div
                        className={
                            errors.policy === undefined
                                ? 'coins-confirmation-field'
                                : 'coins-confirmation-field is-invalid'
                        }
                    >
                        <label htmlFor="coins-policy-accepted">
                            <input
                                aria-describedby={`coins-policy-help${
                                    errors.policy === undefined
                                        ? ''
                                        : ' coins-policy-error'
                                }`}
                                aria-invalid={errors.policy !== undefined}
                                checked={credentials.policyAccepted === true}
                                id="coins-policy-accepted"
                                onChange={(event) =>
                                    updateConfirmation(
                                        'policyAccepted',
                                        'policy',
                                        event.currentTarget.checked,
                                    )
                                }
                                ref={(node) => {
                                    fieldRefs.current.policy = node;
                                }}
                                type="checkbox"
                            />
                            <span>
                                {translations.credentials.policy_accepted}
                            </span>
                        </label>
                        <p id="coins-policy-help">
                            <span>{translations.credentials.policy_help}</span>
                            <span className="coins-policy-links">
                                <a
                                    className="coins-policy-link"
                                    href={termsUrl}
                                >
                                    {translations.credentials.terms_link}
                                </a>
                                <a
                                    className="coins-policy-link"
                                    href={warrantyUrl}
                                >
                                    {translations.credentials.warranty_link}
                                </a>
                            </span>
                        </p>
                        <FieldError
                            error={errors.policy}
                            id="coins-policy-error"
                        />
                    </div>
                </div>
            </div>

            {marketModalOpen
                ? createPortal(
                      <div
                          className="market-modal-overlay is-open"
                          onMouseDown={(event) => {
                              if (event.currentTarget === event.target) {
                                  marketGuideTriggerRef.current?.focus();
                                  setMarketModalOpen(false);
                              }
                          }}
                      >
                          <div
                              aria-labelledby="coins-market-modal-title"
                              aria-modal="true"
                              className="market-modal"
                              dir={locale === 'ar' ? 'rtl' : 'ltr'}
                              role="dialog"
                          >
                              <button
                                  aria-label={
                                      translations.credentials.market_modal
                                          .close
                                  }
                                  className="market-modal-close"
                                  onClick={() => {
                                      marketGuideTriggerRef.current?.focus();
                                      setMarketModalOpen(false);
                                  }}
                                  ref={marketModalCloseRef}
                                  type="button"
                              >
                                  &times;
                              </button>

                              <header className="market-modal-header">
                                  <span className="market-modal-badge">
                                      <HelpIcon />
                                      {
                                          translations.credentials.market_modal
                                              .badge
                                      }
                                  </span>
                                  <h2
                                      className="market-modal-title"
                                      id="coins-market-modal-title"
                                  >
                                      {
                                          translations.credentials.market_modal
                                              .title
                                      }
                                  </h2>
                                  <p className="market-modal-subtitle">
                                      {
                                          translations.credentials.market_modal
                                              .subtitle
                                      }
                                  </p>
                              </header>

                              <ol className="market-steps">
                                  {translations.credentials.market_modal.steps.map(
                                      (step, index) => (
                                          <li
                                              className="market-step"
                                              key={step.title}
                                          >
                                              <span className="market-step-num">
                                                  {formatInteger(
                                                      index + 1,
                                                      locale,
                                                  )}
                                              </span>
                                              <span className="market-step-body">
                                                  <strong>{step.title}</strong>
                                                  <span>{step.body}</span>
                                              </span>
                                          </li>
                                      ),
                                  )}
                              </ol>

                              <div className="market-compare">
                                  <MarketCompareCard
                                      badge={
                                          translations.credentials.market_modal
                                              .open_badge
                                      }
                                      description={
                                          translations.credentials.market_modal
                                              .open_description
                                      }
                                      imageAlt={
                                          translations.credentials
                                              .market_open_label
                                      }
                                      imageSrc="/images/store/coins/market-open.webp"
                                      variant="open"
                                  />
                                  <MarketCompareCard
                                      badge={
                                          translations.credentials.market_modal
                                              .closed_badge
                                      }
                                      description={
                                          translations.credentials.market_modal
                                              .closed_description
                                      }
                                      imageAlt={
                                          translations.credentials
                                              .market_closed_label
                                      }
                                      imageSrc="/images/store/coins/market-closed.webp"
                                      variant="closed"
                                  />
                              </div>

                              <p className="market-modal-note">
                                  <WarningIcon />
                                  <span>
                                      {
                                          translations.credentials.market_modal
                                              .note
                                      }
                                  </span>
                              </p>
                          </div>
                      </div>,
                      document.body,
                  )
                : null}

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
                <button
                    className="coins-secondary-action"
                    onClick={onBack}
                    type="button"
                >
                    {translations.actions.back}
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

    if (field === 'password') {
        return translations.required_password;
    }

    if (field === 'current-balance') {
        return translations.required_balance;
    }

    if (field === 'companion') {
        return translations.required_companion;
    }

    return field === 'policy'
        ? translations.required_policy
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
    onBlur: () => void;
    onChange: (value: string) => void;
    type: 'email';
    value: string;
};

function CredentialInput(props: CredentialInputProps) {
    const { error, id, inputRef, label, onBlur, onChange, type, value } = props;

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
                onBlur={onBlur}
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
            <span aria-hidden="true" className="coins-field-error__icon" />
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

function MarketCompareCard({
    badge,
    description,
    imageAlt,
    imageSrc,
    variant,
}: {
    badge: string;
    description: string;
    imageAlt: string;
    imageSrc: string;
    variant: 'open' | 'closed';
}) {
    return (
        <figure className={`market-compare-card market-${variant}`}>
            <figcaption
                className={`market-compare-badge market-badge-${variant}`}
            >
                {variant === 'open' ? <CheckIcon /> : <CloseIcon />}
                {badge}
            </figcaption>
            <span className="market-compare-img-wrap">
                <img
                    alt={imageAlt}
                    className="market-compare-img"
                    height="840"
                    loading="lazy"
                    src={imageSrc}
                    width="472"
                />
            </span>
            <span className="market-compare-label">{description}</span>
        </figure>
    );
}

function HelpIcon() {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="13"
            viewBox="0 0 24 24"
            width="13"
        >
            <circle
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                strokeWidth="2.5"
            />
            <path
                d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3M12 17h.01"
                stroke="currentColor"
                strokeLinecap="round"
                strokeWidth="2.5"
            />
        </svg>
    );
}

function CheckIcon() {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="13"
            viewBox="0 0 24 24"
            width="13"
        >
            <path
                d="m4 12 5 5L20 6"
                stroke="currentColor"
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2.5"
            />
        </svg>
    );
}

function CloseIcon() {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="13"
            viewBox="0 0 24 24"
            width="13"
        >
            <path
                d="m6 6 12 12M18 6 6 18"
                stroke="currentColor"
                strokeLinecap="round"
                strokeWidth="2.5"
            />
        </svg>
    );
}

function WarningIcon() {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="14"
            viewBox="0 0 24 24"
            width="14"
        >
            <path
                d="M10.29 3.86 1.82 18A2 2 0 0 0 3.53 21h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"
                stroke="currentColor"
                strokeWidth="2"
            />
            <path
                d="M12 9v4m0 4h.01"
                stroke="currentColor"
                strokeLinecap="round"
                strokeWidth="2"
            />
        </svg>
    );
}
