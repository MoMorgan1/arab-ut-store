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
    accountIdentity,
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
    const rawName = accountIdentity?.name?.trim() ?? '';
    const firstName = rawName.split(/\s+/)[0] || '';
    const initial =
        (firstName
            ? firstName.charAt(0)
            : accountUi.page_title.charAt(0)
        ).toUpperCase() || 'U';
    const headingGreeting = accountUi.greeting.replace(
        ':name',
        firstName || rawName || accountUi.page_title,
    );

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
                <header className="account-shell__header">
                    <div className="account-shell__container account-shell__header-inner">
                        <div
                            aria-hidden="true"
                            className="account-shell__avatar"
                        >
                            <span>{initial}</span>
                        </div>
                        <div className="account-shell__header-text">
                            <h1>{headingGreeting}</h1>
                            <p>
                                {accountUi.overview?.subtitle ??
                                    accountUi.introduction}
                            </p>
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
                    bottomNav={accountUi.bottom_nav}
                    current={current}
                    items={accountNavigation}
                    translations={accountUi.navigation}
                />
            </article>
        </StoreLayout>
    );
}
