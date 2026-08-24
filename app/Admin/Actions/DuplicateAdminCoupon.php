<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DuplicateAdminCoupon
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function execute(User $actor, string $sourcePublicId, array $overrides = []): Coupon
    {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        return DB::transaction(function () use ($actor, $sourcePublicId, $overrides): Coupon {
            /** @var Coupon $source */
            $source = Coupon::query()
                ->where('public_id', $sourcePublicId)
                ->with('targets')
                ->lockForUpdate()
                ->firstOrFail();

            $newCode = $this->determineNewCode($source, $overrides);

            $newCoupon = Coupon::create([
                'public_id' => (string) Str::ulid(),
                'code' => $newCode,
                'description_ar' => $overrides['description_ar'] ?? $source->description_ar,
                'description_en' => $overrides['description_en'] ?? $source->description_en,
                'discount_type' => $overrides['discount_type'] ?? $source->discount_type,
                'value' => (int) ($overrides['value'] ?? $source->value),
                'minimum_order_halalah' => (int) ($overrides['minimum_order_halalah'] ?? $source->minimum_order_halalah),
                'maximum_discount_halalah' => array_key_exists('maximum_discount_halalah', $overrides)
                    ? ($overrides['maximum_discount_halalah'] !== null ? (int) $overrides['maximum_discount_halalah'] : null)
                    : $source->maximum_discount_halalah,
                'usage_limit' => array_key_exists('usage_limit', $overrides)
                    ? ($overrides['usage_limit'] !== null ? (int) $overrides['usage_limit'] : null)
                    : $source->usage_limit,
                'per_user_limit' => array_key_exists('per_user_limit', $overrides)
                    ? ($overrides['per_user_limit'] !== null ? (int) $overrides['per_user_limit'] : null)
                    : $source->per_user_limit,
                'scope' => (string) ($overrides['scope'] ?? $source->scope),
                'service_type' => array_key_exists('service_type', $overrides)
                    ? (is_string($overrides['service_type']) ? $overrides['service_type'] : null)
                    : $source->service_type,
                'first_order_only' => isset($overrides['first_order_only'])
                    ? (bool) $overrides['first_order_only']
                    : (bool) $source->first_order_only,
                'excludes_promoted_items' => isset($overrides['excludes_promoted_items'])
                    ? (bool) $overrides['excludes_promoted_items']
                    : (bool) $source->excludes_promoted_items,
                'starts_at' => array_key_exists('starts_at', $overrides)
                    ? ($overrides['starts_at'] !== null ? Carbon::parse($overrides['starts_at'])->utc() : null)
                    : $source->starts_at,
                'ends_at' => array_key_exists('ends_at', $overrides)
                    ? ($overrides['ends_at'] !== null ? Carbon::parse($overrides['ends_at'])->utc() : null)
                    : $source->ends_at,
                'is_active' => false, // Duplicated coupons are always created paused
            ]);

            foreach ($source->targets as $target) {
                $newCoupon->targets()->create([
                    'target_type' => $target->target_type,
                    'target_id' => $target->target_id,
                ]);
            }

            $this->recordStaffAudit->execute(
                $actor,
                $newCoupon,
                new StaffAuditEvent(
                    action: 'coupons.created',
                    metadata: [
                        'coupon_public_id' => $newCoupon->public_id,
                        'code' => $newCoupon->code,
                        'source_coupon_public_id' => $source->public_id,
                        'duplicated' => true,
                    ],
                    ipAddress: request()->ip(),
                ),
            );

            return $newCoupon;
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function determineNewCode(Coupon $source, array $overrides): string
    {
        if (! empty($overrides['code']) && is_string($overrides['code'])) {
            return mb_strtoupper(trim($overrides['code']));
        }

        $base = mb_strtoupper(trim((string) $source->code));
        $suffix = '-COPY';

        if (mb_strlen($base) + mb_strlen($suffix) > 24) {
            $base = mb_substr($base, 0, 24 - mb_strlen($suffix));
        }

        $candidate = $base.$suffix;
        $counter = 2;

        while (Coupon::query()->where('code', $candidate)->exists()) {
            $numSuffix = '-COPY'.$counter;
            if (mb_strlen($base) + mb_strlen($numSuffix) > 24) {
                $base = mb_substr($base, 0, 24 - mb_strlen($numSuffix));
            }
            $candidate = $base.$numSuffix;
            $counter++;
        }

        return $candidate;
    }
}
