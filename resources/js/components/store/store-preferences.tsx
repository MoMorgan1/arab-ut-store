import { useEffect, useRef, useState } from 'react';

import type { StoreLocale, StoreShellTranslations } from '@/types/store-shell';

export type StorePreferencesProps = {
    currentUrl: string;
    locale: StoreLocale;
    displayCurrency: string;
    displayCurrencies: string[];
    translations: StoreShellTranslations;
};

function relativeUrl(currentUrl: string): URL {
    return new URL(currentUrl, 'https://arab-ut.local');
}

export function localizedStoreHref(
    currentUrl: string,
    target: StoreLocale,
): string {
    const url = relativeUrl(currentUrl);
    const localizedPath = url.pathname.replace(/^\/(?:ar|en)(?=\/|$)/, '');

    url.pathname =
        target === 'en' ? `/en${localizedPath || ''}` : localizedPath || '/';

    return `${url.pathname}${url.search}${url.hash}`;
}

export function currencyHref(currentUrl: string, currency: string): string {
    const url = relativeUrl(currentUrl);

    url.searchParams.set('currency', currency);

    return `${url.pathname}${url.search}${url.hash}`;
}

function PreferencesIcon() {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="18"
            viewBox="0 0 24 24"
            width="18"
        >
            <path
                d="M4 7h10M18 7h2M4 17h2M10 17h10M14 4v6M6 14v6"
                stroke="currentColor"
                strokeLinecap="round"
                strokeWidth="1.8"
            />
        </svg>
    );
}

export function StorePreferences({
    currentUrl,
    displayCurrencies,
    displayCurrency,
    locale,
    translations,
}: StorePreferencesProps) {
    const [isOpen, setIsOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const targetLocale: StoreLocale = locale === 'ar' ? 'en' : 'ar';
    useEffect(() => {
        if (!isOpen) {
            return;
        }

        function handleKeyDown(event: KeyboardEvent) {
            if (event.key !== 'Escape') {
                return;
            }

            event.preventDefault();
            setIsOpen(false);
            triggerRef.current?.focus();
        }

        function handlePointerDown(event: PointerEvent) {
            if (
                event.target instanceof Node &&
                !containerRef.current?.contains(event.target)
            ) {
                setIsOpen(false);
            }
        }

        document.addEventListener('keydown', handleKeyDown);
        document.addEventListener('pointerdown', handlePointerDown);

        return () => {
            document.removeEventListener('keydown', handleKeyDown);
            document.removeEventListener('pointerdown', handlePointerDown);
        };
    }, [isOpen]);

    return (
        <div className="store-preferences" ref={containerRef}>
            <button
                aria-expanded={isOpen}
                aria-haspopup="dialog"
                aria-label={translations.header.preferences}
                className="store-preferences__trigger"
                onClick={() => setIsOpen((open) => !open)}
                ref={triggerRef}
                type="button"
            >
                <PreferencesIcon />
            </button>
            {isOpen ? (
                <div
                    aria-label={translations.header.preferences}
                    className="store-preferences__dialog"
                    role="dialog"
                >
                    <div className="store-preferences__language">
                        <a
                            dir={targetLocale === 'ar' ? 'rtl' : 'ltr'}
                            href={localizedStoreHref(currentUrl, targetLocale)}
                            lang={targetLocale}
                        >
                            {translations.language}
                        </a>
                    </div>
                    <div className="store-preferences__currencies">
                        <span>{translations.currency_selector}</span>
                        <ul>
                            {displayCurrencies.map((currency) => (
                                <li key={currency}>
                                    <a
                                        aria-current={
                                            currency === displayCurrency
                                                ? 'page'
                                                : undefined
                                        }
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
                    </div>
                    <a
                        className="store-preferences__attribution"
                        dir="ltr"
                        href="https://www.exchangerate-api.com"
                        rel="noopener noreferrer"
                        target="_blank"
                    >
                        {translations.preferences.exchange_rate_attribution}
                    </a>
                </div>
            ) : null}
        </div>
    );
}
