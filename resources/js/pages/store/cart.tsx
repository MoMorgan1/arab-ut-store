import { Head, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    CheckCircle2,
    CreditCard,
    ShoppingBag,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { interpolate } from '@/components/configurator/coins/configurator-copy';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StoreLayout from '@/layouts/store-layout';
import {
    loadCartCredentials,
    updateCartCredentials,
} from '@/lib/cart-credentials-api';
import type { StoredCartCredentials } from '@/lib/cart-credentials-api';
import { removeCartItem } from '@/lib/cart-items-api';
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
import { phoneCountryCodes } from '@/lib/phone-country-codes';
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
    const authenticated = page.props.auth.user !== null;
    const [items, setItems] = useState(cart.items);
    const totalHalalah = items.reduce(
        (total, cartItem) => total + cartItem.totalHalalah,
        0,
    );

    function itemRemoved(itemId: string, count: number) {
        setItems((current) => current.filter((item) => item.id !== itemId));
        window.dispatchEvent(
            new CustomEvent<number>('arabut:cart-count', { detail: count }),
        );
    }

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

                {items.length === 0 ? (
                    <CartEmptyState
                        coinsUrl={storeShell.coinsUrl}
                        translations={cartPage.translations}
                    />
                ) : (
                    <>
                        <CheckoutProgress
                            authenticated={authenticated}
                            phoneVerified={cartPage.checkout.phoneVerified}
                            translations={cartPage.translations}
                        />
                        <div className="store-cart-page__layout">
                            <section
                                aria-labelledby="store-cart-items-title"
                                className="store-cart-page__items"
                            >
                                <h2 id="store-cart-items-title">
                                    {cartPage.translations.items_heading}
                                </h2>
                                <ol className="store-cart-lines">
                                    {items.map((cartItem) => (
                                        <CartLine
                                            cartItem={cartItem}
                                            key={cartItem.id}
                                            locale={locale}
                                            onRemoved={itemRemoved}
                                            translations={cartPage.translations}
                                        />
                                    ))}
                                </ol>
                            </section>
                            <CheckoutPanel
                                authenticated={authenticated}
                                checkout={cartPage.checkout}
                                locale={locale}
                                policyLinks={{
                                    terms: {
                                        label: ui.footer.terms,
                                        url: storeShell.termsUrl,
                                    },
                                    warranty: {
                                        label: ui.footer.warranty,
                                        url: storeShell.warrantyUrl,
                                    },
                                }}
                                totalHalalah={totalHalalah}
                                translations={cartPage.translations}
                            />
                        </div>
                    </>
                )}
            </section>
        </StoreLayout>
    );
}

function CartEmptyState({
    coinsUrl,
    translations,
}: {
    coinsUrl: string;
    translations: StoreCartTranslations;
}) {
    return (
        <section className="store-cart-empty">
            <span aria-hidden="true">
                <ShoppingBag />
            </span>
            <h2>{translations.empty_title}</h2>
            <p>{translations.empty_description}</p>
            <div>
                <a href={coinsUrl}>{translations.browse_coins}</a>
            </div>
        </section>
    );
}

