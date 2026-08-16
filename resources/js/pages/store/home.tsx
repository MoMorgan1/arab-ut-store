import { usePage } from '@inertiajs/react';

import { CoinsConfigurator } from '@/components/configurator/coins';
import { FaqSection } from '@/components/store/faq-section';
import { HeroStats } from '@/components/store/hero-stats';
import { ReviewsSection } from '@/components/store/reviews-section';
import { ServiceRail } from '@/components/store/service-rail';
import { StoreSeoHead } from '@/components/store/store-seo-head';
import StoreLayout from '@/layouts/store-layout';
import { parseCoinsQuoteSchedules } from '@/lib/coins-quote-schedule';
import type {
    CoinsAmountRules,
    CoinsAvailability,
    CoinsCartConfig,
    CoinsPlatformOption,
    CoinsStoreTranslations,
} from '@/types/coins';
import type { StoreHomeContent } from '@/types/store-content';
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
    homeContent?: StoreHomeContent;
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
        homeContent,
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
            <StoreSeoHead
                title={store?.seo_title ?? ui.home_title}
                description={ui.footer?.description}
                locale={locale}
            />
            {hasHomepageContract ? (
                <>
                    <section
                        aria-labelledby="store-hero-title"
                        className="store-hero"
                    >
                        <div aria-hidden="true" className="store-hero__glow" />
                        <img
                            alt=""
                            aria-hidden="true"
                            className="store-hero__coin store-hero__coin--one"
                            draggable={false}
                            height="160"
                            src="/images/store/coins/ut-coin-160.webp"
                            width="160"
                        />
                        <img
                            alt=""
                            aria-hidden="true"
                            className="store-hero__coin store-hero__coin--two"
                            draggable={false}
                            height="240"
                            src="/images/store/coins/ut-coin-240.webp"
                            width="240"
                        />
                        <img
                            alt=""
                            aria-hidden="true"
                            className="store-hero__coin store-hero__coin--three"
                            draggable={false}
                            height="160"
                            src="/images/store/coins/ut-coin-160.webp"
                            width="160"
                        />
                        <img
                            alt=""
                            aria-hidden="true"
                            className="store-hero__coin store-hero__coin--four"
                            draggable={false}
                            height="160"
                            src="/images/store/coins/ut-coin-160.webp"
                            width="160"
                        />
                        <img
                            alt=""
                            aria-hidden="true"
                            className="store-hero__coin store-hero__coin--five"
                            draggable={false}
                            height="160"
                            src="/images/store/coins/ut-coin-160.webp"
                            width="160"
                        />
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
                                <span className="store-hero__title-primary">
                                    {store.hero.title}
                                </span>{' '}
                                <strong>{store.hero.accent}</strong>
                            </h1>
                            <p className="store-hero__subtitle">
                                {store.hero.subtitle}
                            </p>
                            <div className="store-hero__actions">
                                <a
                                    className="store-hero__cta store-hero__cta--primary"
                                    href="#coins"
                                >
                                    {store.hero.cta}
                                </a>
                                <a
                                    className="store-hero__cta store-hero__cta--secondary"
                                    href="#services"
                                >
                                    {store.hero.services_cta}
                                </a>
                            </div>
                            <HeroStats
                                label={store.hero.proof_label}
                                stats={store.hero.stats}
                            />
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
                                    termsUrl={storeShell.termsUrl}
                                    translations={store}
                                    warrantyUrl={storeShell.warrantyUrl}
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

                    {homeContent === undefined ? null : (
                        <>
                            <ServiceRail
                                direction={direction}
                                services={homeContent.services}
                                translations={homeContent.servicesTranslations}
                            />
                            <ReviewsSection
                                locale={locale}
                                reviews={homeContent.reviews}
                                reviewsUrl={homeContent.reviewsUrl}
                                translations={homeContent.reviewsTranslations}
                            />
                            <FaqSection
                                entries={homeContent.faq}
                                translations={homeContent.faqTranslations}
                            />
                        </>
                    )}
                </>
            ) : null}
        </StoreLayout>
    );
}
