import { usePage } from '@inertiajs/react';

import { FutChampionsConfigurator } from '@/components/configurator/manual-services/fut-champions-configurator';
import { ManualServiceSuggestions } from '@/components/configurator/manual-services/manual-service-suggestions';
import { RivalsConfigurator } from '@/components/configurator/manual-services/rivals-configurator';
import { StoreSeoHead } from '@/components/store/store-seo-head';
import StoreLayout from '@/layouts/store-layout';
import type {
    FutServiceTranslations,
    ManualServicePageProps,
    RivalsServiceTranslations,
} from '@/types/manual-services';

export default function StoreManualService() {
    const page = usePage<ManualServicePageProps>();
    const props = page.props;
    const manual = props.manualService;
    const common = props.manualServicePage.common;
    const ready =
        manual.active &&
        manual.scheduleVersion !== null &&
        manual.pricing !== null;

    return (
        <StoreLayout
            cartCount={props.cartCount}
            currentUrl={page.url}
            direction={props.direction}
            displayCurrencies={props.displayCurrencies}
            displayCurrency={props.displayCurrency}
            locale={props.locale}
            storeShell={props.storeShell}
            ui={props.ui}
        >
            <StoreSeoHead
                title={manual.product.name}
                description={manual.product.description}
                locale={props.locale}
                schemaType="Product"
            />
            <main className="manual-service-page" id="store-content">
                <a className="manual-service-page__back" href={props.backUrl}>
                    {common.back}
                </a>
                <header className="manual-service-hero">
                    <div>
                        <p>{props.manualServicePage.service.eyebrow}</p>
                        <h1>{manual.product.name}</h1>
                        <div>{manual.product.description}</div>
                    </div>
                    <img
                        alt={manual.product.image.alt}
                        height="360"
                        src={manual.product.image.url}
                        width="480"
                    />
                </header>
                {!ready ? (
                    <section
                        className="manual-service-unavailable"
                        role="status"
                    >
                        <h2>{common.unavailable_title}</h2>
                        <p>{common.unavailable_body}</p>
                    </section>
                ) : manual.service === 'fut_champions' &&
                  manual.scheduleVersion !== null &&
                  manual.pricing !== null &&
                  'rankOptions' in manual.pricing ? (
                    <FutChampionsConfigurator
                        addUrl={manual.addUrl}
                        common={common}
                        locale={props.locale}
                        pricing={manual.pricing}
                        product={manual.product}
                        scheduleVersion={manual.scheduleVersion}
                        service={
                            props.manualServicePage
                                .service as FutServiceTranslations
                        }
                        tutorials={manual.tutorials}
                    />
                ) : manual.service === 'rivals' &&
                  manual.scheduleVersion !== null &&
                  manual.pricing !== null &&
                  'ladder' in manual.pricing ? (
                    <RivalsConfigurator
                        addUrl={manual.addUrl}
                        common={common}
                        locale={props.locale}
                        pricing={manual.pricing}
                        product={manual.product}
                        scheduleVersion={manual.scheduleVersion}
                        service={
                            props.manualServicePage
                                .service as RivalsServiceTranslations
                        }
                        tutorials={manual.tutorials}
                    />
                ) : null}
                <section className="manual-service-notes">
                    <h2>{common.notes_title}</h2>
                    <ul>
                        {Object.values(
                            props.manualServicePage.service.notes,
                        ).map((note) => (
                            <li key={note}>{note}</li>
                        ))}
                    </ul>
                </section>
                <ManualServiceSuggestions
                    services={props.manualServicePage.relatedServices}
                    translations={props.manualServicePage.relatedTranslations}
                />
            </main>
        </StoreLayout>
    );
}
