<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminReviewsPage;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminReviews;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ReviewsController extends Controller
{
    public function __construct(private readonly AdminReviewsPage $page) {}

    public function __invoke(ListAdminReviews $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingView->value);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        return Inertia::render('admin/reviews/index', [
            'auth' => null,
            ...$this->page->for($actor, $locale, $request->normalizedFilters()),
        ]);
    }
}
