import { useForm } from '@inertiajs/react';
import { ShieldCheck, Star } from 'lucide-react';
import { useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';

import type {
    AccountLiveOrderPageProps,
    AccountTranslations,
} from '@/types/account';

const MAX_BODY_LENGTH = 600;
const RATINGS = [1, 2, 3, 4, 5] as const;

type OrderReview = NonNullable<AccountLiveOrderPageProps['order']['review']>;
type ReviewTranslations = AccountTranslations['orders']['review'];

export type OrderReviewCardProps = {
    customerName: string;
    locale: 'ar' | 'en';
    review: OrderReview;
    translations: ReviewTranslations;
};

export default function OrderReviewCard({
    customerName,
    locale,
    review,
    translations,
}: OrderReviewCardProps) {
    if (review.submitted !== null) {
        return (
            <SubmittedReview
                customerName={customerName}
                submitted={review.submitted}
                translations={translations}
            />
        );
    }

    return (
        <ReviewForm
            locale={locale}
            translations={translations}
            url={review.url}
        />
    );
}

function ReviewForm({
    locale,
    translations,
    url,
}: {
    locale: 'ar' | 'en';
    translations: ReviewTranslations;
    url: string;
}) {
    const form = useForm({ body: '', rating: 0 });
    const [focusedRating, setFocusedRating] = useState(1);
    const starRefs = useRef<Array<HTMLButtonElement | null>>([]);

    // The visual order of the stars follows the writing direction, so
    // "the arrow key that points at the next star" does too.
    const forward = locale === 'ar' ? 'ArrowLeft' : 'ArrowRight';
    const backward = locale === 'ar' ? 'ArrowRight' : 'ArrowLeft';

    function moveFocus(next: number) {
        const clamped = Math.min(RATINGS.length, Math.max(1, next));
        setFocusedRating(clamped);
        starRefs.current[clamped - 1]?.focus();
    }

    function handleStarKeyDown(
        event: KeyboardEvent<HTMLButtonElement>,
        rating: number,
    ) {
        if (event.key === forward || event.key === 'ArrowDown') {
            event.preventDefault();
            moveFocus(rating + 1);
        } else if (event.key === backward || event.key === 'ArrowUp') {
            event.preventDefault();
            moveFocus(rating - 1);
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            form.setData('rating', rating);
        }
    }

    function submit() {
        if (form.data.rating === 0 || form.processing) {
            return;
        }

        form.post(url, { preserveScroll: true });
    }

    const activeRating = form.data.rating;
    const tabbableRating = activeRating === 0 ? focusedRating : activeRating;

    return (
        <section
            aria-labelledby="account-order-review-title"
            className="account-order-review"
        >
            <h2
                className="account-order-review__title"
                id="account-order-review-title"
            >
                {translations.title}
            </h2>
            <p className="account-order-review__helper">
                {translations.helper}
            </p>

            <div
                aria-label={translations.rating_label}
                className="account-order-review__stars"
                role="radiogroup"
            >
                {RATINGS.map((rating) => (
                    <button
                        aria-checked={activeRating === rating}
                        aria-label={translations.star_label.replace(
                            ':rating',
                            String(rating),
                        )}
                        className="account-order-review__star"
                        data-filled={activeRating >= rating ? 'true' : 'false'}
                        key={rating}
                        onClick={() => {
                            form.setData('rating', rating);
                            setFocusedRating(rating);
                        }}
                        onKeyDown={(event) => handleStarKeyDown(event, rating)}
                        ref={(element) => {
                            starRefs.current[rating - 1] = element;
                        }}
                        role="radio"
                        tabIndex={tabbableRating === rating ? 0 : -1}
                        type="button"
                    >
                        <Star aria-hidden="true" />
                    </button>
                ))}
            </div>
            <p className="account-order-review__rating-value">
                {translations.rating_value.replace(
                    ':rating',
                    String(activeRating),
                )}
            </p>

            <label
                className="account-order-review__comment-label"
                htmlFor="account-order-review-body"
            >
                {translations.comment_label}
            </label>
            <textarea
                className="account-order-review__comment"
                id="account-order-review-body"
                maxLength={MAX_BODY_LENGTH}
                onChange={(event) => form.setData('body', event.target.value)}
                placeholder={translations.comment_placeholder}
                rows={4}
                value={form.data.body}
            />
            <p className="account-order-review__counter">
                <bdi>
                    {translations.counter
                        .replace(':count', String(form.data.body.length))
                        .replace(':max', String(MAX_BODY_LENGTH))}
                </bdi>
            </p>

            {form.errors.rating ? (
                <p className="account-order-review__error" role="alert">
                    {form.errors.rating}
                </p>
            ) : null}

            <button
                className="account-order-review__submit"
                disabled={activeRating === 0 || form.processing}
                onClick={submit}
                type="button"
            >
                {form.processing
                    ? translations.submitting
                    : translations.submit}
            </button>
        </section>
    );
}

function SubmittedReview({
    customerName,
    submitted,
    translations,
}: {
    customerName: string;
    submitted: NonNullable<OrderReview['submitted']>;
    translations: ReviewTranslations;
}) {
    const firstName = customerName.trim().split(/\s+/)[0] ?? '';
    const thanks = (
        submitted.visible
            ? translations.thanks_visible
            : translations.thanks_hidden
    ).replace(':name', firstName);

    return (
        <section
            aria-labelledby="account-order-review-title"
            className="account-order-review account-order-review--submitted"
        >
            <h2
                className="account-order-review__title"
                id="account-order-review-title"
            >
                {translations.submitted_title}
            </h2>

            <p
                aria-label={translations.rating_value.replace(
                    ':rating',
                    String(submitted.rating),
                )}
                className="account-order-review__stars account-order-review__stars--static"
            >
                {RATINGS.map((rating) => (
                    <span
                        className="account-order-review__star"
                        data-filled={
                            submitted.rating >= rating ? 'true' : 'false'
                        }
                        key={rating}
                    >
                        <Star aria-hidden="true" />
                    </span>
                ))}
            </p>

            {submitted.body ? (
                <blockquote className="account-order-review__quote">
                    {submitted.body}
                </blockquote>
            ) : null}

            <p className="account-order-review__badge">
                <ShieldCheck aria-hidden="true" />
                {translations.verified_badge}
            </p>
            <p className="account-order-review__thanks">{thanks}</p>
        </section>
    );
}
