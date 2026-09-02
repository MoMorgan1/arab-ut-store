import { usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useEffect, useState } from 'react';

import { FutChampionsConfigurator } from '@/components/configurator/manual-services/fut-champions-configurator';
import { ManualServiceSuggestions } from '@/components/configurator/manual-services/manual-service-suggestions';
import { RivalsConfigurator } from '@/components/configurator/manual-services/rivals-configurator';
import { StoreSeoHead } from '@/components/store/store-seo-head';
import StoreLayout from '@/layouts/store-layout';
import { riyals, trackViewItem } from '@/lib/analytics';
import type {
    FutServiceTranslations,
    ManualServicePageProps,
    RivalsServiceTranslations,
} from '@/types/manual-services';

type ManualServiceTab = 'options' | 'guide';

export default function StoreManualService() {
    const page = usePage<ManualServicePageProps>();
    const props = page.props;
    const manual = props.manualService;
    const common = props.manualServicePage.common;
    const [tab, setTab] = useState<ManualServiceTab>('options');
    const ready =
        manual.active &&
        manual.scheduleVersion !== null &&
        manual.pricing !== null;
    const baseAmount =
        manual.pricing === null
            ? null
            : 'rankOptions' in manual.pricing
              ? manual.pricing.rankOptions.at(-1)?.price
              : manual.pricing.stepOptions[0]?.price;

    useEffect(() => {
        trackViewItem({
            id: manual.product.slug,
            name: manual.product.name,
            quantity: 1,
            ...(baseAmount !== undefined &&
            baseAmount !== null &&
            baseAmount.currency === 'SAR'
                ? { price: riyals(baseAmount.amountMinor) }
                : {}),
        });
        // Once per page load: the page is one product.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [manual.product.slug]);

    const tabs: Array<{ key: ManualServiceTab; label: string }> = [
        { key: 'options', label: common.tab_options },
        { key: 'guide', label: common.tab_guide },
    ];

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
            <StoreSeoHead title={manual.product.name} />
            <main className="manual-service-page" id="store-content">
                <a className="manual-service-page__back" href={props.backUrl}>
                    {common.back}
                </a>
                <header className="manual-service-hero">
                    <div className="manual-service-hero__content">
                        <p className="manual-service-hero__eyebrow">
                            {props.manualServicePage.service.eyebrow}
                        </p>
                        <h1 className="manual-service-hero__title">
                            {manual.product.name}
                        </h1>
                        <div className="manual-service-hero__description">
                            {manual.product.description}
                        </div>
                    </div>
                    {manual.product.image.url ? (
                        <div className="manual-service-hero__media">
                            <img
                                alt={manual.product.image.alt}
                                loading="lazy"
                                src={manual.product.image.url}
                            />
                        </div>
                    ) : null}
                </header>
                <div
                    aria-label={manual.product.name}
                    className="manual-service-tabs"
                    role="tablist"
                >
                    {tabs.map((entry) => (
                        <button
                            aria-controls={`manual-service-tab-${entry.key}`}
                            aria-selected={tab === entry.key}
                            className="manual-service-tabs__tab"
                            id={`manual-service-tab-button-${entry.key}`}
                            key={entry.key}
                            onClick={() => setTab(entry.key)}
                            role="tab"
                            tabIndex={tab === entry.key ? 0 : -1}
                            type="button"
                        >
                            {entry.label}
                        </button>
                    ))}
                </div>
                <div
                    aria-labelledby="manual-service-tab-button-options"
                    hidden={tab !== 'options'}
                    id="manual-service-tab-options"
                    role="tabpanel"
                >
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
                </div>
                <div
                    aria-labelledby="manual-service-tab-button-guide"
                    hidden={tab !== 'guide'}
                    id="manual-service-tab-guide"
                    role="tabpanel"
                >
                    <section className="manual-section manual-service-guide">
                        <h2 className="manual-service-notes__title">
                            {common.notes_title}
                        </h2>
                        <ul className="manual-service-notes__list">
                            {Object.values(
                                props.manualServicePage.service.notes,
                            ).map((note) => (
                                <li key={note}>{note}</li>
                            ))}
                        </ul>
                        <div className="manual-service-guide__links">
                            <a
                                className="manual-backup-codes__tutorial"
                                href={manual.tutorials.ea}
                                rel="noopener noreferrer"
                                target="_blank"
                            >
                                {common.ea_tutorial}
                                <ExternalLink aria-hidden="true" />
                            </a>
                            <a
                                className="manual-backup-codes__tutorial"
                                href={manual.tutorials.playstation}
                                rel="noopener noreferrer"
                                target="_blank"
                            >
                                {common.playstation_tutorial}
                                <ExternalLink aria-hidden="true" />
                            </a>
                        </div>
                    </section>
                </div>
                <ManualServiceSuggestions
                    common={common}
                    locale={props.locale}
                    relatedServices={props.manualServicePage.relatedServices}
                    translations={props.manualServicePage.relatedTranslations}
                />
            </main>
        </StoreLayout>
    );
}
