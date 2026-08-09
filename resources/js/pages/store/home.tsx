import { Head, usePage } from '@inertiajs/react';

import StoreLayout from '@/layouts/store-layout';
import type { StoreLayoutTranslations } from '@/layouts/store-layout';

type StorePageProps = {
    checkoutCurrency: string;
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    locale: 'ar' | 'en';
    ui: StoreLayoutTranslations & {
        brand: string;
        browse_services: string;
        checkout_notice: string;
        home_title: string;
        service_notice: string;
    };
};

export default function StoreHome() {
    const page = usePage<StorePageProps>();
    const { checkoutCurrency, direction, displayCurrency, locale, ui } =
        page.props;

    return (
        <StoreLayout
            currentUrl={page.url}
            direction={direction}
            displayCurrency={displayCurrency}
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
                <p className="max-w-prose leading-7 text-[var(--arabut-muted)]">
                    {ui.checkout_notice.replace(':currency', checkoutCurrency)}
                </p>
                <a
                    className="inline-flex min-h-11 items-center rounded bg-[var(--arabut-gold)] px-5 font-semibold text-[var(--arabut-navy)] transition outline-none hover:bg-[var(--arabut-gold-bright)] focus-visible:ring-2 focus-visible:ring-[var(--arabut-focus)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--arabut-navy)]"
                    href="#services"
                >
                    {ui.browse_services}
                </a>
            </section>
            <section
                aria-labelledby="services-heading"
                className="border-t border-[var(--arabut-line)] py-8"
                id="services"
            >
                <h2 className="text-2xl font-semibold" id="services-heading">
                    {ui.browse_services}
                </h2>
            </section>
        </StoreLayout>
    );
}
