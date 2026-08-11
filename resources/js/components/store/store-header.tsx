import { useEffect, useState } from 'react';
import { StorePreferences } from '@/components/store/store-preferences';

import type {
    StoreLocale,
    StoreShellConfig,
    StoreShellTranslations,
} from '@/types/store-shell';

export type StoreHeaderProps = {
    currentUrl: string;
    cartCount: number;
    locale: StoreLocale;
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    shell: StoreShellConfig;
    translations: StoreShellTranslations;
};

type NavigationKey = 'home' | 'coins' | 'sbc' | 'fut_champions';

function activeState(
    key: NavigationKey,
    currentUrl: string,
): 'page' | 'location' | undefined {
    const url = new URL(currentUrl, 'https://arab-ut.local');
    const pathname = url.pathname.replace(/\/+$/, '') || '/';
    const isStoreRoot = pathname === '/' || pathname === '/en';

    if (key === 'home') {
        return isStoreRoot && url.hash !== '#coins' ? 'page' : undefined;
    }

    if (key === 'coins') {
        return isStoreRoot && url.hash === '#coins' ? 'location' : undefined;
    }

    if (key === 'sbc') {
        return /\/(?:product-category\/)?sbc$/.test(pathname)
            ? 'page'
            : undefined;
    }

    return /\/fut-champions$/.test(pathname) ? 'page' : undefined;
}

function pathOf(url: string): string {
    const pathname = new URL(url, 'https://arab-ut.local').pathname;

    return pathname.replace(/\/+$/, '') || '/';
}

function isAccountCurrent(currentUrl: string, accountUrl: string): boolean {
    const currentPath = pathOf(currentUrl);
    const accountPath = pathOf(accountUrl);

    if (!accountPath.endsWith('/login')) {
        return currentPath === accountPath;
    }

    const localeBase = accountPath.slice(0, -'/login'.length);

    return (
        [
            `${localeBase}/login`,
            `${localeBase}/register`,
            `${localeBase}/forgot-password`,
        ].includes(currentPath) ||
        currentPath.startsWith(`${localeBase}/reset-password/`)
    );
}

function HomeIcon() {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="18"
            viewBox="0 0 24 24"
            width="18"
        >
            <path
                d="m3 11 9-8 9 8v9a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"
                stroke="currentColor"
                strokeLinejoin="round"
                strokeWidth="1.8"
            />
        </svg>
    );
}

function NavigationIcon({ item }: { item: NavigationKey }) {
    if (item === 'home') {
        return <HomeIcon />;
    }

    const source = {
        coins: '/images/store/coins/ut-coin-80.webp',
        sbc: '/images/store/navigation/logo-sbc-96.webp',
        fut_champions: '/images/store/navigation/logo-champions-80.webp',
    }[item];

    return (
        <img alt="" aria-hidden="true" height="20" src={source} width="20" />
    );
}

function WhatsAppIcon() {
    return (
        <svg
            aria-hidden="true"
            fill="currentColor"
            height="18"
            viewBox="0 0 24 24"
            width="18"
        >
            <path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.46-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.08-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.21 3.07.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.69.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35M12.05 21.79a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.89 9.88M20.46 3.49A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.31-1.65a11.88 11.88 0 0 0 5.68 1.45c6.55 0 11.89-5.34 11.89-11.9 0-3.18-1.23-6.16-3.48-8.41" />
        </svg>
    );
}

function CartIcon() {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="19"
            viewBox="0 0 24 24"
            width="19"
        >
            <path
                d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0"
                stroke="currentColor"
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="1.8"
            />
        </svg>
    );
}

function AccountIcon() {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="19"
            viewBox="0 0 24 24"
            width="19"
        >
            <path
                d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"
                stroke="currentColor"
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="1.8"
            />
        </svg>
    );
}