function CheckoutProgress({
    authenticated,
    phoneVerified,
    translations,
}: {
    authenticated: boolean;
    phoneVerified: boolean;
    translations: StoreCartTranslations;
}) {
    const currentStep = authenticated && phoneVerified ? 1 : 0;
    const steps = [
        { icon: ShoppingBag, label: translations.step_cart },
        { icon: CreditCard, label: translations.step_payment },
    ];

    return (
        <nav
            aria-label={translations.checkout_progress}
            className="store-cart-progress"
        >
            <ol>
                {steps.map((step, index) => {
                    const Icon = step.icon;
                    const state =
                        index < currentStep
                            ? 'complete'
                            : index === currentStep
                              ? 'current'
                              : 'upcoming';

                    return (
                        <li
                            aria-current={
                                state === 'current' ? 'step' : undefined
                            }
                            data-state={state}
                            key={step.label}
                        >
                            <span aria-hidden="true">
                                <Icon />
                            </span>
                            <strong>{step.label}</strong>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

function CheckoutPanel({
    authenticated,
    checkout,
    locale,
    policyLinks,
    totalHalalah,
    translations,
}: {
    authenticated: boolean;
    checkout: StoreCartPageProps['cartPage']['checkout'];
    locale: 'ar' | 'en';
    policyLinks: {
        terms: { label: string; url: string };
        warranty: { label: string; url: string };
    };
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
            className={[
                'store-cart-checkout',
                authenticated && checkout.phoneVerified
                    ? 'store-cart-checkout--ready'
                    : null,
            ]
                .filter(Boolean)
                .join(' ')}
            aria-label={translations.checkout}
        >
            <header className="store-cart-checkout__heading">
                <span aria-hidden="true">
                    <ShieldCheck />
                </span>
                <h2>{translations.summary_title}</h2>
            </header>
            <div className="store-cart-checkout__total">
                <span>{translations.order_total}</span>
                <strong>{formatMinorUnits(totalHalalah, 'SAR', locale)}</strong>
            </div>
            <p className="store-cart-checkout__policies">
                <a href={policyLinks.terms.url}>{policyLinks.terms.label}</a>
                <span aria-hidden="true">·</span>
                <a href={policyLinks.warranty.url}>
                    {policyLinks.warranty.label}
                </a>
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
            <Label htmlFor="checkout-phone-number">
                {translations.phone_number}
            </Label>
            <div
                className="auth-phone-field store-cart-phone__number"
                dir="ltr"
            >
                <label className="sr-only" htmlFor="checkout-country-code">
                    {translations.phone_country}
                </label>
                <select
                    aria-label={translations.phone_country}
                    className="auth-phone-field__country"
                    disabled={busy || stage === 'code'}
                    id="checkout-country-code"
                    onChange={(event) =>
                        setCountryCode(event.currentTarget.value)
                    }
                    value={countryCode}
                >
                    {phoneCountryCodes.map((countryCode) => (
                        <option key={countryCode} value={countryCode}>
                            {countryCode}
                        </option>
                    ))}
                </select>
                <Input
                    aria-label={translations.phone_number}
                    autoComplete="tel-national"
                    className="h-11"
                    disabled={busy || stage === 'code'}
                    id="checkout-phone-number"
                    inputMode="numeric"
                    maxLength={14}
                    onChange={(event) =>
                        setLocalNumber(
                            event.currentTarget.value.replace(/[^0-9]/g, ''),
                        )
                    }
                    type="tel"
                    value={localNumber}
                />
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
                        : errorCode === 'whatsapp_unavailable'
                          ? translations.phone_delivery_error
                          : translations.phone_invalid}
                </p>
            ) : null}
        </div>
    );
}

function CartLine({
    cartItem,
    locale,
    onRemoved,
    translations,
}: {
    cartItem: StoreCartItem;
    locale: 'ar' | 'en';
    onRemoved: (itemId: string, count: number) => void;
    translations: StoreCartTranslations;
}) {
    const [removalState, setRemovalState] = useState<
        'idle' | 'confirming' | 'removing' | 'failed'
    >('idle');
    const configuration = cartItem.configuration;
    const isCoins = cartItem.product.serviceType === 'coins';
    const isFutChampions = cartItem.product.serviceType === 'fut_champions';
    const isRivals = cartItem.product.serviceType === 'rivals';
    const isManualService = isFutChampions || isRivals;
    const platform =
        configuration.platform === 'pc'
            ? translations.platform_pc
            : configuration.platform === 'playstation'
              ? isManualService
                  ? translations.platform_playstation_manual
                  : translations.platform_playstation
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

    async function remove() {
        if (removalState === 'removing') {
            return;
        }

        setRemovalState('removing');

        try {
            const result = await removeCartItem(cartItem.deleteUrl);
            onRemoved(cartItem.id, result.cartCount);
        } catch {
            setRemovalState('failed');
        }
    }

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
                {removalState === 'confirming' ? (
                    <div className="store-cart-line__remove-confirmation">
                        <button onClick={remove} type="button">
                            {translations.remove_confirm}
                        </button>
                        <button
                            onClick={() => setRemovalState('idle')}
                            type="button"
                        >
                            {translations.remove_cancel}
                        </button>
                    </div>
                ) : (
                    <button
                        aria-label={translations.remove_item}
                        className="store-cart-line__remove"
                        disabled={removalState === 'removing'}
                        onClick={() => setRemovalState('confirming')}
                        type="button"
                    >
                        <Trash2 aria-hidden="true" />
                        <span>{translations.remove_item}</span>
                    </button>
                )}
            </div>
            {removalState === 'failed' ? (
                <p className="store-cart-line__remove-error" role="alert">
                    {translations.remove_error}
                </p>
            ) : null}
            <dl className="store-cart-line__summary">
                <CartFact label={translations.platform} value={platform} />
                {isCoins ? (
                    <CartFact label={translations.delivery} value={delivery} />
                ) : null}
                {isCoins && configuration.coins_quantity !== undefined ? (
                    <CartFact label={translations.quantity} value={quantity} />
                ) : null}
                {!isCoins &&
                cartItem.product.serviceType === 'sbc' &&
                configuration.completion_count !== undefined ? (
                    <CartFact
                        label={translations.completions}
                        value={formatInteger(
                            configuration.completion_count,
                            locale,
                        )}
                    />
                ) : null}
                {isManualService && configuration.pc_launcher !== undefined ? (
                    <CartFact
                        label={translations.launcher}
                        value={
                            configuration.pc_launcher === 'steam'
                                ? translations.launcher_steam
                                : translations.launcher_ea_app
                        }
                    />
                ) : null}
                {isFutChampions && configuration.target_rank !== undefined ? (
                    <CartFact
                        label={translations.rank}
                        value={interpolate(translations.rank_value, {
                            rank: formatInteger(
                                configuration.target_rank,
                                locale,
                            ),
                        })}
                    />
                ) : null}
                {isFutChampions && configuration.urgent !== undefined ? (
                    <CartFact
                        label={translations.urgent}
                        value={
                            configuration.urgent
                                ? translations.urgent_yes
                                : translations.urgent_no
                        }
                    />
                ) : null}
                {isFutChampions &&
                configuration.matches_played !== undefined ? (
                    <CartFact
                        label={translations.matches_played}
                        value={formatInteger(
                            configuration.matches_played,
                            locale,
                        )}
                    />
                ) : null}
                {isRivals && configuration.from_division !== undefined ? (
                    <CartFact
                        label={translations.from_division}
                        value={divisionLabel(
                            configuration.from_division,
                            locale,
                            translations,
                        )}
                    />
                ) : null}
                {isRivals && configuration.to_division !== undefined ? (
                    <CartFact
                        label={translations.to_division}
                        value={divisionLabel(
                            configuration.to_division,
                            locale,
                            translations,
                        )}
                    />
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
            {isManualService ? (
                <ManualFulfillmentState
                    fulfillment={cartItem.fulfillment ?? null}
                    translations={translations}
                />
            ) : (
                <CredentialState
                    cartItem={cartItem}
                    locale={locale}
                    translations={translations}
                />
            )}
        </li>
    );
}

function divisionLabel(
    division: NonNullable<StoreCartItem['configuration']['from_division']>,
    locale: 'ar' | 'en',
    translations: StoreCartTranslations,
): string {
    return division === 'elite'
        ? translations.division_elite
        : formatInteger(Number(division), locale);
}

function ManualFulfillmentState({
    fulfillment,
    translations,
}: {
    fulfillment: StoreCartItem['fulfillment'];
    translations: StoreCartTranslations;
}) {
    if (
        fulfillment === null ||
        fulfillment === undefined ||
        !fulfillment.credentialsReady ||
        !fulfillment.squadImagePresent
    ) {
        return (
            <p className="store-cart-credentials store-cart-credentials--missing">
                {translations.fulfillment_missing}
            </p>
        );
    }

    return (
        <div className="store-cart-fulfillment" role="status">
            <span>
                <CheckCircle2 aria-hidden="true" />
                {translations.account_details_ready}
            </span>
            <span>
                <CheckCircle2 aria-hidden="true" />
                {translations.squad_image_ready}
            </span>
        </div>
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
    const [expanded, setExpanded] = useState(false);
    const [editing, setEditing] = useState(false);
    const [loadFailed, setLoadFailed] = useState(false);
    const [saving, setSaving] = useState(false);
    const [saveState, setSaveState] = useState<'idle' | 'saved' | 'failed'>(
        'idle',
    );

    useEffect(() => {
        if (
            !expanded ||
            cartItem.requiresCredentials ||
            cartItem.credentials === null ||
            cartItem.credentialsUrl === null
        ) {
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
        expanded,
    ]);

    if (cartItem.requiresCredentials || cartItem.credentials === null) {
        return (
            <p className="store-cart-credentials store-cart-credentials--missing">
                {translations.credentials_missing}
            </p>
        );
    }

    const contentId = `cart-credentials-${cartItem.id}`;
    const disclosure = (
        <button
            aria-controls={contentId}
            aria-expanded={expanded}
            aria-label={
                expanded
                    ? translations.credentials_hide
                    : translations.credentials_show
            }
            className="store-cart-credentials__disclosure"
            onClick={() => {
                setExpanded((current) => !current);

                if (expanded) {
                    setEditing(false);
                    setDraft(credentials);
                    setSaveState('idle');
                }
            }}
            type="button"
        >
            <span>
                <strong>{translations.credentials}</strong>
                <small>
                    {interpolate(translations.backup_codes, {
                        count: formatInteger(
                            cartItem.credentials.backupCodeCount,
                            locale,
                        ),
                    })}
                </small>
            </span>
            <ChevronDown aria-hidden="true" />
        </button>
    );

    if (!expanded) {
        return <div className="store-cart-credentials">{disclosure}</div>;
    }

    if (loadFailed) {
        return (
            <div className="store-cart-credentials">
                {disclosure}
                <p
                    className="store-cart-credentials--missing"
                    id={contentId}
                    role="alert"
                >
                    {translations.credentials_load_error}
                </p>
            </div>
        );
    }

    if (credentials === null || draft === null) {
        return (
            <div className="store-cart-credentials">
                {disclosure}
                <p id={contentId} role="status">
                    {translations.credentials_ready}
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
        if (
            draft === null ||
            saving ||
            !draftIsValid ||
            cartItem.credentialsUrl === null
        ) {
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
            {disclosure}
            <div className="store-cart-credentials__content" id={contentId}>
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
                                            digits === ''
                                                ? null
                                                : Number(digits),
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
                                        number: formatInteger(
                                            index + 1,
                                            locale,
                                        ),
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
                        <dl
                            className="store-cart-credentials__values"
                            dir="ltr"
                        >
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
