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

final class CreateAdminCoupon
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, array $data): Coupon
    {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        return DB::transaction(function () use ($actor, $data): Coupon {
            $coupon = Coupon::create([
                'public_id' => (string) Str::ulid(),
                'code' => mb_strtoupper((string) $data['code']),
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
                'starts_at' => isset($data['starts_at'])
                    ? Carbon::parse($data['starts_at'])->utc()
                    : null,
                'ends_at' => isset($data['ends_at'])
                    ? Carbon::parse($data['ends_at'])->utc()
                    : null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->recordStaffAudit->execute(
                $actor,
                null,
                new StaffAuditEvent(
                    action: 'coupons.created',
                    metadata: [
                        'coupon_public_id' => $coupon->public_id,
                        'code' => $coupon->code,
                        'discount_type' => $coupon->discount_type,
                    ],
                    ipAddress: request()->ip(),
                ),
            );

            return $coupon;
        });
    }
}
