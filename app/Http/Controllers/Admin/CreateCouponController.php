<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\CreateAdminCoupon;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminCoupon;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CreateCouponController extends Controller
{
    public function __construct(
        private readonly CreateAdminCoupon $action,
    ) {}

    public function __invoke(StoreAdminCoupon $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingManage->value);

        $coupon = $this->action->execute($actor, $request->validated());

        return response()->json([
            'data' => [
                'id' => $coupon->public_id,
                'code' => $coupon->code,
                'isActive' => (bool) $coupon->is_active,
            ],
        ], 201)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
