import { Head, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { interpolate } from '@/components/configurator/coins/configurator-copy';
import StoreLayout from '@/layouts/store-layout';
import {
    loadCartCredentials,
    updateCartCredentials,
} from '@/lib/cart-credentials-api';
import type { StoredCartCredentials } from '@/lib/cart-credentials-api';
import {
    CheckoutPhoneError,
    reloadAfterPhoneVerification,
    sendCheckoutPhoneCode,
    verifyCheckoutPhoneCode,
} from '@/lib/checkout-phone-api';
import { formatCoins, formatInteger, formatMinorUnits } from '@/lib/money';
import {
    navigateToHostedPayment,
    navigateToOrder,
    PaylinkCheckoutError,
    startPaylinkCheckout,
} from '@/lib/paylink-checkout-api';
import type {
    StoreCartItem,
    StoreCartPageProps,
    StoreCartTranslations,
} from '@/types/store-shell';

export default function StoreCart() {
    const page = usePage<StoreCartPageProps>();
    const {
        cart,
        cartCount,
        cartPage,
        direction,
        displayCurrencies,
        displayCurrency,
        locale,
        storeShell,
        ui,
    } = page.props;

    return (
        <StoreLayout
            cartCount={cartCount}
            currentUrl={page.url}
            direction={direction}
            displayCurrencies={displayCurrencies}
            displayCurrency={displayCurrency}
            locale={locale}
            storeShell={storeShell}
            ui={ui}
        >
            <Head title={cartPage.translations.title} />
            <section
                aria-labelledby="store-cart-title"
                className="store-cart-page"
            >
                <header className="store-cart-page__heading">
                    <p>{cartPage.translations.eyebrow}</p>
                    <h1 id="store-cart-title">{cartPage.translations.title}</h1>
                </header>

                {cart.items.length === 0 ? (
                    <p className="store-cart-empty">
                        {cartPage.translations.empty}
                    </p>
                ) : (
                    <ol className="store-cart-lines">
                        {cart.items.map((cartItem) => (
                            <CartLine
                                cartItem={cartItem}
                                key={cartItem.id}
                                locale={locale}
                                translations={cartPage.translations}
                            />
                        ))}
                    </ol>
                )}

                {cart.items.length > 0 ? (
                    <CheckoutPanel
                        authenticated={page.props.auth.user !== null}
                        checkout={cartPage.checkout}
                        locale={locale}
                        totalHalalah={cart.items.reduce(
                            (total, item) => total + item.totalHalalah,
                            0,
                        )}
                        translations={cartPage.translations}
                    />
                ) : null}

                <a className="store-cart-back" href={cartPage.backUrl}>
                    {cartPage.translations.back}
                </a>
            </section>
        </StoreLayout>
    );
}

function CheckoutPanel({
    authenticated,
    checkout,
    locale,
    totalHalalah,
    translations,
}: {
    authenticated: boolean;
    checkout: StoreCartPageProps['cartPage']['checkout'];
    locale: 'ar' | 'en';
    totalHalalah: number;
    translations: StoreCartTranslations;
}) {
    const idempotencyKey = useRef(crypto.randomUUID());
    const [state, setState] = useState<'idle' | 'loading' | 'error'>('idle');
    const [errorCode, setErrorCode] = useState<string | null>(null);

    async function startPayment() {
        if (!checkout.canCheckout || state === 'loading') {
            return;
        }

        setState('loading');
        setErrorCode(null);

        try {
            const result = await startPaylinkCheckout(
                checkout.checkoutUrl,
                idempotencyKey.current,
            );

            if (result.paymentUrl === null) {
                navigateToOrder(result.orderUrl);
            } else {
                navigateToHostedPayment(result.paymentUrl);
            }
        } catch (error) {
            if (error instanceof PaylinkCheckoutError) {
                setErrorCode(error.code);

                if (error.conclusive) {
                    idempotencyKey.current = crypto.randomUUID();
                }
            } else {
                setErrorCode('checkout_error');
            }

            setState('error');
        }
    }

    return (
        <aside
            className="store-cart-checkout"
            aria-label={translations.checkout}
        >
            <div className="store-cart-checkout__total">
                <span>{translations.order_total}</span>
                <strong>{formatMinorUnits(totalHalalah, 'SAR', locale)}</strong>
            </div>
            <p className="store-cart-checkout__secure">
                {translations.checkout_secure}
            </p>
            {!authenticated ? (
                <a
                    className="store-cart-checkout__action"
                    href={checkout.loginUrl}
                >
                    {translations.checkout_login}
                </a>
            ) : !checkout.phoneVerified ? (
                <CheckoutPhoneForm
                    checkout={checkout}
                    translations={translations}
                />
            ) : (
                <button
                    className="store-cart-checkout__action"
                    disabled={!checkout.canCheckout || state === 'loading'}
                    onClick={startPayment}
                    type="button"
                >
                    {state === 'loading'
                        ? translations.checkout_loading
                        : translations.checkout}
                </button>
            )}
            {state === 'error' ? (
                <p className="store-cart-checkout__error" role="alert">
                    {errorCode === 'cart_changed'
                        ? translations.checkout_cart_changed
                        : translations.checkout_error}
                </p>
            ) : null}
        </aside>
    );
}

const CHECKOUT_COUNTRY_CODES = [
    '+966',
    '+971',
    '+965',
    '+974',
    '+973',
    '+968',
    '+20',
] as const;

function CheckoutPhoneForm({
    checkout,
    translations,
}: {
    checkout: StoreCartPageProps['cartPage']['checkout'];
    translations: StoreCartTranslations;
}) {
    const [countryCode, setCountryCode] = useState('+966');
    const [localNumber, setLocalNumber] = useState('');
    const [code, setCode] = useState('');
    const [stage, setStage] = useState<'phone' | 'code'>('phone');
    const [busy, setBusy] = useState(false);
    const [errorCode, setErrorCode] = useState<string | null>(null);
    const phone = `${countryCode}${localNumber.replace(/^0+/, '')}`;
    const phoneValid = /^\+[1-9][0-9]{7,14}$/.test(phone);

    async function sendCode() {
        if (!phoneValid || busy) {
            setErrorCode('invalid_input');

            return;
        }

        setBusy(true);
        setErrorCode(null);

        try {
            await sendCheckoutPhoneCode(checkout.phoneCodeUrl, phone);
            setStage('code');
        } catch (error) {
            setErrorCode(
                error instanceof CheckoutPhoneError ? error.code : 'unknown',
            );
        } finally {
            setBusy(false);
        }
    }

    async function verifyCode() {
        if (!/^[0-9]{6}$/.test(code) || busy) {
            setErrorCode('invalid_input');

            return;
        }

        setBusy(true);
        setErrorCode(null);

        try {
            await verifyCheckoutPhoneCode(checkout.phoneVerifyUrl, phone, code);
            reloadAfterPhoneVerification();
        } catch (error) {
            setErrorCode(
                error instanceof CheckoutPhoneError ? error.code : 'unknown',
            );
            setBusy(false);
        }
    }

    return (
        <div className="store-cart-phone">
            <p className="store-cart-checkout__notice">
                {translations.checkout_phone}
            </p>
            <div className="store-cart-phone__number" dir="ltr">
                <label>
                    <span>{translations.phone_country}</span>
                    <select
                        aria-label={translations.phone_country}
                        disabled={busy || stage === 'code'}
                        onChange={(event) =>
                            setCountryCode(event.currentTarget.value)
                        }
                        value={countryCode}
                    >
                        {CHECKOUT_COUNTRY_CODES.map((value) => (
                            <option key={value} value={value}>
                                {value}
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    <span>{translations.phone_number}</span>
                    <input
                        aria-label={translations.phone_number}
                        autoComplete="tel-national"
                        disabled={busy || stage === 'code'}
                        inputMode="numeric"
                        maxLength={14}
                        onChange={(event) =>
                            setLocalNumber(
                                event.currentTarget.value.replace(
                                    /[^0-9]/g,
                                    '',
                                ),
                            )
                        }
                        type="tel"
                        value={localNumber}
                    />
                </label>
            </div>
            {stage === 'phone' ? (
                <button
                    className="store-cart-phone__button"
                    disabled={busy || !phoneValid}
                    onClick={sendCode}
                    type="button"
                >
                    {busy
                        ? translations.phone_sending
                        : translations.phone_send}
                </button>
            ) : (
                <>
                    <p className="store-cart-phone__sent" role="status">
                        {translations.phone_sent}
                    </p>
                    <label className="store-cart-phone__code">
                        <span>{translations.phone_code}</span>
                        <input
                            aria-label={translations.phone_code}
                            autoComplete="one-time-code"
                            disabled={busy}
                            inputMode="numeric"
                            maxLength={6}
                            onChange={(event) =>
                                setCode(
                                    event.currentTarget.value.replace(
                                        /[^0-9]/g,
                                        '',
                                    ),
                                )
                            }
                            pattern="[0-9]{6}"
                            value={code}
                        />
                    </label>
                    <button
                        className="store-cart-phone__button"
                        disabled={busy || code.length !== 6}
                        onClick={verifyCode}
                        type="button"
                    >
                        {busy
                            ? translations.phone_verifying
                            : translations.phone_verify}
                    </button>
                </>
            )}
            {errorCode !== null ? (
                <p className="store-cart-checkout__error" role="alert">
                    {errorCode === 'phone_unavailable'
                        ? translations.phone_unavailable
                        : translations.phone_invalid}
                </p>
            ) : null}
        </div>
    );
}

function CartLine({
    cartItem,
    locale,
    translations,
}: {
    cartItem: StoreCartItem;
    locale: 'ar' | 'en';
    translations: StoreCartTranslations;
}) {
    const configuration = cartItem.configuration;
    const isCoins = cartItem.product.serviceType === 'coins';
    const platform =
        configuration.platform === 'pc'
            ? translations.platform_pc
            : configuration.platform === 'playstation'
              ? translations.platform_playstation
              : '—';
    const delivery =
        configuration.platform === 'pc' && configuration.delivery === null
            ? translations.delivery_pc
            : configuration.platform === 'playstation' &&
                configuration.delivery === 'fast'
              ? translations.delivery_fast
              : configuration.platform === 'playstation' &&
                  configuration.delivery === 'normal'
                ? translations.delivery_normal
                : '—';
    const quantity =
        configuration.coins_quantity === undefined
            ? '—'
            : `${formatCoins(configuration.coins_quantity, locale)} ${translations.coins_unit}`;

    return (
        <li className="store-cart-line">
            <div className="store-cart-line__title">
                {cartItem.product.imageUrl !== null ? (
                    <img
                        alt={isCoins ? '' : cartItem.product.name}
                        aria-hidden={isCoins ? 'true' : undefined}
                        height="42"
                        src={cartItem.product.imageUrl}
                        width="42"
                    />
                ) : null}
                <div>
                    <span>{translations.service}</span>
                    <h2>{cartItem.product.name}</h2>
                </div>
            </div>
            <dl className="store-cart-line__summary">
                <CartFact label={translations.platform} value={platform} />
                <CartFact label={translations.delivery} value={delivery} />
                {isCoins && configuration.coins_quantity !== undefined ? (
                    <CartFact label={translations.quantity} value={quantity} />
                ) : null}
                <CartFact
                    emphasized
                    label={translations.total}
                    value={formatMinorUnits(
                        cartItem.totalHalalah,
                        'SAR',
                        locale,
                    )}
                />
            </dl>
            <CredentialState
                cartItem={cartItem}
                locale={locale}
                translations={translations}
            />
        </li>
    );
}

function CredentialState({
    cartItem,
    locale,
    translations,
}: {
    cartItem: StoreCartItem;
    locale: 'ar' | 'en';
    translations: StoreCartTranslations;
}) {
    const [credentials, setCredentials] =
        useState<StoredCartCredentials | null>(null);
    const [draft, setDraft] = useState<StoredCartCredentials | null>(null);
    const [editing, setEditing] = useState(false);
    const [loadFailed, setLoadFailed] = useState(false);
    const [saving, setSaving] = useState(false);
    const [saveState, setSaveState] = useState<'idle' | 'saved' | 'failed'>(
        'idle',
    );

    useEffect(() => {
        if (cartItem.requiresCredentials || cartItem.credentials === null) {
            return;
        }

        const controller = new AbortController();

        loadCartCredentials(cartItem.credentialsUrl, controller.signal)
            .then((loaded) => {
                setCredentials(loaded);
                setDraft(loaded);
            })
            .catch((error: unknown) => {
                if (!(
                    error instanceof DOMException && error.name === 'AbortError'
                )) {
                    setLoadFailed(true);
                }
            });

        return () => controller.abort();
    }, [
        cartItem.credentials,
        cartItem.credentialsUrl,
        cartItem.requiresCredentials,
    ]);

    if (cartItem.requiresCredentials || cartItem.credentials === null) {
        return (
            <p className="store-cart-credentials store-cart-credentials--missing">
                {translations.credentials_missing}
            </p>
        );
    }

    if (loadFailed) {
        return (
            <p
                className="store-cart-credentials store-cart-credentials--missing"
                role="alert"
            >
                {translations.credentials_load_error}
            </p>
        );
    }

    if (credentials === null || draft === null) {
        return (
            <div className="store-cart-credentials" role="status">
                <p>{translations.credentials_ready}</p>
                <p>
                    {interpolate(translations.backup_codes, {
                        count: formatInteger(
                            cartItem.credentials.backupCodeCount,
                            locale,
                        ),
                    })}
                </p>
            </div>
        );
    }

    const isCoins = cartItem.product.serviceType === 'coins';
    const requiresBalance =
        isCoins &&
        cartItem.configuration.platform === 'playstation' &&
        cartItem.configuration.delivery === 'fast';

    const draftIsValid =
        /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(draft.eaEmail) &&
        draft.eaEmail.length <= 254 &&
        draft.eaPassword.length >= 1 &&
        draft.eaPassword.length <= 128 &&
        draft.backupCodes.every((code) => /^[0-9]{8}$/.test(code)) &&
        new Set(draft.backupCodes).size === 3 &&
        (!isCoins ||
            (draft.companionMarketOpen &&
                draft.policyAccepted &&
                (!requiresBalance || draft.currentBalance !== null)));

    function updateDraft<Key extends keyof StoredCartCredentials>(
        key: Key,
        value: StoredCartCredentials[Key],
    ) {
        setDraft((current) =>
            current === null ? current : { ...current, [key]: value },
        );
        setSaveState('idle');
    }

    function updateCode(index: 0 | 1 | 2, value: string) {
        if (draft === null) {
            return;
        }

        const backupCodes: [string, string, string] = [...draft.backupCodes];
        backupCodes[index] = value.replace(/[^0-9]/g, '').slice(0, 8);
        updateDraft('backupCodes', backupCodes);
    }

    async function save() {
        if (draft === null || saving || !draftIsValid) {
            return;
        }

        setSaving(true);
        setSaveState('idle');

        try {
            await updateCartCredentials(cartItem.credentialsUrl, draft);
            setCredentials(draft);
            setEditing(false);
            setSaveState('saved');
        } catch {
            setSaveState('failed');
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="store-cart-credentials">
            <h3>{translations.credentials}</h3>
            <p>
                {interpolate(translations.backup_codes, {
                    count: formatInteger(
                        cartItem.credentials.backupCodeCount,
                        locale,
                    ),
                })}
            </p>
            {editing ? (
                <div className="store-cart-credentials__form">
                    <label>
                        <span>{translations.ea_email}</span>
                        <input
                            autoComplete="off"
                            dir="ltr"
                            onChange={(event) =>
                                updateDraft(
                                    'eaEmail',
                                    event.currentTarget.value,
                                )
                            }
                            required
                            type="email"
                            value={draft.eaEmail}
                        />
                    </label>
                    <label>
                        <span>{translations.ea_password}</span>
                        <input
                            autoComplete="off"
                            dir="ltr"
                            onChange={(event) =>
                                updateDraft(
                                    'eaPassword',
                                    event.currentTarget.value,
                                )
                            }
                            required
                            type="text"
                            value={draft.eaPassword}
                        />
                    </label>
                    {requiresBalance ? (
                        <label>
                            <span>{translations.current_balance}</span>
                            <input
                                dir="ltr"
                                inputMode="numeric"
                                maxLength={9}
                                onChange={(event) => {
                                    const digits = event.currentTarget.value
                                        .replace(/[^0-9]/g, '')
                                        .slice(0, 9);
                                    updateDraft(
                                        'currentBalance',
                                        digits === '' ? null : Number(digits),
                                    );
                                }}
                                required
                                type="text"
                                value={draft.currentBalance ?? ''}
                            />
                        </label>
                    ) : null}
                    {draft.backupCodes.map((code, index) => (
                        <label key={index}>
                            <span>
                                {interpolate(translations.backup_code, {
                                    number: formatInteger(index + 1, locale),
                                })}
                            </span>
                            <input
                                autoComplete="off"
                                dir="ltr"
                                inputMode="numeric"
                                maxLength={8}
                                onChange={(event) =>
                                    updateCode(
                                        index as 0 | 1 | 2,
                                        event.currentTarget.value,
                                    )
                                }
                                pattern="[0-9]{8}"
                                required
                                value={code}
                            />
                        </label>
                    ))}
                    {isCoins ? (
                        <>
                            <label className="store-cart-credentials__check">
                                <input
                                    checked={draft.companionMarketOpen}
                                    onChange={(event) =>
                                        updateDraft(
                                            'companionMarketOpen',
                                            event.currentTarget.checked,
                                        )
                                    }
                                    type="checkbox"
                                />
                                <span>
                                    {translations.companion_market_open}
                                </span>
                            </label>
                            <label className="store-cart-credentials__check">
                                <input
                                    checked={draft.policyAccepted}
                                    onChange={(event) =>
                                        updateDraft(
                                            'policyAccepted',
                                            event.currentTarget.checked,
                                        )
                                    }
                                    type="checkbox"
                                />
                                <span>{translations.policy_accepted}</span>
                            </label>
                        </>
                    ) : null}
                    <div className="store-cart-credentials__actions">
                        <button
                            disabled={saving || !draftIsValid}
                            onClick={save}
                            type="button"
                        >
                            {translations.save_credentials}
                        </button>
                        <button
                            onClick={() => {
                                setDraft(credentials);
                                setEditing(false);
                                setSaveState('idle');
                            }}
                            type="button"
                        >
                            {translations.cancel_edit}
                        </button>
                    </div>
                </div>
            ) : (
                <>
                    <dl className="store-cart-credentials__values" dir="ltr">
                        <div>
                            <dt>{translations.ea_email}</dt>
                            <dd>{credentials.eaEmail}</dd>
                        </div>
                        <div>
                            <dt>{translations.ea_password}</dt>
                            <dd>{credentials.eaPassword}</dd>
                        </div>
                        {credentials.backupCodes.map((code, index) => (
                            <div key={index}>
                                <dt>
                                    {interpolate(translations.backup_code, {
                                        number: formatInteger(
                                            index + 1,
                                            locale,
                                        ),
                                    })}
                                </dt>
                                <dd>{code}</dd>
                            </div>
                        ))}
                        {isCoins && credentials.currentBalance !== null ? (
                            <div>
                                <dt>{translations.current_balance}</dt>
                                <dd>
                                    {formatInteger(
                                        credentials.currentBalance,
                                        locale,
                                    )}
                                </dd>
                            </div>
                        ) : null}
                        {isCoins && credentials.companionMarketOpen ? (
                            <div>
                                <dt>{translations.companion_market_open}</dt>
                                <dd
                                    aria-label={
                                        translations.companion_market_open
                                    }
                                >
                                    ✓
                                </dd>
                            </div>
                        ) : null}
                        {isCoins && credentials.policyAccepted ? (
                            <div>
                                <dt>{translations.policy_accepted}</dt>
                                <dd aria-label={translations.policy_accepted}>
                                    ✓
                                </dd>
                            </div>
                        ) : null}
                    </dl>
                    <button
                        className="store-cart-credentials__edit"
                        onClick={() => setEditing(true)}
                        type="button"
                    >
                        {translations.edit_credentials}
                    </button>
                </>
            )}
            {saveState === 'saved' ? (
                <p role="status">{translations.credentials_saved}</p>
            ) : null}
            {saveState === 'failed' ? (
                <p role="alert">{translations.credentials_save_error}</p>
            ) : null}
        </div>
    );
}

function CartFact({
    emphasized = false,
    label,
    value,
}: {
    emphasized?: boolean;
    label: string;
    value: string;
}) {
    return (
        <div className={emphasized ? 'store-cart-line__total' : undefined}>
            <dt>{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}
