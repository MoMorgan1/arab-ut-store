<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminCouponDetailPage;
use App\Admin\Queries\ReadAdminCouponPerformance;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PublicHandle\CouponHandle;
use Illuminate\Http\RedirectResponse;
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

    public function __invoke(Request $request, string $coupon): Response|RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingView->value);

        $target = CouponHandle::resolveForAdmin($coupon);

        // A request addressed by the internal ULID is sent to the code URL. A
        // code in any casing resolves in place; only the legacy ULID redirects.
        if (CouponHandle::isUlid($coupon)) {
            $redirect = CouponHandle::legacyRedirect($request, $target);

            if ($redirect instanceof RedirectResponse) {
                return $redirect;
            }
        }

        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';
        $result = $this->couponPerformanceQuery->forCoupon($target, $locale);

        return Inertia::render('admin/marketing/coupons/show', [
            'auth' => null,
            ...$this->page->for($actor, $locale, $result),
        ]);
    }
}
