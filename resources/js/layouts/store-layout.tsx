import type { PropsWithChildren } from 'react';

export type StoreLayoutTranslations = {
    currency_selector: string;
    language: string;
    skip_to_content: string;
    store_tools: string;
};

type StoreLayoutProps = PropsWithChildren<{
    currentUrl: string;
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    ui: StoreLayoutTranslations;
}>;

function relativeUrl(currentUrl: string): URL {
    return new URL(currentUrl, 'https://arab-ut.local');
}

function currencyHref(currentUrl: string, currency: string): string {
    const url = relativeUrl(currentUrl);

    url.searchParams.set('currency', currency);

    return `${url.pathname}${url.search}${url.hash}`;
}

function languageHref(currentUrl: string, locale: 'ar' | 'en'): string {
    const url = relativeUrl(currentUrl);
    const targetPath = locale === 'ar' ? '/en' : '/';

    return `${targetPath}${url.search}${url.hash}`;
}

export default function StoreLayout({
    children,
    currentUrl,
    direction,
    displayCurrency,
    displayCurrencies,
    locale,
    ui,
}: StoreLayoutProps) {
    const targetLocale = locale === 'ar' ? 'en' : 'ar';
    const targetDirection = targetLocale === 'ar' ? 'rtl' : 'ltr';

    return (
        <div
            className="store-shell min-h-screen bg-[var(--arabut-navy)] text-[var(--arabut-ink)]"
            dir={direction}
            lang={locale}
        >
            <a className="store-skip-link" href="#store-content">
                {ui.skip_to_content}
            </a>
            <header className="store-header" dir={direction}>
                <div className="store-header__inner">
                    <a
                        aria-label="Arab UT"
                        className="store-wordmark"
                        href={locale === 'ar' ? '/' : '/en'}
                    >
                        <img
                            alt=""
                            aria-hidden="true"
                            height="40"
                            src="/images/arabut-logo-header.webp"
                            width="40"
                        />
                        <span>Arab UT</span>
                    </a>
                    <div className="store-tools">
                        <nav aria-label={ui.store_tools}>
                            <a
                                className="store-tool-link"
                                dir={targetDirection}
                                href={languageHref(currentUrl, locale)}
                                lang={targetLocale}
                            >
                                {ui.language}
                            </a>
                        </nav>
                        <nav aria-label={ui.currency_selector}>
                            <details className="group relative">
                                <summary
                                    aria-label={`${ui.currency_selector}: ${displayCurrency}`}
                                    className="store-currency-toggle"
                                >
                                    <span aria-hidden="true">
                                        {displayCurrency}
                                    </span>
                                    <span
                                        aria-hidden="true"
                                        className="transition group-open:rotate-180"
                                    >
                                        ⌄
                                    </span>
                                </summary>
                                <ul className="store-currency-menu ltr:right-0 rtl:left-0">
                                    {displayCurrencies.map((currency) => (
                                        <li key={currency}>
                                            <a
                                                aria-current={
                                                    currency === displayCurrency
                                                        ? 'page'
                                                        : undefined
                                                }
                                                className="store-currency-option"
                                                href={currencyHref(
                                                    currentUrl,
                                                    currency,
                                                )}
                                            >
                                                {currency}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        </nav>
                    </div>
                </div>
            </header>
            <main className="store-main" id="store-content">
                {children}
            </main>
        </div>
    );
}
