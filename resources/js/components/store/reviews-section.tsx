import { useEffect, useState } from 'react';

import { useBouncingHorizontalRail } from '@/hooks/use-bouncing-horizontal-rail';
import { DATE_LOCALE } from '@/lib/date-locale';
import type {
    ReviewCollection,
    ReviewItem,
    ReviewTranslations,
} from '@/types/store-content';

/**
 * The storefront's social proof: a trust summary (average, star spread,
 * verified count) and the latest cards. On the home page the cards sit in a
 * horizontal rail with arrows and dots; the reviews page reuses the summary
 * and the card in a grid.
 */
export function ReviewsSection({
    locale,
    rateUrl,
    reviews,
    reviewsUrl,
    translations,
}: {
    locale: 'ar' | 'en';
    rateUrl?: string;
    reviews: ReviewCollection;
    reviewsUrl: string;
    translations: ReviewTranslations;
}) {
    const direction = locale === 'ar' ? 'rtl' : 'ltr';
    const { containerProps, trackProps } = useBouncingHorizontalRail({
        direction,
    });
    const [activeIndex, setActiveIndex] = useState(0);
    const items = reviews.items;

    useEffect(() => {
        const track = trackProps.ref.current;

        if (track === null) {
            return;
        }

        const update = () => {
            const cards = Array.from(track.children) as HTMLElement[];

            if (cards.length === 0) {
                return;
            }

            const trackRect = track.getBoundingClientRect();
            let nearest = 0;
            let nearestDistance = Number.POSITIVE_INFINITY;

            cards.forEach((card, index) => {
                const cardRect = card.getBoundingClientRect();
                const distance = Math.abs(
                    direction === 'rtl'
                        ? cardRect.right - trackRect.right
                        : cardRect.left - trackRect.left,
                );

                if (distance < nearestDistance) {
                    nearest = index;
                    nearestDistance = distance;
                }
            });

            setActiveIndex(nearest);
        };

        track.addEventListener('scroll', update, { passive: true });

        return () => track.removeEventListener('scroll', update);
    }, [direction, items.length, trackProps.ref]);

    function scrollCards(step: 1 | -1) {
        const track = trackProps.ref.current;
        const card = track?.firstElementChild;

        if (!track || !(card instanceof HTMLElement)) {
            return;
        }

        const distance = card.getBoundingClientRect().width + 16;

        track.scrollBy({
            left: (direction === 'rtl' ? -step : step) * distance,
            behavior: 'smooth',
        });
    }

    function scrollToCard(index: number) {
        const track = trackProps.ref.current;
        const card = track?.children.item(index);

        if (card instanceof HTMLElement) {
            card.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest',
                inline: 'start',
            });
        }
    }

    return (
        <section
            aria-labelledby="store-reviews-title"
            className="store-reviews"
            id="reviews"
        >
            <div className="store-reviews__inner">
                <div className="store-reviews__top">
                    <header className="store-section-heading store-reviews__heading">
                        <p>{translations.eyebrow}</p>
                        <h2 id="store-reviews-title">{translations.title}</h2>
                        {translations.intro ? (
                            <span className="store-reviews__intro">
                                {translations.intro}
                            </span>
                        ) : null}
                    </header>
                    {reviews.average === null ? null : (
                        <ReviewSummary
                            locale={locale}
                            reviews={reviews}
                            translations={translations}
                        />
                    )}
                </div>

                {items.length === 0 ? (
                    <p className="store-reviews__empty">{translations.empty}</p>
                ) : (
                    <div className="store-reviews__rail-shell">
                        <ul
                            aria-label={translations.rail_label}
                            className="store-reviews-rail"
                            {...containerProps}
                            {...trackProps}
                        >
                            {items.map((review) => (
                                <ReviewCard
                                    key={review.id}
                                    locale={locale}
                                    review={review}
                                    translations={translations}
                                />
                            ))}
                        </ul>
                        {items.length > 1 ? (
                            <div className="store-reviews__controls">
                                <div className="store-reviews__arrows">
                                    <button
                                        aria-label={translations.previous_cards}
                                        className="store-reviews__arrow"
                                        onClick={() => scrollCards(-1)}
                                        type="button"
                                    >
                                        <ArrowIcon direction="back" />
                                    </button>
                                    <button
                                        aria-label={translations.next_cards}
                                        className="store-reviews__arrow"
                                        onClick={() => scrollCards(1)}
                                        type="button"
                                    >
                                        <ArrowIcon direction="forward" />
                                    </button>
                                </div>
                                <div
                                    aria-hidden="true"
                                    className="store-reviews__dots"
                                >
                                    {items.map((review, index) => (
                                        <button
                                            className={
                                                index === activeIndex
                                                    ? 'is-active'
                                                    : undefined
                                            }
                                            key={review.id}
                                            onClick={() => scrollToCard(index)}
                                            tabIndex={-1}
                                            type="button"
                                        />
                                    ))}
                                </div>
                            </div>
                        ) : null}
                    </div>
                )}

                <div className="store-reviews__actions">
                    <a className="store-reviews__all" href={reviewsUrl}>
                        {translations.read_all ?? translations.view_all}
                    </a>
                    {rateUrl && translations.rate_your_order ? (
                        <a className="store-reviews__rate" href={rateUrl}>
                            {translations.rate_your_order}
                        </a>
                    ) : null}
                </div>
            </div>
        </section>
    );
}

