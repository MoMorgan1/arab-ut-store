<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionComponent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateAdminPromotion
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, array $data): Promotion
    {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        return DB::transaction(function () use ($actor, $data): Promotion {
            $mechanic = (string) ($data['mechanic'] ?? Promotion::MECHANIC_ITEM);
            $discountType = (string) ($data['discount_type'] ?? ($mechanic === Promotion::MECHANIC_BUNDLE ? 'fixed' : 'percent'));
            $value = isset($data['value']) ? (int) $data['value'] : 0;

            $promotion = Promotion::create([
                'public_id' => (string) Str::ulid(),
                'name_ar' => (string) $data['name_ar'],
                'name_en' => (string) $data['name_en'],
                'badge_ar' => $data['badge_ar'] ?? null,
                'badge_en' => $data['badge_en'] ?? null,
                'mechanic' => $mechanic,
                'scope' => (string) ($data['scope'] ?? Promotion::SCOPE_ALL),
                'category_id' => $this->categoryId($data),
                'service_type' => isset($data['service_type']) && is_string($data['service_type'])
                    ? $data['service_type']
                    : null,
                'discount_type' => $discountType,
                'value' => $value,
                'buy_quantity' => isset($data['buy_quantity']) && $data['buy_quantity'] !== '' ? (int) $data['buy_quantity'] : null,
                'get_quantity' => isset($data['get_quantity']) && $data['get_quantity'] !== '' ? (int) $data['get_quantity'] : null,
                'max_applications' => isset($data['max_applications']) && $data['max_applications'] !== '' ? (int) $data['max_applications'] : null,
                'discount_target' => isset($data['discount_target']) && is_string($data['discount_target'])
                    ? $data['discount_target']
                    : Promotion::TARGET_CHEAPEST,
                'qualifying_scope' => isset($data['qualifying_scope']) && is_string($data['qualifying_scope'])
                    ? $data['qualifying_scope']
                    : null,
                'bundle_price_halalah' => isset($data['bundle_price_halalah']) && $data['bundle_price_halalah'] !== '' ? (int) $data['bundle_price_halalah'] : null,
                'applies_to_promoted_items' => (bool) ($data['applies_to_promoted_items'] ?? false),
                'starts_at' => isset($data['starts_at']) ? Carbon::parse((string) $data['starts_at'])->utc() : null,
                'ends_at' => isset($data['ends_at']) ? Carbon::parse((string) $data['ends_at'])->utc() : null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            if ($mechanic === Promotion::MECHANIC_BUNDLE && ! empty($data['components']) && is_array($data['components'])) {
                foreach ($data['components'] as $componentData) {
                    $rawProductId = $componentData['product_id'] ?? $componentData['product'] ?? null;
                    $resolvedProductId = $this->resolveProductId($rawProductId);

                    if ($resolvedProductId !== null) {
                        PromotionComponent::create([
                            'public_id' => (string) Str::ulid(),
                            'promotion_id' => $promotion->id,
                            'product_id' => $resolvedProductId,
                            'quantity' => isset($componentData['quantity']) ? max(1, (int) $componentData['quantity']) : 1,
                        ]);
                    }
                }
            }

            $this->recordStaffAudit->execute(
                $actor,
                null,
                new StaffAuditEvent(
                    action: 'promotions.created',
                    metadata: [
                        'promotion_public_id' => $promotion->public_id,
                        'mechanic' => $promotion->mechanic,
                        'scope' => $promotion->scope,
                        'discount_type' => $promotion->discount_type,
                        'value' => $promotion->value,
                        'bundle_price_halalah' => $promotion->bundle_price_halalah,
                    ],
                    ipAddress: request()->ip(),
                ),
            );

            return $promotion;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function categoryId(array $data): ?int
    {
        if (($data['scope'] ?? null) !== Promotion::SCOPE_CATEGORY
            || ! is_string($data['category'] ?? null)) {
            return null;
        }

        /** @var Category|null $category */
        $category = Category::query()->where('public_id', $data['category'])->first();

        return $category?->id;
    }

    private function resolveProductId(int|string|null $identifier): ?int
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        if (is_numeric($identifier)) {
            /** @var Product|null $product */
            $product = Product::query()->find((int) $identifier);
            if ($product !== null) {
                return $product->id;
            }
        }

        /** @var Product|null $product */
        $product = Product::query()->where('public_id', (string) $identifier)->first();

        return $product?->id;
    }
}
