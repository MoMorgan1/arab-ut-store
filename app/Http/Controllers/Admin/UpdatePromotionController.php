<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateAdminPromotion;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminPromotion as UpdatePromotionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class UpdatePromotionController extends Controller
{
    public function __construct(
        private readonly UpdateAdminPromotion $action,
    ) {}

    public function __invoke(UpdatePromotionRequest $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingManage->value);

        $promotion = $this->action->execute($actor, $publicId, $request->validated());

        return response()->json([
            'data' => [
                'id' => $promotion->public_id,
                'nameEn' => $promotion->name_en,
                'isActive' => (bool) $promotion->is_active,
            ],
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
