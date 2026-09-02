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

        return Inertia::render('store/reviews', [
            'reviews' => $reviews->paginate($locale, (int) ($query['page'] ?? 1), $filters),
            'reviewsPage' => trans('store.reviews'),
            'filters' => $filters,
            'rateUrl' => route(
                $locale === 'en' ? 'localized.account.orders' : 'account.orders',
                $locale === 'en' ? ['locale' => 'en'] : [],
                absolute: false,
            ),
            'seo' => StorePageSeo::default(trans('store.reviews.title'))->toArray(),
        ]);
    }
}
