import type { PropsWithChildren } from 'react';

type StoreLayoutProps = PropsWithChildren<{
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
}>;

export default function StoreLayout({
    children,
    direction,
    displayCurrency,
    locale,
}: StoreLayoutProps) {
    const languageHref = locale === 'ar' ? '/en' : '/ar';
    const languageLabel = locale === 'ar' ? 'English' : 'العربية';

    return (
        <div
            className="min-h-screen bg-[var(--arabut-navy)] text-[var(--arabut-ink)]"
            dir={direction}
            lang={locale}
        >
            <a
                className="sr-only z-50 rounded bg-[var(--arabut-gold)] px-4 py-2 text-[var(--arabut-navy)] focus:not-sr-only focus:absolute focus:top-3 focus:left-3"
                href="#store-content"
            >
                {locale === 'ar' ? 'انتقل إلى المحتوى' : 'Skip to content'}
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
                    <nav
                        aria-label={
                            locale === 'ar' ? 'أدوات المتجر' : 'Store tools'
                        }
                        className="flex items-center gap-2 text-sm"
                    >
                        <a
                            className="rounded px-2 py-2 text-[var(--arabut-muted)] transition outline-none hover:text-[var(--arabut-gold-bright)] focus-visible:ring-2 focus-visible:ring-[var(--arabut-focus)]"
                            href={languageHref}
                        >
                            {languageLabel}
                        </a>
                        <a
                            className="rounded border border-[var(--arabut-line)] px-2 py-1.5 font-medium text-[var(--arabut-ink)] outline-none focus-visible:ring-2 focus-visible:ring-[var(--arabut-focus)]"
                            href="?currency=SAR"
                        >
                            {displayCurrency}
                        </a>
                    </nav>
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
