<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class UpdateAdminCoupon
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, string $couponPublicId, array $data): Coupon
    {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        return DB::transaction(function () use ($actor, $couponPublicId, $data): Coupon {
            /** @var Coupon $coupon */
            $coupon = Coupon::query()
                ->where('public_id', $couponPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            $coupon->update([
                'code' => mb_strtoupper(trim((string) $data['code'])),
                'description_ar' => $data['description_ar'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'discount_type' => $data['discount_type'],
                'value' => (int) $data['value'],
                'minimum_order_halalah' => (int) ($data['minimum_order_halalah'] ?? 0),
                'maximum_discount_halalah' => isset($data['maximum_discount_halalah'])
                    ? (int) $data['maximum_discount_halalah']
                    : null,
                'usage_limit' => isset($data['usage_limit']) ? (int) $data['usage_limit'] : null,
                'per_user_limit' => isset($data['per_user_limit']) ? (int) $data['per_user_limit'] : null,
                'scope' => (string) ($data['scope'] ?? $coupon->scope),
                'service_type' => array_key_exists('service_type', $data)
                    ? (is_string($data['service_type']) ? $data['service_type'] : null)
                    : $coupon->service_type,
                'first_order_only' => isset($data['first_order_only'])
                    ? (bool) $data['first_order_only']
                    : $coupon->first_order_only,
                'excludes_promoted_items' => isset($data['excludes_promoted_items'])
                    ? (bool) $data['excludes_promoted_items']
                    : $coupon->excludes_promoted_items,
                'starts_at' => isset($data['starts_at'])
                    ? Carbon::parse($data['starts_at'])->utc()
                    : null,
                'ends_at' => isset($data['ends_at'])
                    ? Carbon::parse($data['ends_at'])->utc()
                    : null,
                'is_active' => (bool) ($data['is_active'] ?? $coupon->is_active),
            ]);

            if (isset($data['targets']) || isset($data['category_ids']) || isset($data['product_ids'])) {
                $coupon->targets()->delete();
                $this->syncTargets($coupon, $data);
            }

            $this->recordStaffAudit->execute(
                $actor,
                $coupon,
                new StaffAuditEvent(
                    action: 'coupons.updated',
                    metadata: [
                        'coupon_public_id' => $coupon->public_id,
                        'code' => $coupon->code,
                    ],
                    ipAddress: request()->ip(),
                ),
            );

            return $coupon;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncTargets(Coupon $coupon, array $data): void
    {
        if (isset($data['targets']) && is_array($data['targets'])) {
            foreach ($data['targets'] as $target) {
                if (is_array($target) && isset($target['target_type'], $target['target_id'])) {
                    $coupon->targets()->create([
                        'target_type' => (string) $target['target_type'],
                        'target_id' => (int) $target['target_id'],
                    ]);
                }
            }
        }

        if (isset($data['category_ids']) && is_array($data['category_ids'])) {
            foreach ($data['category_ids'] as $categoryId) {
                $coupon->targets()->create([
                    'target_type' => CouponTarget::TYPE_CATEGORY,
                    'target_id' => (int) $categoryId,
                ]);
            }
        }

        if (isset($data['product_ids']) && is_array($data['product_ids'])) {
            foreach ($data['product_ids'] as $productId) {
                $coupon->targets()->create([
                    'target_type' => CouponTarget::TYPE_PRODUCT,
                    'target_id' => (int) $productId,
                ]);
            }
        }
    }
}
