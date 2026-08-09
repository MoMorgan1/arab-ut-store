import { Head, usePage } from '@inertiajs/react';

import StoreLayout from '@/layouts/store-layout';

type StorePageProps = {
    checkoutCurrency: string;
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    locale: 'ar' | 'en';
    ui: {
        brand: string;
        browse_services: string;
        service_notice: string;
    };
};

export default function StoreHome() {
    const { checkoutCurrency, direction, displayCurrency, locale, ui } =
        usePage<StorePageProps>().props;

    return (
        <StoreLayout
            direction={direction}
            displayCurrency={displayCurrency}
            locale={locale}
        >
            <Head title={ui.brand} />
            <section className="max-w-xl space-y-4 py-8 sm:py-14">
                <p className="text-sm font-medium tracking-wide text-[var(--arabut-gold)]">
                    {ui.service_notice}
                </p>
                <h1 className="text-3xl font-semibold tracking-tight sm:text-5xl">
                    {ui.brand}
                </h1>
                <p className="max-w-prose leading-7 text-[var(--arabut-muted)]">
                    {locale === 'ar'
                        ? `كل الأسعار النهائية والدفع بالريال السعودي (${checkoutCurrency}).`
                        : `All final prices and checkout are in Saudi Riyal (${checkoutCurrency}).`}
                </p>
                <a
                    className="inline-flex min-h-11 items-center rounded bg-[var(--arabut-gold)] px-5 font-semibold text-[var(--arabut-navy)] transition outline-none hover:bg-[var(--arabut-gold-bright)] focus-visible:ring-2 focus-visible:ring-[var(--arabut-focus)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--arabut-navy)]"
                    href="#store-content"
                >
                    {ui.browse_services}
                </a>
            </section>
        </StoreLayout>
    );
}
