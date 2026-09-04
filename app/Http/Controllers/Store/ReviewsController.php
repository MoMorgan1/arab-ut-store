<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Reviews\StoreReviewReader;
use App\Support\Seo\StorePageSeo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ReviewsController extends Controller
{
    public function __invoke(Request $request, StoreReviewReader $reviews): Response
    {
        $query = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'service' => ['sometimes', Rule::in(['rivals', 'fut_champions', 'sbc', 'objectives'])],
            'rating' => ['sometimes', Rule::in(StoreReviewReader::RATING_FILTERS)],
            'verified' => ['sometimes', Rule::in(['1'])],
            'comment' => ['sometimes', Rule::in(['1'])],
            'sort' => ['sometimes', Rule::in(StoreReviewReader::SORTS)],
        ])->validate();

        $filters = [
            'service' => $query['service'] ?? null,
            'rating' => $query['rating'] ?? null,
            'verified' => ($query['verified'] ?? null) === '1',
            'withComment' => ($query['comment'] ?? null) === '1',
            'sort' => $query['sort'] ?? 'newest',
        ];
        $locale = app()->getLocale();

        // The summary in the page result ignores the rating/verified/comment
        // filters and is scoped only by service, so the schema always matches
        // what the page shows; a service filter moves the rating onto a
        // Service node so the store-wide node never changes per URL.
        $page = $reviews->paginate($locale, (int) ($query['page'] ?? 1), $filters);
        $seo = StorePageSeo::default(trans('store.reviews.title'));

        if ($filters['service'] !== null) {
            $seo = $seo->withService((string) trans("store.reviews.service_names.{$filters['service']}"), null);
        }

        return Inertia::render('store/reviews', [
            'reviews' => $page,
            'reviewsPage' => trans('store.reviews'),
            'filters' => $filters,
            'rateUrl' => route(
                $locale === 'en' ? 'localized.account.orders' : 'account.orders',
                $locale === 'en' ? ['locale' => 'en'] : [],
                absolute: false,
            ),
            'seo' => $seo
                ->withRating($page['average'], $page['count'])
                ->withReviews($page['items'])
                ->toArray(),
        ]);
    }
}
