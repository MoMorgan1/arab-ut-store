import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Disclosure } from '@/components/motion/disclosure';

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

function ChevronIcon() {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="16"
            viewBox="0 0 24 24"
            width="16"
        >
            <path
                d="m6 9 6 6 6-6"
                stroke="currentColor"
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2"
            />
        </svg>
    );
}

/** Both options are shown, each in its own language. */
const STORE_LOCALES: StoreLocale[] = ['ar', 'en'];
const LANGUAGE_NAMES: Record<StoreLocale, string> = {
    ar: 'العربية',
    en: 'English',
};

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
            <Disclosure
                anchored
                aria-label={translations.header.preferences}
                className="store-preferences__dialog"
                open={isOpen}
                role="dialog"
            >
                {() => (
                    <>
                        <p className="store-preferences__title">
                            {translations.header.preferences}
                        </p>
                        <div className="store-preferences__group">
                            <label
                                className="store-preferences__label"
                                htmlFor="store-preferences-language"
                            >
                                {translations.language_label}
                            </label>
                            <span className="store-preferences__select">
                                <select
                                    id="store-preferences-language"
                                    onChange={(event) => {
                                        const next = event.target
                                            .value as StoreLocale;

                                        if (next === locale) {
                                            return;
                                        }

                                        // The locale lives in the path, so
                                        // this is a document navigation, not
                                        // an Inertia visit.
                                        window.location.assign(
                                            localizedStoreHref(
                                                currentUrl,
                                                next,
                                            ),
                                        );
                                    }}
                                    value={locale}
                                >
                                    {STORE_LOCALES.map((code) => (
                                        <option
                                            key={code}
                                            lang={code}
                                            value={code}
                                        >
                                            {LANGUAGE_NAMES[code]}
                                        </option>
                                    ))}
                                </select>
                                <ChevronIcon />
                            </span>
                        </div>
                        <div className="store-preferences__group">
                            <label
                                className="store-preferences__label"
                                htmlFor="store-preferences-currency"
                            >
                                {translations.currency}
                            </label>
                            <span className="store-preferences__select">
                                <select
                                    aria-label={translations.currency_selector}
                                    id="store-preferences-currency"
                                    onChange={(event) => {
                                        const next = event.target.value;

                                        if (next === displayCurrency) {
                                            return;
                                        }

                                        setIsOpen(false);
                                        router.visit(
                                            currencyHref(currentUrl, next),
                                            {
                                                preserveScroll: true,
                                                preserveState: true,
                                                replace: true,
                                            },
                                        );
                                    }}
                                    value={displayCurrency}
                                >
                                    {displayCurrencies.map((currency) => (
                                        <option key={currency} value={currency}>
                                            {currency}
                                        </option>
                                    ))}
                                </select>
                                <ChevronIcon />
                            </span>
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
                    </>
                )}
            </Disclosure>
        </div>
    );
}
