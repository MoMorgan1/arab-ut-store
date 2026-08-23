<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateAdminCoupon;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminCoupon as UpdateCouponRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class UpdateCouponController extends Controller
{
    public function __construct(
        private readonly UpdateAdminCoupon $action,
    ) {}

    public function __invoke(UpdateCouponRequest $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingManage->value);

        $coupon = $this->action->execute($actor, $publicId, $request->validated());

        return response()->json([
            'data' => [
                'id' => $coupon->public_id,
                'code' => $coupon->code,
                'isActive' => (bool) $coupon->is_active,
            ],
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
