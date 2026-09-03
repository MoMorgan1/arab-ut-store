import { usePage } from '@inertiajs/react';

import { ReviewCard, ReviewSummary } from '@/components/store/reviews-section';
import { StoreSeoHead } from '@/components/store/store-seo-head';
import StoreLayout from '@/layouts/store-layout';
import type {
    ReviewFilterState,
    StoreReviewsPageProps,
} from '@/types/store-content';

type FilterKey = 'all' | 'five' | 'four' | 'verified' | 'comment';

const DEFAULT_FILTERS: ReviewFilterState = {
    rating: null,
    service: null,
    sort: 'newest',
    verified: false,
    withComment: false,
};

export default function StoreReviews() {
    const page = usePage<StoreReviewsPageProps>();
    const props = page.props;
    const copy = props.reviewsPage;
    const pagination = props.reviews.pagination;
    const filters = props.filters ?? DEFAULT_FILTERS;
    const locale = props.locale === 'en' ? 'en' : 'ar';

    const chips: Array<{ key: FilterKey; label: string; active: boolean }> = [
        {
            key: 'all',
            label: copy.filter_all ?? 'All',
            active:
                filters.rating === null &&
                !filters.verified &&
                !filters.withComment,
        },
        {
            key: 'five',
            label: copy.filter_five ?? '5',
            active: filters.rating === '5',
        },
        {
            key: 'four',
            label: copy.filter_four ?? '4',
            active: filters.rating === '4',
        },
        {
            key: 'verified',
            label: copy.filter_verified ?? 'Verified',
            active: filters.verified,
        },
        {
            key: 'comment',
            label: copy.filter_with_comment ?? 'With comment',
            active: filters.withComment,
        },
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
            <StoreSeoHead title={copy.title} />
            <main className="store-reviews-page" id="store-content">
                <div className="store-reviews__top">
                    <header className="store-catalog-hero store-reviews-page__hero">
                        <p>{copy.eyebrow}</p>
                        <h1 className="store-reviews-page__title">
                            {copy.title}
                        </h1>
                        {copy.intro ? <span>{copy.intro}</span> : null}
                    </header>
                    <ReviewSummary
                        locale={locale}
                        reviews={props.reviews}
                        translations={copy}
                    />
                </div>

                <nav
                    aria-label={copy.filters_label}
                    className="store-reviews-filters"
                >
                    {filters.service ? (
                        <ul className="store-reviews-filters__chips store-reviews-filters__chips--service">
                            <li>
                                <a
                                    className="store-reviews-chip"
                                    href={query({ ...filters, service: null })}
                                >
                                    {copy.service_all}
                                </a>
                            </li>
                            <li>
                                <a
                                    aria-current="true"
                                    className="store-reviews-chip is-active"
                                    href={query(filters)}
                                >
                                    {copy.service_names?.[filters.service] ??
                                        filters.service}
                                </a>
                            </li>
                        </ul>
                    ) : null}
                    <ul className="store-reviews-filters__chips">
                        {chips.map((chip) => (
                            <li key={chip.key}>
                                <a
                                    aria-current={
                                        chip.active ? 'true' : undefined
                                    }
                                    className={
                                        chip.active
                                            ? 'store-reviews-chip is-active'
                                            : 'store-reviews-chip'
                                    }
                                    href={filterUrl(filters, chip.key)}
                                >
                                    {chip.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                    <div className="store-reviews-filters__sort">
                        <span>{copy.sort_label}</span>
                        <a
                            aria-current={
                                filters.sort !== 'highest' ? 'true' : undefined
                            }
                            className={
                                filters.sort !== 'highest'
                                    ? 'store-reviews-chip is-active'
                                    : 'store-reviews-chip'
                            }
                            href={sortUrl(filters, 'newest')}
                        >
                            {copy.sort_newest}
                        </a>
                        <a
                            aria-current={
                                filters.sort === 'highest' ? 'true' : undefined
                            }
                            className={
                                filters.sort === 'highest'
                                    ? 'store-reviews-chip is-active'
                                    : 'store-reviews-chip'
                            }
                            href={sortUrl(filters, 'highest')}
                        >
                            {copy.sort_highest}
                        </a>
                    </div>
                </nav>

                {props.reviews.items.length === 0 ? (
                    <p className="store-reviews__empty">{copy.empty}</p>
                ) : (
                    <ul className="store-reviews-page__grid">
                        {props.reviews.items.map((review, index) => (
                            <ReviewCard
                                key={review.id}
                                locale={locale}
                                revealDelayMs={Math.min(index * 70, 280)}
                                review={review}
                                translations={copy}
                            />
                        ))}
                    </ul>
                )}

                {pagination.lastPage > 1 ? (
                    <nav aria-label={copy.pages} className="store-pagination">
                        {pagination.page > 1 ? (
                            <a href={pageUrl(filters, pagination.page - 1)}>
                                {copy.previous}
                            </a>
                        ) : (
                            <span />
                        )}
                        <span>
                            {copy.page_of
                                ? copy.page_of
                                      .replace(':page', String(pagination.page))
                                      .replace(
                                          ':last',
                                          String(pagination.lastPage),
                                      )
                                : `${pagination.page} / ${pagination.lastPage}`}
                        </span>
                        {pagination.page < pagination.lastPage ? (
                            <a href={pageUrl(filters, pagination.page + 1)}>
                                {copy.next}
                            </a>
                        ) : (
                            <span />
                        )}
                    </nav>
                ) : null}

                {props.rateUrl && copy.rate_your_order ? (
                    <div className="store-reviews__actions store-reviews-page__actions">
                        <a className="store-reviews__rate" href={props.rateUrl}>
                            {copy.rate_your_order}
                        </a>
                    </div>
                ) : null}
            </main>
        </StoreLayout>
    );
}

function query(filters: ReviewFilterState, page?: number): string {
    const params = new URLSearchParams();

    if (filters.service) {
        params.set('service', filters.service);
    }

    if (filters.rating) {
        params.set('rating', filters.rating);
    }

    if (filters.verified) {
        params.set('verified', '1');
    }

    if (filters.withComment) {
        params.set('comment', '1');
    }

    if (filters.sort === 'highest') {
        params.set('sort', 'highest');
    }

    if (page && page > 1) {
        params.set('page', String(page));
    }

    const encoded = params.toString();

    return encoded === '' ? '?' : `?${encoded}`;
}

function filterUrl(filters: ReviewFilterState, key: FilterKey): string {
    switch (key) {
        case 'all':
            return query({
                ...DEFAULT_FILTERS,
                service: filters.service,
                sort: filters.sort,
            });
        case 'five':
            return query({
                ...filters,
                rating: filters.rating === '5' ? null : '5',
            });
        case 'four':
            return query({
                ...filters,
                rating: filters.rating === '4' ? null : '4',
            });
        case 'verified':
            return query({ ...filters, verified: !filters.verified });
        case 'comment':
            return query({ ...filters, withComment: !filters.withComment });
    }
}

function sortUrl(filters: ReviewFilterState, sort: 'newest' | 'highest') {
    return query({ ...filters, sort });
}

function pageUrl(filters: ReviewFilterState, page: number) {
    return query(filters, page);
}
