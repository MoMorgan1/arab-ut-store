import { usePage } from '@inertiajs/react';

import { StoreSeoHead } from '@/components/store/store-seo-head';
import StoreLayout from '@/layouts/store-layout';
import type { StoreSitemapPageProps } from '@/types/store-shell';

export default function StoreSitemapPage() {
    const inertia = usePage<StoreSitemapPageProps>();
    const {
        cartCount,
        direction,
        displayCurrencies,
        displayCurrency,
        locale,
        sitemapPage,
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
            <StoreSeoHead title={sitemapPage.title} />
            <article className="store-info-page">
                <section
                    aria-labelledby="store-sitemap-title"
                    className="store-info-page__hero"
                >
                    <div aria-hidden="true" className="store-info-page__glow" />
                    <div className="store-info-page__container store-info-page__hero-inner">
                        <nav
                            aria-label={sitemapPage.title}
                            className="store-info-page__breadcrumb"
                        >
                            <a href={storeShell.homeUrl}>{ui.header.home}</a>
                            <span aria-hidden="true">›</span>
                            <span aria-current="page">{sitemapPage.title}</span>
                        </nav>
                        <h1 id="store-sitemap-title">{sitemapPage.title}</h1>
                        <p>{sitemapPage.eyebrow}</p>
                    </div>
                </section>
                <section
                    aria-label={sitemapPage.title}
                    className="store-info-page__content"
                >
                    <div className="store-info-page__container store-info-page__prose">
                        {sitemapPage.groups.map((group) => (
                            <section
                                aria-label={group.heading}
                                key={group.heading}
                            >
                                <h2>{group.heading}</h2>
                                <ul>
                                    {group.links.map((link) => (
                                        <li key={link.href}>
                                            <a href={link.href}>{link.label}</a>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                </section>
            </article>
        </StoreLayout>
    );
}
