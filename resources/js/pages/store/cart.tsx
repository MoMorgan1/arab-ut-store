import { Head, usePage } from '@inertiajs/react';

import { interpolate } from '@/components/configurator/coins/configurator-copy';
import StoreLayout from '@/layouts/store-layout';
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
    const isCoins = configuration.service_type === 'coins';
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
                {isCoins ? (
                    <img
                        alt=""
                        aria-hidden="true"
                        height="42"
                        src="/images/store/coins/ut-coin-80.webp"
                        width="42"
                    />
                ) : null}
                <div>
                    <span>{translations.service}</span>
                    <h2>{isCoins ? translations.coins_service : '—'}</h2>
                </div>
            </div>
            <dl className="store-cart-line__summary">
                <CartFact label={translations.platform} value={platform} />
                <CartFact label={translations.delivery} value={delivery} />
                {isCoins ? (
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
    if (cartItem.requiresCredentials || cartItem.credentials === null) {
        return (
            <p className="store-cart-credentials store-cart-credentials--missing">
                {translations.credentials_missing}
            </p>
        );
    }

    const expiry = new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(cartItem.credentials.retainedUntil));

    return (
        <div className="store-cart-credentials">
            <h3>{translations.credentials}</h3>
            <p>{interpolate(translations.credentials_ready, { expiry })}</p>
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
