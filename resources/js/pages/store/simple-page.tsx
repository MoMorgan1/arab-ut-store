import { Head, usePage } from '@inertiajs/react';

import StoreLayout from '@/layouts/store-layout';
import type { SimpleStorePageProps } from '@/types/store-shell';

export default function SimpleStorePage() {
    const inertia = usePage<SimpleStorePageProps>();
    const {
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
            currentUrl={inertia.url}
            direction={direction}
            displayCurrency={displayCurrency}
            displayCurrencies={displayCurrencies}
            locale={locale}
            storeShell={storeShell}
            ui={ui}
        >
            <Head title={page.title} />
            <section
                aria-labelledby="simple-page-title"
                className="store-simple-page"
            >
                <p>{ui.simple_pages.eyebrow}</p>
                <h1 id="simple-page-title">{page.title}</h1>
                <p>{page.body}</p>
                <a href={storeShell.homeUrl}>{ui.simple_pages.back_home}</a>
            </section>
        </StoreLayout>
    );
}
