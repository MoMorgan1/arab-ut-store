import { ReviewsRail, ReviewSummary } from '@/components/store/reviews-section';
import type { ServiceReviewsProps } from '@/types/store-content';

function ArrowIcon({ direction }: { direction: 'back' | 'forward' }) {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="18"
            stroke="currentColor"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth="2"
            viewBox="0 0 24 24"
            width="18"
        >
            {direction === 'forward' ? (
                <path d="M9 6l6 6-6 6" />
            ) : (
                <path d="M15 6l-6 6 6 6" />
            )}
        </svg>
    );
}

export function ServiceReviewsSection({
    direction,
    locale,
    serviceReviews,
}: ServiceReviewsProps) {
    if (
        serviceReviews === null ||
        serviceReviews.reviews.count === 0 ||
        serviceReviews.reviews.items.length === 0
    ) {
        return null;
    }

    const eyebrow =
        serviceReviews.translations.service_eyebrow ??
        serviceReviews.translations.eyebrow;

    return (
        <section
            aria-labelledby="service-reviews-heading"
            className="manual-section service-reviews-section"
        >
            <header className="service-reviews-section__header">
                {eyebrow ? (
                    <p className="manual-service-related__eyebrow">{eyebrow}</p>
                ) : null}
                <h2
                    className="manual-section__title"
                    id="service-reviews-heading"
                >
                    {serviceReviews.title}
                </h2>
                {serviceReviews.hint ? (
                    <p className="manual-section__hint">
                        {serviceReviews.hint}
                    </p>
                ) : null}
            </header>

            <ReviewSummary
                locale={locale}
                reviews={serviceReviews.reviews}
                translations={serviceReviews.translations}
            />

            <ReviewsRail
                direction={direction}
                items={serviceReviews.reviews.items}
                locale={locale}
                translations={serviceReviews.translations}
            />

            <div className="service-reviews-section__footer">
                <a
                    className="store-reviews__more"
                    href={serviceReviews.readAllUrl}
                >
                    {serviceReviews.readAll}
                    <ArrowIcon direction="forward" />
                </a>
            </div>
        </section>
    );
}
