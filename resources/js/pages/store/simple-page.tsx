import { usePage } from '@inertiajs/react';

import StoreInformationPageContent from '@/components/store/store-information-page';
import { StoreSeoHead } from '@/components/store/store-seo-head';
import StoreLayout from '@/layouts/store-layout';
import type { SimpleStorePageProps } from '@/types/store-shell';

export default function SimpleStorePage() {
    const inertia = usePage<SimpleStorePageProps>();
    const {
        cartCount,
        direction,
        displayCurrencies,
        displayCurrency,
        locale,
        page,
        storeShell,
        ui,
    } = inertia.props;

    return (
        <StoreLayout
            cartCount={cartCount}
            currentUrl={inertia.url}
            direction={direction}
            displayCurrency={displayCurrency}
            displayCurrencies={displayCurrencies}
            locale={locale}
            storeShell={storeShell}
            ui={ui}
        >
            <StoreSeoHead
                title={page.title}
                description={page.subtitle}
                locale={locale}
            />
            <StoreInformationPageContent
                homeUrl={storeShell.homeUrl}
                page={page}
            />
        </StoreLayout>
    );
}
