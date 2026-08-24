<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminCouponDetailPage;
use App\Admin\Queries\ReadAdminCouponPerformance;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CouponDetailController extends Controller
{
    public function __construct(
        private readonly ReadAdminCouponPerformance $couponPerformanceQuery,
        private readonly AdminCouponDetailPage $page,
    ) {}

    public function __invoke(Request $request, string $publicId): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingView->value);

        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';
        $result = $this->couponPerformanceQuery->findByPublicId($publicId, $locale);
        abort_if($result === null, 404);

        return Inertia::render('admin/marketing/coupons/show', [
            'auth' => null,
            ...$this->page->for($actor, $locale, $result),
        ]);
    }
}
