<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\SetAdminProductStorefrontVisibility;
use App\Enums\AdminPermission;
use App\Exceptions\AdminProductVisibilityConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminProductVisibility;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class ProductVisibilityController extends Controller
{
    public function __construct(
        private readonly SetAdminProductStorefrontVisibility $action,
    ) {}

    public function __invoke(SetAdminProductVisibility $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::CatalogManage->value);

        try {
            $product = $this->action->execute(
                actor: $actor,
                productPublicId: $publicId,
                hidden: $request->hidden(),
                expectedHidden: $request->expectedHidden(),
                ipAddress: $request->ip(),
            );
        } catch (AdminProductVisibilityConflict $exception) {
            return response()->json([
                'product' => $exception->productPublicId,
                'current' => ['adminHidden' => $exception->currentHidden],
            ], 409)
                ->header('Cache-Control', 'no-store, private')
                ->header('Content-Type', 'application/json');
        }

        return response()->json([
            'product' => (string) $product->public_id,
            'adminHidden' => $product->admin_hidden_at !== null,
        ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
