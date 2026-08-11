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
                    <ul className="store-reviews-rail">
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

    return (
        <li className="store-review-card" data-testid="review-card">
            <div aria-label={ratingLabel} className="store-review-card__stars">
                <span aria-hidden="true">{'★'.repeat(review.rating)}</span>
                <span
                    aria-hidden="true"
                    className="store-review-card__stars-muted"
                >
                    {'★'.repeat(5 - review.rating)}
                </span>
            </div>
            <blockquote>{review.body}</blockquote>
            <footer>
                <strong>{review.reviewerName}</strong>
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

function interpolate(template: string, values: Record<string, string>): string {
    return Object.entries(values).reduce(
        (value, [key, replacement]) => value.replace(`:${key}`, replacement),
        template,
    );
}
