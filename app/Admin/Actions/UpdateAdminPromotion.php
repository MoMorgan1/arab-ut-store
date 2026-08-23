<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Models\Category;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

            $promotion->update([
                'name_ar' => (string) $data['name_ar'],
                'name_en' => (string) $data['name_en'],
                'badge_ar' => $data['badge_ar'] ?? null,
                'badge_en' => $data['badge_en'] ?? null,
                'scope' => (string) $data['scope'],
                'category_id' => $this->categoryId($data),
                'service_type' => isset($data['service_type']) && is_string($data['service_type'])
                    ? $data['service_type']
                    : null,
                'discount_type' => (string) $data['discount_type'],
                'value' => (int) $data['value'],
                'starts_at' => isset($data['starts_at']) ? Carbon::parse((string) $data['starts_at'])->utc() : null,
                'ends_at' => isset($data['ends_at']) ? Carbon::parse((string) $data['ends_at'])->utc() : null,
                'is_active' => (bool) ($data['is_active'] ?? $promotion->is_active),
            ]);

            $this->recordStaffAudit->execute(
                $actor,
                $promotion,
                new StaffAuditEvent(
                    action: 'promotions.updated',
                    metadata: [
                        'promotion_public_id' => $promotion->public_id,
                        'scope' => $promotion->scope,
                        'discount_type' => $promotion->discount_type,
                        'value' => $promotion->value,
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
}
