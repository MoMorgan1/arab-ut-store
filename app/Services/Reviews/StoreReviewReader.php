<?php

namespace App\Services\Reviews;

use App\Models\Review;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;

final class StoreReviewReader
{
    /** @return array{average: float|null, count: int, items: list<array<string, mixed>>} */
    public function homepage(string $locale): array
    {
        $query = $this->visible();
        $count = (clone $query)->count();
        $average = $count > 0 ? round((float) (clone $query)->avg('rating'), 1) : null;
        $items = array_values($query->limit(6)->get()
            ->map(fn (Review $review) => $this->project($review, $locale))
            ->all());

        return ['average' => $average, 'count' => $count, 'items' => $items];
    }

    /** @return array{average: float|null, count: int, items: list<array<string, mixed>>, pagination: array<string, int>} */
    public function paginate(string $locale, int $page): array
    {
        $base = $this->visible();
        $count = (clone $base)->count();
        $average = $count > 0 ? round((float) (clone $base)->avg('rating'), 1) : null;
        $paginator = $base->paginate(12, ['*'], 'page', $page);

        return [
            'average' => $average,
            'count' => $count,
            'items' => array_values(collect($paginator->items())
                ->map(fn (Review $review) => $this->project($review, $locale))
                ->all()),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return Builder<Review> */
    private function visible(): Builder
    {
        return Review::query()
            ->where('is_visible', true)
            ->whereNotNull('published_at')
            ->latest('published_at')
            ->latest('id');
    }

    /** @return array<string, mixed> */
    private function project(Review $review, string $locale): array
    {
        $genericNames = [
            Lang::get('store.reviews.anonymous_customer', [], 'ar'),
            Lang::get('store.reviews.anonymous_customer', [], 'en'),
        ];
        $name = in_array($review->reviewer_name, $genericNames, true)
            ? Lang::get('store.reviews.anonymous_customer', [], $locale)
            : $review->reviewer_name;
        $publishedAt = CarbonImmutable::make($review->getRawOriginal('published_at'));

        return [
            'id' => $review->public_id,
            'reviewerName' => $name,
            'rating' => $review->rating,
            'body' => $locale === 'ar'
                ? ($review->body_ar ?? $review->body_en)
                : ($review->body_en ?? $review->body_ar),
            'verified' => $review->order_item_id !== null,
            'publishedAt' => $publishedAt?->toAtomString(),
        ];
    }
}
