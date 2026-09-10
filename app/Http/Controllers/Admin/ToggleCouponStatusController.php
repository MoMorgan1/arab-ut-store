<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\ToggleAdminCouponStatus;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ToggleAdminCouponStatus as ToggleCouponRequest;
use App\Models\User;
use App\Support\PublicHandle\CouponHandle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class ToggleCouponStatusController extends Controller
{
    public function __construct(
        private readonly ToggleAdminCouponStatus $action,
    ) {}

    public function __invoke(ToggleCouponRequest $request, string $coupon): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingManage->value);

        $target = CouponHandle::resolveForAdmin($coupon);

        $updated = $this->action->execute($actor, (string) $target->public_id, $request->boolean('is_active'));

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
