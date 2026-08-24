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

final class UpdateAdminPromotion
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, string $promotionPublicId, array $data): Promotion
    {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        return DB::transaction(function () use ($actor, $promotionPublicId, $data): Promotion {
            /** @var Promotion $promotion */
            $promotion = Promotion::query()
                ->where('public_id', $promotionPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            $mechanic = (string) ($data['mechanic'] ?? $promotion->mechanic ?? Promotion::MECHANIC_ITEM);
            $discountType = (string) ($data['discount_type'] ?? ($mechanic === Promotion::MECHANIC_BUNDLE ? 'fixed' : $promotion->discount_type));
            $value = isset($data['value']) ? (int) $data['value'] : ($mechanic === Promotion::MECHANIC_BUNDLE ? 0 : $promotion->value);

            $promotion->update([
                'name_ar' => (string) $data['name_ar'],
                'name_en' => (string) $data['name_en'],
                'badge_ar' => $data['badge_ar'] ?? null,
                'badge_en' => $data['badge_en'] ?? null,
                'mechanic' => $mechanic,
                'scope' => (string) ($data['scope'] ?? $promotion->scope),
                'category_id' => $this->categoryId($data),
                'service_type' => isset($data['service_type']) && is_string($data['service_type'])
                    ? $data['service_type']
                    : $promotion->service_type,
                'discount_type' => $discountType,
                'value' => $value,

                // Every field below keeps its stored value when the key is
                // absent. Resetting them to null on a partial update was a real
                // hazard: the engine reads a null buy/get as 1 and a null
                // max_applications as UNCAPPED, so editing a promotion's name
                // would have silently turned "buy 3 get 1, max 1" into
                // "buy 1 get 1, unlimited" - every second item free, whole cart.
                'buy_quantity' => isset($data['buy_quantity']) && $data['buy_quantity'] !== ''
                    ? (int) $data['buy_quantity']
                    : $promotion->buy_quantity,
                'get_quantity' => isset($data['get_quantity']) && $data['get_quantity'] !== ''
                    ? (int) $data['get_quantity']
                    : $promotion->get_quantity,
                'max_applications' => isset($data['max_applications']) && $data['max_applications'] !== ''
                    ? (int) $data['max_applications']
                    : $promotion->max_applications,
                'discount_target' => isset($data['discount_target']) && is_string($data['discount_target'])
                    ? $data['discount_target']
                    : ($promotion->discount_target ?? Promotion::TARGET_CHEAPEST),
                'qualifying_scope' => isset($data['qualifying_scope']) && is_string($data['qualifying_scope'])
                    ? $data['qualifying_scope']
                    : $promotion->qualifying_scope,
                'bundle_price_halalah' => isset($data['bundle_price_halalah']) && $data['bundle_price_halalah'] !== ''
                    ? (int) $data['bundle_price_halalah']
                    : $promotion->bundle_price_halalah,
                'applies_to_promoted_items' => (bool) ($data['applies_to_promoted_items'] ?? $promotion->applies_to_promoted_items),
                'starts_at' => isset($data['starts_at']) ? Carbon::parse((string) $data['starts_at'])->utc() : $promotion->starts_at,
                'ends_at' => isset($data['ends_at']) ? Carbon::parse((string) $data['ends_at'])->utc() : $promotion->ends_at,
                'is_active' => (bool) ($data['is_active'] ?? $promotion->is_active),
            ]);

            if ($mechanic === Promotion::MECHANIC_BUNDLE) {
                // Only rewrite components when the payload actually carries
                // them. Deleting first meant a partial update - fixing a typo in
                // the name, with no `components` key - emptied the bundle, and
                // an empty bundle applies no discount: the offer silently stops
                // working on the storefront while the admin page still shows it.
                if (array_key_exists('components', $data) && is_array($data['components'])) {
                    $promotion->components()->delete();

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
            } else {
                $promotion->components()->delete();
            }

            $this->recordStaffAudit->execute(
                $actor,
                $promotion,
                new StaffAuditEvent(
                    action: 'promotions.updated',
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
