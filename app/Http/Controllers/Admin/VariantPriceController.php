<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\SetAdminVariantPriceOverride;
use App\Enums\AdminPermission;
use App\Exceptions\AdminVariantPriceConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminVariantPrice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class VariantPriceController extends Controller
{
    public function __construct(
        private readonly SetAdminVariantPriceOverride $action,
    ) {}

    public function __invoke(SetAdminVariantPrice $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::CatalogManage->value);

        try {
            $variant = $this->action->execute(
                actor: $actor,
                variantPublicId: $publicId,
                priceHalalah: $request->priceHalalah(),
                completionPricing: $request->completionPricing(),
                expectedPriceVersion: $request->expectedPriceVersion(),
                ipAddress: $request->ip(),
            );
        } catch (AdminVariantPriceConflict $exception) {
            return response()->json([
                'variant' => $exception->variantPublicId,
                'current' => [
                    'priceVersion' => $exception->currentPriceVersion,
                    'effectivePriceHalalah' => $exception->currentEffectivePriceHalalah,
                ],
            ], 409)
                ->header('Cache-Control', 'no-store, private')
                ->header('Content-Type', 'application/json');
        }

        return response()->json([
            'variant' => (string) $variant->public_id,
            'priceVersion' => (int) $variant->price_version,
            'effectivePriceHalalah' => $variant->effectivePriceHalalah(),
            'hasOverride' => $variant->hasAdminPriceOverride(),
        ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
