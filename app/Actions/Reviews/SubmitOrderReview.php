<?php

namespace App\Actions\Reviews;

use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The customer's own verdict on a finished order.
 *
 * The review is built field by field and never from the request array:
 * `DomainModel` guards only `id` and `public_id`, so a posted `is_visible` or
 * `published_at` would otherwise be mass assignable and a one-star review could
 * publish itself.
 */
final class SubmitOrderReview
{
    public function execute(User $user, string $orderPublicId, int $rating, ?string $body): Review
    {
        /** @var Order $order */
        $order = Order::query()
            ->where('public_id', $orderPublicId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($order->completed_at === null || $order->channel === 'salla_import') {
            throw ValidationException::withMessages([
                'rating' => [(string) trans('account.orders.review.not_eligible')],
            ]);
        }

        try {
            return DB::transaction(function () use ($order, $user, $rating, $body): Review {
                if (Review::query()->where('order_id', $order->id)->exists()) {
                    throw $this->alreadyReviewed();
                }

                return $this->store($order, $user, $rating, $body);
            });
        } catch (UniqueConstraintViolationException) {
            // The unique index on reviews.order_id is the second line of
            // defence: two submissions racing each other both pass the check
            // above, and only one of them may reach the storefront.
            throw $this->alreadyReviewed();
        }
    }

    private function store(Order $order, User $user, int $rating, ?string $body): Review
    {
        $locale = $order->locale === 'en' ? 'en' : 'ar';
        $name = trim((string) $user->getAttribute('first_name'));
        $visible = $rating >= 4;
        // A blank body would render an empty card on the storefront and break
        // the reader's rating-only ordering, so it stores the same placeholder
        // the Salla importer uses.
        $text = $body ?? (string) trans('store.reviews.rating_without_comment', [], $locale);

        $review = new Review;
        $review->forceFill([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'reviewer_name' => $name !== ''
                ? $name
                : (string) trans('store.reviews.anonymous_customer', [], $locale),
            'rating' => $rating,
            'body_ar' => $locale === 'ar' ? $text : null,
            'body_en' => $locale === 'en' ? $text : null,
            'source' => 'customer',
            'is_visible' => $visible,
            'published_at' => $visible ? now() : null,
        ])->save();

        return $review;
    }

    private function alreadyReviewed(): ValidationException
    {
        return ValidationException::withMessages([
            'rating' => [(string) trans('account.orders.review.already_reviewed')],
        ]);
    }
}
