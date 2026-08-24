<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\SetAdminCategoryStorefrontVisibility;
use App\Enums\AdminPermission;
use App\Exceptions\AdminCategoryVisibilityConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminCategoryVisibility;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CategoryVisibilityController extends Controller
{
    public function __construct(
        private readonly SetAdminCategoryStorefrontVisibility $action,
    ) {}

    public function __invoke(SetAdminCategoryVisibility $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::CatalogManage->value);

        try {
            $category = $this->action->execute(
                actor: $actor,
                categoryPublicId: $publicId,
                hidden: $request->hidden(),
                expectedHidden: $request->expectedHidden(),
                ipAddress: $request->ip(),
            );
        } catch (AdminCategoryVisibilityConflict $exception) {
            return response()->json([
                'category' => $exception->categoryPublicId,
                'current' => ['adminHidden' => $exception->currentHidden],
            ], 409)
                ->header('Cache-Control', 'no-store, private')
                ->header('Content-Type', 'application/json');
        }

        return response()->json([
            'category' => (string) $category->public_id,
            'adminHidden' => $category->admin_hidden_at !== null,
        ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
