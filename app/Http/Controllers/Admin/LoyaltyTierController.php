<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateLoyaltyTier;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminLoyaltyTier;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class LoyaltyTierController extends Controller
{
    public function __construct(
        private readonly UpdateLoyaltyTier $action,
    ) {}

    public function __invoke(UpdateAdminLoyaltyTier $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::LoyaltyManage->value);

        $tier = $this->action->execute(
            actor: $actor,
            publicId: $publicId,
            nameAr: $request->nameAr(),
            nameEn: $request->nameEn(),
            minimumSpendHalalah: $request->minimumLifetimeSpendHalalah(),
            cashbackBasisPoints: $request->cashbackBasisPoints(),
            isActive: $request->isActive(),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'data' => [
                'id' => (string) $tier->public_id,
                'key' => (string) $tier->key,
                'nameAr' => (string) $tier->name_ar,
                'nameEn' => (string) $tier->name_en,
                'rank' => (int) $tier->rank,
                'minimumLifetimeSpend' => [
                    'amountMinor' => (string) $tier->minimum_lifetime_spend_halalah,
                    'currency' => 'SAR',
                ],
                'cashbackBasisPoints' => (int) $tier->cashback_basis_points,
                'cashbackPercent' => number_format((float) ($tier->cashback_basis_points / 100), 1).'%',
                'isActive' => (bool) $tier->is_active,
                'updatedAt' => $tier->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            ],
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
