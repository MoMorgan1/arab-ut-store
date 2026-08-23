<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ToggleAdminCouponStatus
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(User $actor, string $couponPublicId, bool $isActive): Coupon
    {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        return DB::transaction(function () use ($actor, $couponPublicId, $isActive): Coupon {
            /** @var Coupon $coupon */
            $coupon = Coupon::query()
                ->where('public_id', $couponPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            $previousActive = (bool) $coupon->is_active;
            $coupon->is_active = $isActive;
            $coupon->save();

            $this->recordStaffAudit->execute(
                $actor,
                $coupon,
                new StaffAuditEvent(
                    action: $isActive ? 'coupons.activated' : 'coupons.deactivated',
                    metadata: [
                        'coupon_public_id' => $coupon->public_id,
                        'code' => $coupon->code,
                        'previous_active' => $previousActive,
                        'new_active' => $isActive,
                    ],
                    ipAddress: request()->ip(),
                ),
            );

            return $coupon;
        });
    }
}
