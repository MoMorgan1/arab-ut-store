<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateAdminCoupon;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminCoupon as UpdateCouponRequest;
use App\Models\User;
use App\Support\PublicHandle\CouponHandle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class UpdateCouponController extends Controller
{
    public function __construct(
        private readonly UpdateAdminCoupon $action,
    ) {}

    public function __invoke(UpdateCouponRequest $request, string $coupon): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingManage->value);

        $target = CouponHandle::resolveForAdmin($coupon);

        $updated = $this->action->execute($actor, (string) $target->public_id, $request->validated());

        return response()->json([
            'data' => [
                'id' => $updated->public_id,
                'code' => $updated->code,
                'isActive' => (bool) $updated->is_active,
                'url' => CouponHandle::adminDetailUrl($request, $updated),
            ],
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
