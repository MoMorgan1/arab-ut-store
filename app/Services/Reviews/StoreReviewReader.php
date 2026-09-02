<?php

namespace App\Services\Reviews;

use App\Models\Review;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Lang;

/**
 * What the storefront may say about its reviews.
 *
 * Only visible, published rows rated four or above ever leave this class, on
 * the home page and on /reviews alike; the summary (average, star
 * distribution, verified count) is computed over that same set, so the
 * numbers a visitor reads always describe the cards they can scroll to.
 */
final class StoreReviewReader
{
    public const PER_PAGE = 12;

    /** @var list<string> */
    public const RATING_FILTERS = ['5', '4'];

    /** @var list<string> */
    public const SORTS = ['newest', 'highest'];

    /**
     * @return array{average: float|null, count: int, distribution: list<array{rating: int, count: int, percent: int}>, verifiedCount: int, items: list<array<string, mixed>>}
     */
    public function homepage(string $locale): array
    {
        $query = $this->visible();
        $items = array_values($this->homepageItems($query, $locale)
            ->map(fn (Review $review) => $this->project($review, $locale))
            ->all());

        return [...$this->summary($query), 'items' => $items];
    }

    /**
     * @param  array{rating?: string|null, verified?: bool, withComment?: bool, sort?: string|null}  $filters
     * @return array{average: float|null, count: int, distribution: list<array{rating: int, count: int, percent: int}>, verifiedCount: int, items: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function paginate(string $locale, int $page, array $filters = []): array
    {
        $summary = $this->summary($this->visible());
        $query = $this->filtered($this->visible(), $filters, $locale);

        if (($filters['sort'] ?? 'newest') === 'highest') {
            $query->reorder()->orderByDesc('rating')->latest('published_at')->latest('id');
        }

        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return [
            ...$summary,
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

    /**
     * @param  Builder<Review>  $query
     * @return array{average: float|null, count: int, distribution: list<array{rating: int, count: int, percent: int}>, verifiedCount: int}
     */
    private function summary(Builder $query): array
    {
        $count = (clone $query)->count();
        $average = $count > 0 ? round((float) (clone $query)->avg('rating'), 1) : null;
        /** @var array<int, int> $byRating */
        $byRating = (clone $query)
            ->reorder()
            ->selectRaw('rating, COUNT(*) AS aggregate')
            ->groupBy('rating')
            ->pluck('aggregate', 'rating')
            ->map(fn ($value): int => (int) $value)
            ->all();
        $distribution = [];

        foreach ([5, 4, 3, 2, 1] as $rating) {
            $ratingCount = $byRating[$rating] ?? 0;
            $distribution[] = [
                'rating' => $rating,
                'count' => $ratingCount,
                'percent' => $count > 0 ? (int) round($ratingCount * 100 / $count) : 0,
            ];
        }

        $verifiedCount = (clone $query)
            ->reorder()
            ->where(fn (Builder $verified) => $verified
                ->whereNotNull('order_id')
                ->orWhereNotNull('order_item_id'))
            ->count();

        return [
            'average' => $average,
            'count' => $count,
            'distribution' => $distribution,
            'verifiedCount' => $verifiedCount,
        ];
    }

    /**
     * @param  Builder<Review>  $query
     * @param  array{rating?: string|null, verified?: bool, withComment?: bool, sort?: string|null}  $filters
     * @return Builder<Review>
     */
    private function filtered(Builder $query, array $filters, string $locale): Builder
    {
        $rating = $filters['rating'] ?? null;

        if (is_string($rating) && in_array($rating, self::RATING_FILTERS, true)) {
            $query->where('rating', (int) $rating);
        }

        if (($filters['verified'] ?? false) === true) {
            $query->where(fn (Builder $verified) => $verified
                ->whereNotNull('order_id')
                ->orWhereNotNull('order_item_id'));
        }

        if (($filters['withComment'] ?? false) === true) {
            [$preferredBody, $fallbackBody] = $this->bodyColumns($locale);
            $query->whereRaw(
                "COALESCE({$preferredBody}, {$fallbackBody}) NOT IN (?, ?)",
                $this->ratingOnlyBodies(),
            )->whereNotNull($locale === 'ar' ? 'body_ar' : 'body_en');
        }

        return $query;
    }

    /** @return Builder<Review> */
    private function visible(): Builder
    {
        return Review::query()
            ->where('is_visible', true)
            ->where('rating', '>=', 4)
            ->whereNotNull('published_at')
            ->latest('published_at')
            ->latest('id');
    }

    /**
     * @param  Builder<Review>  $query
     * @return Collection<int, Review>
     */
    private function homepageItems(Builder $query, string $locale): Collection
    {
        [$preferredBody, $fallbackBody] = $this->bodyColumns($locale);

        return (clone $query)
            ->reorder()
            ->orderByRaw(
                "CASE WHEN COALESCE({$preferredBody}, {$fallbackBody}) IN (?, ?) THEN 1 ELSE 0 END",
                $this->ratingOnlyBodies(),
            )
            ->latest('published_at')
            ->latest('id')
            ->limit(6)
            ->get();
    }

    /** @return array{0: literal-string, 1: literal-string} */
    private function bodyColumns(string $locale): array
    {
        return $locale === 'ar' ? ['body_ar', 'body_en'] : ['body_en', 'body_ar'];
    }

    /** @return array{0: string, 1: string} */
    private function ratingOnlyBodies(): array
    {
        return [
            (string) Lang::get('store.reviews.rating_without_comment', [], 'ar'),
            (string) Lang::get('store.reviews.rating_without_comment', [], 'en'),
        ];
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
        $body = $locale === 'ar'
            ? ($review->body_ar ?? $review->body_en)
            : ($review->body_en ?? $review->body_ar);

        return [
            'id' => $review->public_id,
            'reviewerName' => $name,
            'reviewerLocation' => $review->reviewer_location,
            'rating' => $review->rating,
            'body' => $body,
            'hasComment' => $body !== null && ! in_array($body, $this->ratingOnlyBodies(), true),
            'verified' => $review->order_id !== null || $review->order_item_id !== null,
            'publishedAt' => $publishedAt?->toAtomString(),
        ];
    }
}
