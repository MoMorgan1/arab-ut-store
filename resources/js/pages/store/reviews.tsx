import { usePage } from '@inertiajs/react';

import { ReviewCard } from '@/components/store/reviews-section';
import { StoreSeoHead } from '@/components/store/store-seo-head';
import StoreLayout from '@/layouts/store-layout';
import type { StoreReviewsPageProps } from '@/types/store-content';

export default function StoreReviews() {
    const page = usePage<StoreReviewsPageProps>();
    const props = page.props;
    const pagination = props.reviews.pagination;

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
            <StoreSeoHead title={props.reviewsPage.title} />
            <main className="store-reviews-page" id="store-content">
                <header className="store-catalog-hero">
                    <p>{props.reviewsPage.eyebrow}</p>
                    <h1 className="store-reviews-page__title">
                        {props.reviewsPage.title}
                    </h1>
                    {props.reviews.average === null ? null : (
                        <span>
                            {props.reviewsPage.summary
                                .replace(
                                    ':average',
                                    props.reviews.average.toFixed(1),
                                )
                                .replace(':count', String(props.reviews.count))}
                        </span>
                    )}
                </header>

                {props.reviews.items.length === 0 ? (
                    <p className="store-reviews__empty">
                        {props.reviewsPage.empty}
                    </p>
                ) : (
                    <ul className="store-reviews-page__grid">
                        {props.reviews.items.map((review) => (
                            <ReviewCard
                                key={review.id}
                                review={review}
                                translations={props.reviewsPage}
                            />
                        ))}
                    </ul>
                )}

                {pagination.lastPage > 1 ? (
                    <nav
                        aria-label={props.reviewsPage.pages}
                        className="store-pagination"
                    >
                        {pagination.page > 1 ? (
                            <a href={`?page=${pagination.page - 1}`}>
                                {props.reviewsPage.previous}
                            </a>
                        ) : (
                            <span />
                        )}
                        <span>
                            {pagination.page} / {pagination.lastPage}
                        </span>
                        {pagination.page < pagination.lastPage ? (
                            <a href={`?page=${pagination.page + 1}`}>
                                {props.reviewsPage.next}
                            </a>
                        ) : (
                            <span />
                        )}
                    </nav>
                ) : null}
            </main>
        </StoreLayout>
    );
}
