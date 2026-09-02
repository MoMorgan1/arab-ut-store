<?php

namespace App\Admin\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type AdminReviewsFilters array{
 *     search?: ?string,
 *     status?: ?string,
 *     rating?: ?int,
 *     source?: ?string,
 *     service?: ?string,
 *     per_page?: int,
 *     page?: int
 * }
 * @phpstan-type AdminReviewRow array{
 *     id: string,
 *     reviewerName: string,
 *     reviewerLocation: ?string,
 *     rating: int,
 *     excerpt: string,
 *     bodyLocale: 'ar'|'en',
 *     order: array{number: string, publicId: string}|null,
 *     source: 'customer'|'archive',
 *     serviceType: ?string,
 *     serviceLabel: string,
 *     isVisible: bool,
 *     publishedAt: ?string,
 *     createdAt: string
 * }
 */
final class ListAdminReviews
{
    /** How much of a comment a list row shows before it is cut. */
    private const EXCERPT_LENGTH = 160;

    /**
     * @param  AdminReviewsFilters  $filters
     * @return array{
     *     reviews: list<AdminReviewRow>,
     *     pagination: array{
     *         currentPage: int,
     *         lastPage: int,
     *         perPage: int,
     *         total: int,
     *         from: ?int,
     *         to: ?int
     *     }
     * }
     */
    public function paginate(array $filters, string $locale = 'ar'): array
    {
        $paginator = $this->filteredQuery($filters)
            ->select([
                'reviews.public_id',
                'reviews.reviewer_name',
                'reviews.reviewer_location',
                'reviews.rating',
                'reviews.body_ar',
                'reviews.body_en',
                'reviews.source',
                'reviews.service_type',
                'reviews.order_id',
                'reviews.is_visible',
                'reviews.published_at',
                'reviews.created_at',
                'orders.order_number as order_number',
                'orders.public_id as order_public_id',
            ])
            ->leftJoin('orders', 'orders.id', '=', 'reviews.order_id')
            ->orderByDesc('reviews.created_at')
            ->orderByDesc('reviews.id')
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? 15),
                page: (int) ($filters['page'] ?? 1),
            );

        $rows = array_values(array_map(
            fn (stdClass $review): stdClass => $review,
            $paginator->items(),
        ));

        return [
            'reviews' => $this->projectReviews($rows, $locale),
            'pagination' => $this->pagination($paginator),
        ];
    }

    /** @param AdminReviewsFilters $filters */
    private function filteredQuery(array $filters): Builder
    {
        $query = DB::table('reviews');

        $this->applySearch($query, $filters['search'] ?? null);

        if (($filters['status'] ?? null) === 'visible') {
            $query->where('reviews.is_visible', true);
        } elseif (($filters['status'] ?? null) === 'hidden') {
            $query->where('reviews.is_visible', false);
        }

        $rating = $filters['rating'] ?? null;

        if (is_int($rating) && $rating >= 1 && $rating <= 5) {
            $query->where('reviews.rating', $rating);
        }

        // "customer" is the only source this store writes; everything else in
        // the table arrived from the Salla archive or the old n8n refresh.
        if (($filters['source'] ?? null) === 'customer') {
            $query->where('reviews.source', 'customer');
        } elseif (($filters['source'] ?? null) === 'archive') {
            $query->where(function (Builder $sources): void {
                $sources->whereNull('reviews.source')
                    ->orWhere('reviews.source', '!=', 'customer');
            });
        }

        $service = $filters['service'] ?? null;

        if (is_string($service) && in_array($service, ['rivals', 'fut_champions', 'sbc', 'objectives'], true)) {
            $query->where('reviews.service_type', $service);
        }

        return $query;
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        $lowercaseSearch = mb_strtolower($search);

        $query->where(function (Builder $reviewQuery) use ($search, $lowercaseSearch): void {
            $reviewQuery->where('reviews.public_id', $search)
                ->orWhereRaw('LOWER(reviews.reviewer_name) LIKE ?', ['%'.$lowercaseSearch.'%'])
                ->orWhereExists(function (Builder $orders) use ($search): void {
                    $orders->select(DB::raw(1))
                        ->from('orders')
                        ->whereColumn('orders.id', 'reviews.order_id')
                        ->where('orders.order_number', 'like', '%'.$search.'%');
                });
        });
    }

    /**
     * @param  list<stdClass>  $reviews
     * @return list<AdminReviewRow>
     */
    private function projectReviews(array $reviews, string $locale): array
    {
        return array_map(function (stdClass $review) use ($locale): array {
            $preferred = $locale === 'en' ? $review->body_en : $review->body_ar;
            $fallback = $locale === 'en' ? $review->body_ar : $review->body_en;
            $body = trim((string) ($preferred ?? $fallback ?? ''));
            $bodyLocale = $preferred !== null && trim((string) $preferred) !== ''
                ? $locale
                : ($review->body_ar !== null && trim((string) $review->body_ar) !== '' ? 'ar' : 'en');

            $serviceType = isset($review->service_type) && trim((string) $review->service_type) !== ''
                ? (string) $review->service_type
                : null;
            $serviceLabel = '—';
            if ($serviceType !== null && in_array($serviceType, ['rivals', 'fut_champions', 'sbc', 'objectives'], true)) {
                $serviceLabel = (string) trans("store.reviews.service_names.{$serviceType}", [], $locale);
            }

            return [
                'id' => (string) $review->public_id,
                'reviewerName' => (string) $review->reviewer_name,
                'reviewerLocation' => $review->reviewer_location !== null
                    ? (string) $review->reviewer_location
                    : null,
                'rating' => (int) $review->rating,
                'excerpt' => mb_strlen($body) > self::EXCERPT_LENGTH
                    ? mb_substr($body, 0, self::EXCERPT_LENGTH).'…'
                    : $body,
                'bodyLocale' => $bodyLocale === 'en' ? 'en' : 'ar',
                'order' => $review->order_id !== null && $review->order_number !== null
                    ? [
                        'number' => (string) $review->order_number,
                        'publicId' => (string) $review->order_public_id,
                    ]
                    : null,
                'source' => $review->source === 'customer' ? 'customer' : 'archive',
                'serviceType' => $serviceType,
                'serviceLabel' => $serviceLabel,
                'isVisible' => (bool) $review->is_visible,
                'publishedAt' => $review->published_at !== null
                    ? Carbon::parse($review->published_at, 'UTC')->utc()->toIso8601String()
                    : null,
                'createdAt' => $review->created_at !== null
                    ? Carbon::parse($review->created_at, 'UTC')->utc()->toIso8601String()
                    : '',
            ];
        }, $reviews);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array{currentPage: int, lastPage: int, perPage: int, total: int, from: ?int, to: ?int}
     */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
