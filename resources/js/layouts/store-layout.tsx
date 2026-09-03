import type { PropsWithChildren } from 'react';

import { CartAddedNotice } from '@/components/store/cart-added-notice';
import { StoreFooter } from '@/components/store/store-footer';
import { StoreHeader } from '@/components/store/store-header';
import { useScrollReveal } from '@/hooks/use-scroll-reveal';
import type {
    StoreShellConfig,
    StoreShellTranslations,
} from '@/types/store-shell';

export type StoreLayoutTranslations = StoreShellTranslations;

type StoreLayoutProps = PropsWithChildren<{
    currentUrl: string;
    cartCount: number;
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    storeShell: StoreShellConfig;
    ui: StoreShellTranslations;
}>;

export default function StoreLayout({
    children,
    cartCount,
    currentUrl,
    direction,
    displayCurrency,
    displayCurrencies,
    locale,
    storeShell,
    ui,
}: StoreLayoutProps) {
    useScrollReveal();

    return (
        <div
            className="store-shell min-h-screen bg-[var(--arabut-navy)] text-[var(--arabut-ink)]"
            dir={direction}
            lang={locale}
        >
            <a className="store-skip-link" href="#store-content">
                {ui.skip_to_content}
            </a>
            <StoreHeader
                currentUrl={currentUrl}
                cartCount={cartCount}
                direction={direction}
                displayCurrencies={displayCurrencies}
                displayCurrency={displayCurrency}
                locale={locale}
                shell={storeShell}
                translations={ui}
            />
            <main className="store-main" id="store-content">
                {children}
            </main>
            <CartAddedNotice locale={locale} translations={ui.cart_added} />
            <StoreFooter locale={locale} shell={storeShell} translations={ui} />
        </div>
    );
}
