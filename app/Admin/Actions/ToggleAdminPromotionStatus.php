<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ToggleAdminPromotionStatus
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(User $actor, string $promotionPublicId, bool $isActive): Promotion
    {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        return DB::transaction(function () use ($actor, $promotionPublicId, $isActive): Promotion {
            /** @var Promotion $promotion */
            $promotion = Promotion::query()
                ->where('public_id', $promotionPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            $previousActive = (bool) $promotion->is_active;
            $promotion->is_active = $isActive;
            $promotion->save();

            $this->recordStaffAudit->execute(
                $actor,
                $promotion,
                new StaffAuditEvent(
                    action: $isActive ? 'promotions.activated' : 'promotions.deactivated',
                    metadata: [
                        'promotion_public_id' => $promotion->public_id,
                        'previous_active' => $previousActive,
                        'new_active' => $isActive,
                    ],
                    ipAddress: request()->ip(),
                ),
            );

            return $promotion;
        });
    }
}
