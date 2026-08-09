import type { PropsWithChildren } from 'react';

const displayCurrencies = ['SAR', 'USD', 'EUR', 'GBP'] as const;

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
    locale,
    ui,
}: StoreLayoutProps) {
    const targetLocale = locale === 'ar' ? 'en' : 'ar';
    const targetDirection = targetLocale === 'ar' ? 'rtl' : 'ltr';

    return (
        <div
            className="min-h-screen bg-[var(--arabut-navy)] text-[var(--arabut-ink)]"
            dir={direction}
            lang={locale}
        >
            <a
                className="sr-only z-50 rounded bg-[var(--arabut-gold)] px-4 py-2 text-[var(--arabut-navy)] focus:not-sr-only focus:absolute focus:start-3 focus:top-3"
                href="#store-content"
            >
                {ui.skip_to_content}
            </a>
            <header
                className="border-b border-[var(--arabut-line)] bg-[var(--arabut-navy)]"
                dir={direction}
            >
                <div className="mx-auto flex min-h-16 max-w-6xl items-center justify-between gap-3 px-4 sm:px-6">
                    <a
                        aria-label="Arab UT"
                        className="shrink-0"
                        href={locale === 'ar' ? '/' : '/en'}
                    >
                        <img
                            alt="Arab UT"
                            className="h-8 w-auto"
                            height="40"
                            src="/images/arabut-logo-header.webp"
                            width="148"
                        />
                    </a>
                    <div className="flex items-center gap-2 text-sm">
                        <nav aria-label={ui.store_tools}>
                            <a
                                className="rounded px-2 py-2 text-[var(--arabut-muted)] transition outline-none hover:text-[var(--arabut-gold-bright)] focus-visible:ring-2 focus-visible:ring-[var(--arabut-focus)]"
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
                                    className="flex min-h-9 cursor-pointer list-none items-center gap-1 rounded border border-[var(--arabut-line)] px-2 font-medium text-[var(--arabut-ink)] transition outline-none hover:border-[var(--arabut-gold)] focus-visible:ring-2 focus-visible:ring-[var(--arabut-focus)] [&::-webkit-details-marker]:hidden"
                                >
                                    <span aria-hidden="true">
                                        {displayCurrency}
                                    </span>
                                    <span
                                        aria-hidden="true"
                                        className="transition group-open:rotate-180"
                                    >
                                        ▾
                                    </span>
                                </summary>
                                <ul className="absolute top-full z-20 mt-2 min-w-24 space-y-1 rounded border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] p-2 shadow-xl ltr:right-0 rtl:left-0">
                                    {displayCurrencies.map((currency) => (
                                        <li key={currency}>
                                            <a
                                                aria-current={
                                                    currency === displayCurrency
                                                        ? 'page'
                                                        : undefined
                                                }
                                                className="flex min-h-9 items-center rounded px-3 font-medium text-[var(--arabut-muted)] transition outline-none hover:text-[var(--arabut-gold-bright)] focus-visible:ring-2 focus-visible:ring-[var(--arabut-focus)] aria-current:bg-[var(--arabut-gold)] aria-current:text-[var(--arabut-navy)]"
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
            <main
                className="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6"
                id="store-content"
            >
                {children}
            </main>
        </div>
    );
}
