<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Reviews\StoreReviewReader;
use App\Support\Seo\StorePageSeo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

final class ReviewsController extends Controller
{
    public function __invoke(Request $request, StoreReviewReader $reviews): Response
    {
        $query = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        return Inertia::render('store/reviews', [
            'reviews' => $reviews->paginate(app()->getLocale(), (int) ($query['page'] ?? 1)),
            'reviewsPage' => trans('store.reviews'),
            'seo' => StorePageSeo::default(trans('store.reviews.title'))->toArray(),
        ]);
    }
}
