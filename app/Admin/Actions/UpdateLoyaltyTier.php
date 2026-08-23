<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Models\LoyaltyTier;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class UpdateLoyaltyTier
{
    public function __construct(
        private RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(
        User $actor,
        string $publicId,
        string $nameAr,
        string $nameEn,
        int $minimumSpendHalalah,
        int $cashbackBasisPoints,
        bool $isActive,
        ?string $ipAddress = null,
    ): LoyaltyTier {
        if (! $actor->is_active || ! $actor->can(AdminPermission::LoyaltyManage->value)) {
            throw new AuthorizationException('This action requires loyalty.manage permission.');
        }

        return DB::transaction(function () use (
            $actor,
            $publicId,
            $nameAr,
            $nameEn,
            $minimumSpendHalalah,
            $cashbackBasisPoints,
            $isActive,
            $ipAddress,
        ): LoyaltyTier {
            /** @var LoyaltyTier $tier */
            $tier = LoyaltyTier::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($tier->rank === 1 && $minimumSpendHalalah !== 0) {
                throw new InvalidArgumentException('Rank-1 loyalty tier minimum spend must stay 0.');
            }

            if ($cashbackBasisPoints < 0 || $cashbackBasisPoints > 2000) {
                throw new InvalidArgumentException('Cashback basis points must be between 0 and 2000.');
            }

            if ($isActive) {
                $hasLowerConflict = LoyaltyTier::query()
                    ->whereKeyNot($tier->getKey())
                    ->where('is_active', true)
                    ->where('rank', '<', $tier->rank)
                    ->where('minimum_lifetime_spend_halalah', '>=', $minimumSpendHalalah)
                    ->exists();

                $hasHigherConflict = LoyaltyTier::query()
                    ->whereKeyNot($tier->getKey())
                    ->where('is_active', true)
                    ->where('rank', '>', $tier->rank)
                    ->where('minimum_lifetime_spend_halalah', '<=', $minimumSpendHalalah)
                    ->exists();

                if ($hasLowerConflict || $hasHigherConflict) {
                    throw new InvalidArgumentException('Active tier thresholds must remain strictly increasing by rank.');
                }
            }

            $previous = [
                'name_ar' => (string) $tier->name_ar,
                'name_en' => (string) $tier->name_en,
                'minimum_lifetime_spend_halalah' => (int) $tier->minimum_lifetime_spend_halalah,
                'cashback_basis_points' => (int) $tier->cashback_basis_points,
                'is_active' => (bool) $tier->is_active,
            ];

            $tier->name_ar = $nameAr;
            $tier->name_en = $nameEn;
            $tier->minimum_lifetime_spend_halalah = $minimumSpendHalalah;
            $tier->cashback_basis_points = $cashbackBasisPoints;
            $tier->is_active = $isActive;
            $tier->save();

            $this->recordStaffAudit->execute(
                $actor,
                $tier,
                new StaffAuditEvent(
                    action: 'loyalty.tier_updated',
                    metadata: [
                        'tier_key' => (string) $tier->key,
                        'rank' => (int) $tier->rank,
                        'previous' => $previous,
                        'new' => [
                            'name_ar' => $nameAr,
                            'name_en' => $nameEn,
                            'minimum_lifetime_spend_halalah' => $minimumSpendHalalah,
                            'cashback_basis_points' => $cashbackBasisPoints,
                            'is_active' => $isActive,
                        ],
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $tier;
        });
    }
}
