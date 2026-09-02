<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ListAdminReviews;
use App\Models\User;

final readonly class AdminReviewsPage
{
    public function __construct(
        private AdminShell $shell,
        private ListAdminReviews $reviewsQuery,
    ) {}

    /**
     * @param array{
     *     search: ?string,
     *     status: 'all'|'visible'|'hidden',
     *     rating: 'all'|'5'|'4'|'3'|'2'|'1',
     *     source: 'all'|'customer'|'archive',
     *     service?: 'all'|'rivals'|'fut_champions'|'sbc'|'objectives'|string,
     *     per_page: int,
     *     page: int
     * } $filters
     * @return array<string, mixed>
     */
    public function for(User $actor, string $locale, array $filters): array
    {
        $reviewData = $this->reviewsQuery->paginate([
            'search' => $filters['search'],
            'status' => $filters['status'] === 'all' ? null : $filters['status'],
            'rating' => $filters['rating'] === 'all' ? null : (int) $filters['rating'],
            'source' => $filters['source'] === 'all' ? null : $filters['source'],
            'service' => ($filters['service'] ?? 'all') === 'all' ? null : $filters['service'],
            'per_page' => $filters['per_page'],
            'page' => $filters['page'],
        ], $locale);

        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'reviews' => $reviewData['reviews'],
            'pagination' => $reviewData['pagination'],
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => [
                    ['value' => 'all', 'label' => (string) trans('admin.reviews.filterStatusAll', locale: $locale)],
                    ['value' => 'visible', 'label' => (string) trans('admin.reviews.stateVisible', locale: $locale)],
                    ['value' => 'hidden', 'label' => (string) trans('admin.reviews.stateHidden', locale: $locale)],
                ],
                'ratings' => [
                    ['value' => 'all', 'label' => (string) trans('admin.reviews.filterRatingAll', locale: $locale)],
                    ...array_map(fn (int $rating): array => [
                        'value' => (string) $rating,
                        'label' => (string) trans('admin.reviews.ratingValue', ['rating' => $rating], $locale),
                    ], [5, 4, 3, 2, 1]),
                ],
                'sources' => [
                    ['value' => 'all', 'label' => (string) trans('admin.reviews.filterSourceAll', locale: $locale)],
                    ['value' => 'customer', 'label' => (string) trans('admin.reviews.sourceCustomer', locale: $locale)],
                    ['value' => 'archive', 'label' => (string) trans('admin.reviews.sourceArchive', locale: $locale)],
                ],
                'services' => [
                    ['value' => 'all', 'label' => (string) trans('admin.reviews.filterServiceAll', locale: $locale)],
                    ['value' => 'rivals', 'label' => (string) trans('store.reviews.service_names.rivals', locale: $locale)],
                    ['value' => 'fut_champions', 'label' => (string) trans('store.reviews.service_names.fut_champions', locale: $locale)],
                    ['value' => 'sbc', 'label' => (string) trans('store.reviews.service_names.sbc', locale: $locale)],
                    ['value' => 'objectives', 'label' => (string) trans('store.reviews.service_names.objectives', locale: $locale)],
                ],
                'perPageOptions' => [15, 25, 50, 100],
            ],
            'orderUrlTemplate' => route($prefix.'orders.show', ['publicId' => '__ID__'], absolute: false),
            'visibilityUrlTemplate' => route($prefix.'reviews.visibility.store', ['publicId' => '__ID__'], absolute: false),
        ];
    }
}
