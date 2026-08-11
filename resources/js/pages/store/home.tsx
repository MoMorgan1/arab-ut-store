import { Head, usePage } from '@inertiajs/react';

import { CoinsConfigurator } from '@/components/configurator/coins';
import StoreLayout from '@/layouts/store-layout';
import { parseCoinsQuoteSchedules } from '@/lib/coins-quote-schedule';
import type {
    CoinsAmountRules,
    CoinsAvailability,
    CoinsCartConfig,
    CoinsPlatformOption,
    CoinsStoreTranslations,
} from '@/types/coins';
import type {
    StoreShellConfig,
    StoreShellTranslations,
} from '@/types/store-shell';

type StorePageProps = {
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    locale: 'ar' | 'en';
    status: CoinsAvailability;
    quoteSchedules?: unknown;
    quoteUrl: string;
    amount: CoinsAmountRules;
    cartCount: number;
    coinsCart: CoinsCartConfig;
    platforms: CoinsPlatformOption[];
    store: CoinsStoreTranslations;
    storeShell: StoreShellConfig;
    ui: StoreShellTranslations;
};

export default function StoreHome() {
    const page = usePage<StorePageProps>();
    const {
        amount,
        cartCount,
        coinsCart,
        direction,
        displayCurrencies,
        displayCurrency,
        locale,
        platforms,
        quoteSchedules,
        status,
        store,
        storeShell,
        ui,
    } = page.props;
    const hasHomepageContract =
        store !== undefined &&
        status !== undefined &&
        amount !== undefined &&
        platforms !== undefined;
    const schedules = parseCoinsQuoteSchedules(
        quoteSchedules,
        displayCurrency,
        amount,
        platforms,
    );

    return (
        <StoreLayout
            currentUrl={page.url}
            direction={direction}
            displayCurrency={displayCurrency}
            displayCurrencies={displayCurrencies}
            locale={locale}
            cartCount={cartCount}
            storeShell={storeShell}
            ui={ui}
        >
            <Head title={store?.seo_title ?? ui.home_title} />
            {hasHomepageContract ? (
                <>
                    <section
                        aria-labelledby="store-hero-title"
                        className="store-hero"
                    >
                        <div aria-hidden="true" className="store-hero__glow" />
                        <div className="store-hero__content">
                            <img
                                alt=""
                                aria-hidden="true"
                                className="store-hero__logo"
                                height="140"
                                src="/images/store/hero/arabut-logo-hero.webp"
                                width="140"
                            />
                            <p className="store-hero__badge">
                                {store.hero.badge}
                            </p>
                            <h1 id="store-hero-title">
                                <span>{store.hero.title}</span>{' '}
                                <strong>{store.hero.accent}</strong>
                            </h1>
                            <p className="store-hero__subtitle">
                                {store.hero.subtitle}
                            </p>
                            <a className="store-hero__cta" href="#coins">
                                {store.hero.cta}
                            </a>
                            <dl
                                aria-label={store.hero.proof_label}
                                className="store-hero__stats"
                                role="group"
                            >
                                {store.hero.stats.map((stat) => (
                                    <div
                                        className="store-hero__stat"
                                        key={`${stat.value}-${stat.label}`}
                                    >
                                        <dd>{stat.value}</dd>
                                        <dt>{stat.label}</dt>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    </section>

                    <section
                        aria-labelledby="coins-section-title"
                        className="store-coins-section"
                        id="coins"
                    >
                        <div className="store-coins-section__inner">
                            <header className="store-section-heading">
                                <p>{store.coins_section.tag}</p>
                                <h2 id="coins-section-title">
                                    {store.coins_section.title}
                                </h2>
                                <span>{store.coins_section.intro}</span>
                            </header>

                            {status === 'available' ? (
                                <CoinsConfigurator
                                    amount={amount}
                                    cart={coinsCart}
                                    locale={locale}
                                    platforms={platforms}
                                    quoteSchedules={schedules}
                                    translations={store}
                                />
                            ) : (
                                <section
                                    aria-labelledby="coins-unavailable-title"
                                    className="coins-unavailable"
                                >
                                    <span
                                        aria-hidden="true"
                                        className="coins-unavailable__mark"
                                    />
                                    <div>
                                        <h2 id="coins-unavailable-title">
                                            {store.availability.title}
                                        </h2>
                                        <p>{store.availability.body}</p>
                                    </div>
                                </section>
                            )}
                        </div>
                    </section>
                </>
            ) : null}
        </StoreLayout>
    );
}
