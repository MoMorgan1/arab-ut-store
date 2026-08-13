import { useBouncingHorizontalRail } from '@/hooks/use-bouncing-horizontal-rail';
import type {
    ReviewCollection,
    ReviewItem,
    ReviewTranslations,
} from '@/types/store-content';

export function ReviewsSection({
    locale,
    reviews,
    reviewsUrl,
    translations,
}: {
    locale: 'ar' | 'en';
    reviews: ReviewCollection;
    reviewsUrl: string;
    translations: ReviewTranslations;
}) {
    const direction = locale === 'ar' ? 'rtl' : 'ltr';
    const { containerProps, trackProps } = useBouncingHorizontalRail({
        direction,
    });

    return (
        <section
            aria-labelledby="store-reviews-title"
            className="store-reviews"
            id="reviews"
        >
            <div className="store-reviews__inner">
                <header className="store-section-heading store-reviews__heading">
                    <p>{translations.eyebrow}</p>
                    <h2 id="store-reviews-title">{translations.title}</h2>
                    {reviews.average === null ? null : (
                        <span>
                            {interpolate(translations.summary, {
                                average: reviews.average.toFixed(1),
                                count: String(reviews.count),
                            })}
                        </span>
                    )}
                </header>

                {reviews.items.length === 0 ? (
                    <p className="store-reviews__empty">{translations.empty}</p>
                ) : (
                    <ul
                        className="store-reviews-rail"
                        {...containerProps}
                        {...trackProps}
                    >
                        {reviews.items.map((review) => (
                            <ReviewCard
                                key={review.id}
                                locale={locale}
                                review={review}
                                translations={translations}
                            />
                        ))}
                    </ul>
                )}

                <a className="store-reviews__all" href={reviewsUrl}>
                    {translations.view_all}
                </a>
            </div>
        </section>
    );
}

export function ReviewCard({
    locale,
    review,
    translations,
}: {
    locale: 'ar' | 'en';
    review: ReviewItem;
    translations: ReviewTranslations;
}) {
    const ratingLabel = interpolate(translations.rating_label, {
        rating: String(review.rating),
    });
    const published = review.publishedAt
        ? new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SA' : 'en-GB', {
              dateStyle: 'medium',
              timeZone: 'UTC',
          }).format(new Date(review.publishedAt))
        : null;
    const initial = Array.from(review.reviewerName.trim())[0] ?? 'A';
    const cardClassName = [
        'store-review-card',
        review.rating === 5 ? 'store-review-card--gold' : null,
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <li className={cardClassName} data-testid="review-card">
            <div className="store-review-card__top">
                <span aria-hidden="true" className="store-review-card__avatar">
                    {initial}
                </span>
                <div className="store-review-card__customer">
                    <strong>{review.reviewerName}</strong>
                    {review.reviewerLocation ? (
                        <span className="store-review-card__location">
                            <LocationIcon />
                            {review.reviewerLocation}
                        </span>
                    ) : null}
                </div>
                <div
                    aria-label={ratingLabel}
                    className="store-review-card__stars"
                >
                    {Array.from({ length: 5 }, (_, index) => (
                        <StarIcon filled={index < review.rating} key={index} />
                    ))}
                </div>
            </div>
            <blockquote>{review.body}</blockquote>
            <footer>
                {review.verified ? <span>{translations.verified}</span> : null}
                {published === null ? null : (
                    <time dateTime={review.publishedAt ?? undefined}>
                        {published}
                    </time>
                )}
            </footer>
        </li>
    );
}

function StarIcon({ filled }: { filled: boolean }) {
    return (
        <svg
            aria-hidden="true"
            className={filled ? undefined : 'store-review-card__star-muted'}
            height="15"
            viewBox="0 0 24 24"
            width="15"
        >
            <path d="m12 2 3.09 6.26 6.91 1.01-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z" />
        </svg>
    );
}

function LocationIcon() {
    return (
        <svg aria-hidden="true" height="12" viewBox="0 0 24 24" width="12">
            <path
                d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
            />
            <circle cx="12" cy="10" fill="currentColor" r="2.25" />
        </svg>
    );
}

function interpolate(template: string, values: Record<string, string>): string {
    return Object.entries(values).reduce(
        (value, [key, replacement]) => value.replace(`:${key}`, replacement),
        template,
    );
}