export function StoreHeader(props: StoreHeaderProps) {
    const {
        currentUrl,
        cartCount,
        direction,
        displayCurrencies,
        displayCurrency,
        locale,
        shell,
        translations,
    } = props;
    const [visibleCartCount, setVisibleCartCount] = useState(cartCount);
    const [browserNavigation, setBrowserNavigation] = useState({
        sourceUrl: currentUrl,
        value: currentUrl,
    });
    const liveCurrentUrl =
        browserNavigation.sourceUrl === currentUrl
            ? browserNavigation.value
            : currentUrl;

    useEffect(() => {
        function syncFromBrowserUrl() {
            setBrowserNavigation({
                sourceUrl: currentUrl,
                value: `${window.location.pathname}${window.location.search}${window.location.hash}`,
            });
        }

        window.addEventListener('hashchange', syncFromBrowserUrl);
        window.addEventListener('popstate', syncFromBrowserUrl);

        return () => {
            window.removeEventListener('hashchange', syncFromBrowserUrl);
            window.removeEventListener('popstate', syncFromBrowserUrl);
        };
    }, [currentUrl]);

    useEffect(() => {
        function updateCartCount(event: Event) {
            const nextCount = (event as CustomEvent<number>).detail;

            if (Number.isSafeInteger(nextCount) && nextCount >= 0) {
                setVisibleCartCount(nextCount);
            }
        }

        window.addEventListener('arabut:cart-count', updateCartCount);

        return () =>
            window.removeEventListener('arabut:cart-count', updateCartCount);
    }, []);
    const wordmarkMatch = translations.brand.match(/^(.*)\s+(\S+)$/u);
    const wordmarkName = wordmarkMatch?.[1] ?? translations.brand;
    const wordmarkAccent = wordmarkMatch?.[2];
    const navigation = [
        {
            key: 'home',
            href: shell.homeUrl,
            label: translations.header.home,
            badge: null,
        },
        {
            key: 'coins',
            href: shell.coinsUrl,
            label: translations.header.coins,
            badge: null,
        },
        {
            key: 'sbc',
            href: shell.sbcUrl,
            label: translations.header.sbc,
            badge: translations.header.most_requested,
        },
        {
            key: 'fut_champions',
            href: shell.futChampionsUrl,
            label: translations.header.fut_champions,
            badge: null,
        },
    ] as const;

    return (
        <header className="store-header" dir={direction}>
            <div className="store-header__top">
                <div className="store-header__top-inner">
                    <a
                        aria-label={translations.brand}
                        className="store-wordmark"
                        href={shell.homeUrl}
                    >
                        <img
                            alt=""
                            aria-hidden="true"
                            height="48"
                            src="/images/arabut-logo-header.webp"
                            width="48"
                        />
                        <span
                            aria-hidden="true"
                            className="store-wordmark__text"
                            dir={direction}
                        >
                            <span className="store-wordmark__name">
                                {wordmarkName}
                            </span>
                            {wordmarkAccent === undefined ? null : (
                                <>
                                    {' '}
                                    <span className="store-wordmark__accent">
                                        {wordmarkAccent}
                                    </span>
                                </>
                            )}
                        </span>
                    </a>
                    <div className="store-header__actions">
                        <StorePreferences
                            currentUrl={liveCurrentUrl}
                            displayCurrencies={displayCurrencies}
                            displayCurrency={displayCurrency}
                            locale={locale}
                            translations={translations}
                        />
                        <a
                            aria-label={translations.header.cart}
                            className="store-header__icon-link"
                            href={shell.cartUrl}
                        >
                            <CartIcon />
                            <span aria-hidden="true">{visibleCartCount}</span>
                        </a>
                        <a
                            aria-current={
                                isAccountCurrent(
                                    liveCurrentUrl,
                                    shell.accountUrl,
                                )
                                    ? 'page'
                                    : undefined
                            }
                            aria-label={translations.header.account}
                            className="store-header__icon-link"
                            href={shell.accountUrl}
                        >
                            <AccountIcon />
                        </a>
                    </div>
                </div>
            </div>
            <nav
                aria-label={translations.header.primary_navigation}
                className="store-primary-nav"
            >
                <ul>
                    {navigation.map((item) => (
                        <li key={item.key}>
                            <a
                                aria-current={activeState(
                                    item.key,
                                    liveCurrentUrl,
                                )}
                                href={item.href}
                            >
                                <NavigationIcon item={item.key} />
                                <span>{item.label}</span>
                                {item.badge === null ? null : (
                                    <small>{item.badge}</small>
                                )}
                            </a>
                        </li>
                    ))}
                    <li className="store-primary-nav__whatsapp">
                        <a
                            className="store-primary-nav__whatsapp-target"
                            href={shell.whatsappUrl}
                            rel="noopener noreferrer"
                            target="_blank"
                        >
                            <span className="store-primary-nav__whatsapp-visual">
                                <WhatsAppIcon />
                                <span>{translations.header.whatsapp}</span>
                            </span>
                        </a>
                    </li>
                </ul>
            </nav>
        </header>
    );
}
