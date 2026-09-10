<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateAdminProduct;
use App\Enums\AdminPermission;
use App\Exceptions\AdminProductConflict;
use App\Exceptions\AdminProductNotEditable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminProduct as UpdateProductRequest;
use App\Models\User;
use App\Support\PublicHandle\ProductHandle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class ProductController extends Controller
{
    public function __construct(
        private readonly UpdateAdminProduct $action,
    ) {}

    public function __invoke(UpdateProductRequest $request, string $product): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::CatalogManage->value);

        $target = ProductHandle::resolveForAdmin($product);
        $publicId = (string) $target->public_id;

        try {
            $productModel = $this->action->execute(
                actor: $actor,
                productPublicId: $publicId,
                nameAr: $request->nameAr(),
                nameEn: $request->nameEn(),
                descriptionAr: $request->descriptionAr(),
                descriptionEn: $request->descriptionEn(),
                isVisible: $request->isVisible(),
                sortOrder: $request->sortOrder(),
                expected: $request->expected(),
                ipAddress: $request->ip(),
            );
        } catch (AdminProductNotEditable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'product_not_editable',
                'product' => $exception->productPublicId,
            ], 422)
                ->header('Cache-Control', 'no-store, private')
                ->header('Content-Type', 'application/json');
        } catch (AdminProductConflict $exception) {
            return response()->json([
                'product' => $exception->productPublicId,
                'current' => [
                    'nameAr' => $exception->current['name_ar'],
                    'nameEn' => $exception->current['name_en'],
                    'descriptionAr' => $exception->current['description_ar'],
                    'descriptionEn' => $exception->current['description_en'],
                    'isVisible' => $exception->current['is_visible'],
                    'sortOrder' => $exception->current['sort_order'],
                ],
            ], 409)
                ->header('Cache-Control', 'no-store, private')
                ->header('Content-Type', 'application/json');
        }

        return response()->json([
            'data' => [
                'nameAr' => (string) $productModel->name_ar,
                'nameEn' => (string) $productModel->name_en,
                'descriptionAr' => $productModel->description_ar !== null ? (string) $productModel->description_ar : null,
                'descriptionEn' => $productModel->description_en !== null ? (string) $productModel->description_en : null,
                'isVisible' => (bool) $productModel->is_visible,
                'sortOrder' => (int) $productModel->sort_order,
                'updatedAt' => $productModel->updated_at?->utc()->toIso8601String() ?? now()->utc()->toIso8601String(),
            ],
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
