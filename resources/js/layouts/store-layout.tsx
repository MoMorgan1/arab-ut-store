import type { PropsWithChildren } from 'react';

import { StoreHeader } from '@/components/store/store-header';
import type {
    StoreShellConfig,
    StoreShellTranslations,
} from '@/types/store-shell';

export type StoreLayoutTranslations = StoreShellTranslations;

type StoreLayoutProps = PropsWithChildren<{
    currentUrl: string;
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    storeShell: StoreShellConfig;
    ui: StoreShellTranslations;
}>;

export default function StoreLayout({
    children,
    currentUrl,
    direction,
    displayCurrency,
    displayCurrencies,
    locale,
    storeShell,
    ui,
}: StoreLayoutProps) {
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
        </div>
    );
}
