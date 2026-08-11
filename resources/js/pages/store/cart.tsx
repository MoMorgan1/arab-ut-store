import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { interpolate } from '@/components/configurator/coins/configurator-copy';
import StoreLayout from '@/layouts/store-layout';
import {
    loadCartCredentials,
    updateCartCredentials,
} from '@/lib/cart-credentials-api';
import type { StoredCartCredentials } from '@/lib/cart-credentials-api';
import { formatCoins, formatInteger, formatMinorUnits } from '@/lib/money';
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

                <a className="store-cart-back" href={cartPage.backUrl}>
                    {cartPage.translations.back}
                </a>
            </section>
        </StoreLayout>
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

    const draftIsValid =
        /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(draft.eaEmail) &&
        draft.eaEmail.length <= 254 &&
        draft.eaPassword.length >= 1 &&
        draft.eaPassword.length <= 128 &&
        draft.backupCodes.every((code) => /^[0-9]{8}$/.test(code)) &&
        new Set(draft.backupCodes).size === 3;

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