export function ReviewSummary({
    reviews,
    translations,
}: {
    locale?: 'ar' | 'en';
    reviews: ReviewCollection;
    translations: ReviewTranslations;
}) {
    if (reviews.average === null) {
        return null;
    }

    // The storefront writes every figure with Western digits (hero stats,
    // prices), so the summary does too.
    const numberLocale = 'en-US';
    const average = reviews.average;
    const rounded = Math.round(average);
    const distribution = reviews.distribution ?? [];

    return (
        <div
            aria-label={interpolate(translations.summary, {
                average: average.toFixed(1),
                count: String(reviews.count),
            })}
            className="store-reviews-summary"
            role="group"
        >
            <div className="store-reviews-summary__score">
                <strong>{average.toFixed(1)}</strong>
                <span
                    aria-hidden="true"
                    className="store-reviews-summary__stars"
                >
                    {Array.from({ length: 5 }, (_, index) => (
                        <StarIcon filled={index < rounded} key={index} />
                    ))}
                </span>
                <span className="store-reviews-summary__count">
                    {interpolate(translations.of_count ?? ':count', {
                        count: reviews.count.toLocaleString(numberLocale),
                    })}
                </span>
                {reviews.verifiedCount && translations.verified_count ? (
                    <span className="store-reviews-summary__verified">
                        <CheckIcon />
                        {interpolate(translations.verified_count, {
                            count: reviews.verifiedCount.toLocaleString(
                                numberLocale,
                            ),
                        })}
                    </span>
                ) : null}
            </div>
            {distribution.length > 0 ? (
                <ul
                    aria-label={translations.distribution_label}
                    className="store-reviews-summary__bars"
                >
                    {distribution.map((entry) => (
                        <li key={entry.rating}>
                            <span className="store-reviews-summary__bar-label">
                                {entry.rating}
                                <StarIcon filled />
                            </span>
                            <span
                                aria-hidden="true"
                                className="store-reviews-summary__bar"
                            >
                                <span style={{ width: `${entry.percent}%` }} />
                            </span>
                            <span className="store-reviews-summary__bar-value">
                                {entry.percent.toLocaleString(numberLocale)}%
                            </span>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
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
        ? formatPublished(review.publishedAt, locale)
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
                <div
                    aria-label={ratingLabel}
                    className="store-review-card__stars"
                    role="img"
                >
                    {Array.from({ length: 5 }, (_, index) => (
                        <StarIcon filled={index < review.rating} key={index} />
                    ))}
                </div>
                {review.verified ? (
                    <span className="store-review-card__verified">
                        <CheckIcon />
                        {translations.verified}
                    </span>
                ) : null}
            </div>
            <blockquote>
                <span aria-hidden="true" className="store-review-card__quote">
                    &ldquo;
                </span>
                {review.body}
            </blockquote>
            <footer>
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
                {published === null ? null : (
                    <time dateTime={review.publishedAt ?? undefined}>
                        {published}
                    </time>
                )}
            </footer>
        </li>
    );
}

/**
 * "3 days ago" for anything under a year, the plain date beyond that: a
 * relative stamp reads as fresh, an old one as archive, which is the truth.
 */
export function formatPublished(
    iso: string,
    locale: 'ar' | 'en',
    now: Date = new Date(),
): string {
    const date = new Date(iso);
    const days = Math.round(
        (now.getTime() - date.getTime()) / (24 * 60 * 60 * 1000),
    );
    const relative = new Intl.RelativeTimeFormat(
        locale === 'ar' ? 'ar' : 'en',
        { numeric: 'auto' },
    );

    if (days < 0 || days >= 365) {
        return new Intl.DateTimeFormat(DATE_LOCALE, {
            dateStyle: 'medium',
            timeZone: 'UTC',
        }).format(date);
    }

    if (days < 1) {
        return relative.format(0, 'day');
    }

    if (days < 30) {
        return relative.format(-days, 'day');
    }

    return relative.format(-Math.round(days / 30), 'month');
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
            <path d="M12 2.5l2.9 6.1 6.6.8-4.9 4.6 1.3 6.6L12 17.3 6.1 20.6l1.3-6.6L2.5 9.4l6.6-.8z" />
        </svg>
    );
}

function CheckIcon() {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="12"
            stroke="currentColor"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth="2.5"
            viewBox="0 0 24 24"
            width="12"
        >
            <path d="M20 6L9 17l-5-5" />
        </svg>
    );
}

function LocationIcon() {
    return (
        <svg
            aria-hidden="true"
            fill="none"
            height="12"
            stroke="currentColor"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth="2"
            viewBox="0 0 24 24"
            width="12"
        >
            <path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z" />
            <circle cx="12" cy="10" r="2.5" />
        </svg>
    );
}

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

function interpolate(template: string, values: Record<string, string>): string {
    return Object.entries(values).reduce(
        (result, [key, value]) => result.replace(`:${key}`, value),
        template,
    );
}
