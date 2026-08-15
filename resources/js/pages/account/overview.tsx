import { Head, usePage } from '@inertiajs/react';

import StoreLayout from '@/layouts/store-layout';
import type { AccountOverviewPageProps } from '@/types/account';

export default function AccountOverview() {
    const inertia = usePage<AccountOverviewPageProps>();
    const {
        accountUi,
        cartCount,
        direction,
        displayCurrencies,
        displayCurrency,
        locale,
        storeShell,
        ui,
    } = inertia.props;

    return (
        <StoreLayout
            cartCount={cartCount}
            currentUrl={inertia.url}
            direction={direction}
            displayCurrency={displayCurrency}
            displayCurrencies={displayCurrencies}
            locale={locale}
            storeShell={storeShell}
            ui={ui}
        >
            <Head title={accountUi.page_title} />
            <article className="store-info-page">
                <section
                    aria-labelledby="account-page-title"
                    className="store-info-page__hero"
                >
                    <div aria-hidden="true" className="store-info-page__glow" />
                    <div className="store-info-page__container store-info-page__hero-inner">
                        <p>{accountUi.eyebrow}</p>
                        <h1 id="account-page-title">{accountUi.page_title}</h1>
                        <p>{accountUi.introduction}</p>
                    </div>
                </section>
                <section
                    aria-labelledby="account-overview-title"
                    className="store-info-page__content"
                >
                    <div className="store-info-page__container store-info-page__prose">
                        <h2 id="account-overview-title">
                            {accountUi.overview.title}
                        </h2>
                        <p>{accountUi.overview.description}</p>
                    </div>
                </section>
            </article>
        </StoreLayout>
    );
}
