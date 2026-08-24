<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\DuplicateAdminCoupon;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class DuplicateCouponController extends Controller
{
    public function __construct(
        private readonly DuplicateAdminCoupon $action,
    ) {}

    public function __invoke(Request $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingManage->value);

        $data = $request->validate([
            'code' => ['sometimes', 'nullable', 'string', 'min:3', 'max:24', 'regex:/\A[A-Za-z0-9\-]{3,24}\z/D'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $newCoupon = $this->action->execute($actor, $publicId, $data);

        return response()->json([
            'data' => [
                'id' => $newCoupon->public_id,
                'code' => $newCoupon->code,
                'isActive' => (bool) $newCoupon->is_active,
            ],
        ], 201)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
