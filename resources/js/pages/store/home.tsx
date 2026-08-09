import { Head, usePage } from '@inertiajs/react';

import StoreLayout from '@/layouts/store-layout';
import type { StoreLayoutTranslations } from '@/layouts/store-layout';

type StorePageProps = {
    checkoutCurrency: string;
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    locale: 'ar' | 'en';
    ui: StoreLayoutTranslations & {
        brand: string;
        home_title: string;
        service_notice: string;
    };
};

export default function StoreHome() {
    const page = usePage<StorePageProps>();
    const { direction, displayCurrencies, displayCurrency, locale, ui } =
        page.props;

    return (
        <StoreLayout
            currentUrl={page.url}
            direction={direction}
            displayCurrency={displayCurrency}
            displayCurrencies={displayCurrencies}
            locale={locale}
            ui={ui}
        >
            <Head title={ui.home_title} />
            <section className="max-w-xl space-y-4 py-8 sm:py-14">
                <p className="text-sm font-medium tracking-wide text-[var(--arabut-gold)]">
                    {ui.service_notice}
                </p>
                <h1 className="text-3xl font-semibold tracking-tight sm:text-5xl">
                    {ui.brand}
                </h1>
            </section>
        </StoreLayout>
    );
}
