import type { PropsWithChildren } from 'react';

import AccountMobileBottomNav from '@/components/account/account-mobile-bottom-nav';
import AccountNavigation from '@/components/account/account-navigation';
import StoreLayout from '@/layouts/store-layout';
import type {
    AccountDestination,
    AccountPageShellProps,
} from '@/types/account';

type MyAccountLayoutProps = PropsWithChildren<
    AccountPageShellProps & {
        current: AccountDestination;
        currentUrl: string;
    }
>;

export default function MyAccountLayout({
    accountNavigation,
    accountUi,
    cartCount,
    children,
    current,
    currentUrl,
    direction,
    displayCurrencies,
    displayCurrency,
    locale,
    logoutUrl,
    storeShell,
    ui,
}: MyAccountLayoutProps) {
    return (
        <StoreLayout
            cartCount={cartCount}
            currentUrl={currentUrl}
            direction={direction}
            displayCurrency={displayCurrency}
            displayCurrencies={displayCurrencies}
            locale={locale}
            storeShell={storeShell}
            ui={ui}
        >
            <article className="account-shell">
                <header className="account-shell__hero">
                    <div aria-hidden="true" className="account-shell__glow" />
                    <div className="account-shell__container account-shell__hero-inner">
                        <img
                            alt=""
                            aria-hidden="true"
                            height="72"
                            src="/images/arabut-logo-header.webp"
                            width="72"
                        />
                        <div>
                            <p>{accountUi.eyebrow}</p>
                            <h1>{accountUi.page_title}</h1>
                            <span>{accountUi.introduction}</span>
                        </div>
                    </div>
                </header>
                <div className="account-shell__container account-shell__grid">
                    <aside className="account-shell__sidebar">
                        <AccountNavigation
                            current={current}
                            items={accountNavigation}
                            logoutUrl={logoutUrl}
                            translations={accountUi.navigation}
                        />
                    </aside>
                    <div className="account-shell__content">{children}</div>
                </div>
                <AccountMobileBottomNav
                    current={current}
                    items={accountNavigation}
                    translations={accountUi.navigation}
                />
            </article>
        </StoreLayout>
    );
}
