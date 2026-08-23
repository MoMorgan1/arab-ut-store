<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\ProductAuthority;
use App\Enums\UserRole;
use App\Exceptions\AdminProductConflict;
use App\Exceptions\AdminProductNotEditable;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class UpdateAdminProduct
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    /**
     * @param array{
     *     name_ar: string,
     *     name_en: string,
     *     description_ar: string|null,
     *     description_en: string|null,
     *     is_visible: bool,
     *     sort_order: int
     * } $expected The values the caller was shown, refused if the row has moved since.
     * @param  int<0, max>  $sortOrder
     */
    public function execute(
        User $actor,
        string $productPublicId,
        string $nameAr,
        string $nameEn,
        ?string $descriptionAr,
        ?string $descriptionEn,
        bool $isVisible,
        int $sortOrder,
        array $expected,
        ?string $ipAddress = null,
    ): Product {
        if (! $actor->is_active || ! $actor->can(AdminPermission::CatalogManage->value)) {
            throw new AuthorizationException('This action requires catalog.manage permission.');
        }

        if ($actor->role !== UserRole::Admin) {
            throw new AuthorizationException('Only Admin actors may update products.');
        }

        return DB::transaction(function () use (
            $actor,
            $productPublicId,
            $nameAr,
            $nameEn,
            $descriptionAr,
            $descriptionEn,
            $isVisible,
            $sortOrder,
            $expected,
            $ipAddress,
        ): Product {
            /** @var Product $target */
            $target = Product::query()
                ->where('public_id', $productPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($target->authority !== ProductAuthority::Manual) {
                throw new AdminProductNotEditable((string) $target->public_id);
            }

            $previousValues = [
                'name_ar' => (string) $target->name_ar,
                'name_en' => (string) $target->name_en,
                'description_ar' => $target->description_ar !== null ? (string) $target->description_ar : null,
                'description_en' => $target->description_en !== null ? (string) $target->description_en : null,
                'is_visible' => (bool) $target->is_visible,
                'sort_order' => (int) $target->sort_order,
            ];

            if ($previousValues !== $expected) {
                throw new AdminProductConflict((string) $target->public_id, $previousValues);
            }

            $newValues = [
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'description_ar' => $descriptionAr,
                'description_en' => $descriptionEn,
                'is_visible' => $isVisible,
                'sort_order' => $sortOrder,
            ];

            /** @var list<string> $changed */
            $changed = [];
            /** @var array<string, mixed> $previous */
            $previous = [];
            /** @var array<string, mixed> $new */
            $new = [];

            foreach ($newValues as $field => $newValue) {
                if ($previousValues[$field] !== $newValue) {
                    $changed[] = $field;
                    $previous[$field] = $previousValues[$field];
                    $new[$field] = $newValue;
                }
            }

            if (! empty($changed)) {
                $target->name_ar = $nameAr;
                $target->name_en = $nameEn;
                $target->description_ar = $descriptionAr;
                $target->description_en = $descriptionEn;
                $target->is_visible = $isVisible;
                $target->sort_order = $sortOrder;

                $target->save();

                $this->recordStaffAudit->execute(
                    $actor,
                    $target,
                    new StaffAuditEvent(
                        action: 'products.updated',
                        metadata: [
                            'product_changed' => $changed,
                            'product_previous' => $previous,
                            'product_new' => $new,
                        ],
                        ipAddress: $ipAddress,
                    ),
                );
            }

            return $target;
        });
    }
}
