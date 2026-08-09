import { Head, usePage } from '@inertiajs/react';

import { CoinsConfigurator } from '@/components/configurator/coins';
import StoreLayout from '@/layouts/store-layout';
import type { StoreLayoutTranslations } from '@/layouts/store-layout';
import type {
    CoinsAmountRules,
    CoinsAvailability,
    CoinsPlatformOption,
    CoinsStoreTranslations,
} from '@/types/coins';

type StorePageProps = {
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    locale: 'ar' | 'en';
    status: CoinsAvailability;
    quoteUrl: string;
    amount: CoinsAmountRules;
    platforms: CoinsPlatformOption[];
    store: CoinsStoreTranslations;
    ui: StoreLayoutTranslations & {
        home_title: string;
    };
};

export default function StoreHome() {
    const page = usePage<StorePageProps>();
    const {
        amount,
        direction,
        displayCurrencies,
        displayCurrency,
        locale,
        platforms,
        quoteUrl,
        status,
        store,
        ui,
    } = page.props;
    const hasHomepageContract =
        store !== undefined &&
        status !== undefined &&
        amount !== undefined &&
        platforms !== undefined;

    return (
        <StoreLayout
            currentUrl={page.url}
            direction={direction}
            displayCurrency={displayCurrency}
            displayCurrencies={displayCurrencies}
            locale={locale}
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
                                    locale={locale}
                                    platforms={platforms}
                                    quoteUrl={quoteUrl}
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
