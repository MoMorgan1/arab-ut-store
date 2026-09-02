<?php

namespace App\Services\Reviews;

use App\Enums\ServiceType;
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
 * With a service filter, the summary is scoped to that service; it stays
 * unscoped by the rating / verified / comment filters.
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
        $summary = $this->summary($query);
        unset($summary['commentedCount']);

        return [...$summary, 'items' => $items];
    }

    /**
     * @return array{average: float|null, count: int, distribution: list<array{rating: int, count: int, percent: int}>, verifiedCount: int, items: list<array<string, mixed>>}|null
     */
    public function service(ServiceType $service, string $locale): ?array
    {
        $query = $this->visible($service->value);
        $summary = $this->summary($query);
        $commentedCount = $summary['commentedCount'];
        unset($summary['commentedCount']);

        if ($commentedCount < 3) {
            return null;
        }

        $items = array_values($this->homepageItems($query, $locale)
            ->map(fn (Review $review) => $this->project($review, $locale))
            ->all());

        return [...$summary, 'items' => $items];
    }

    /**
     * @param  array{rating?: string|null, verified?: bool, withComment?: bool, sort?: string|null, service?: string|null}  $filters
     * @return array{average: float|null, count: int, distribution: list<array{rating: int, count: int, percent: int}>, verifiedCount: int, items: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function paginate(string $locale, int $page, array $filters = []): array
    {
        $service = $filters['service'] ?? null;
        $summary = $this->summary($this->visible($service));
        unset($summary['commentedCount']);
        $query = $this->filtered($this->visible($service), $filters, $locale);

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
     * @return array{average: float|null, count: int, distribution: list<array{rating: int, count: int, percent: int}>, verifiedCount: int, commentedCount: int}
     */
    private function summary(Builder $query): array
    {
        // One grouped query feeds the whole summary; the homepage has a
        // query budget and every extra round trip here is paid on every visit.
        /** @var list<object{rating: int|string, aggregate: int|string, verified: int|string|null, commented: int|string|null}> $rows */
        $rows = (clone $query)
            ->reorder()
            ->selectRaw(
                'rating, COUNT(*) AS aggregate, '
                .'SUM(CASE WHEN order_id IS NOT NULL OR order_item_id IS NOT NULL THEN 1 ELSE 0 END) AS verified, '
                .'SUM(CASE WHEN COALESCE(body_ar, body_en) NOT IN (?, ?) THEN 1 ELSE 0 END) AS commented',
                $this->ratingOnlyBodies(),
            )
            ->groupBy('rating')
            ->get()
            ->all();
        $byRating = [];
        $count = 0;
        $weighted = 0;
        $verifiedCount = 0;
        $commentedCount = 0;

        foreach ($rows as $row) {
            $rating = (int) $row->rating;
            $aggregate = (int) $row->aggregate;
            $byRating[$rating] = $aggregate;
            $count += $aggregate;
            $weighted += $rating * $aggregate;
            $verifiedCount += (int) ($row->verified ?? 0);
            $commentedCount += (int) ($row->commented ?? 0);
        }

        $distribution = [];

        foreach ([5, 4, 3, 2, 1] as $rating) {
            $ratingCount = $byRating[$rating] ?? 0;
            $distribution[] = [
                'rating' => $rating,
                'count' => $ratingCount,
                'percent' => $count > 0 ? (int) round($ratingCount * 100 / $count) : 0,
            ];
        }

        return [
            'average' => $count > 0 ? round($weighted / $count, 1) : null,
            'count' => $count,
            'distribution' => $distribution,
            'verifiedCount' => $verifiedCount,
            'commentedCount' => $commentedCount,
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
    private function visible(?string $service = null): Builder
    {
        $query = Review::query()
            ->where('is_visible', true)
            ->where('rating', '>=', 4)
            ->whereNotNull('published_at');

        if ($service !== null) {
            $query->where('service_type', $service);
        }

        return $query
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
