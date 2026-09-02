<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Exceptions\AdminFaqEntryVisibilityConflict;
use App\Models\FaqEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class SetAdminFaqEntryVisibility
{
    public function __construct(
        private RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(
        User $actor,
        string $faqEntryPublicId,
        bool $visible,
        bool $expectedVisible,
        ?string $ipAddress = null,
    ): FaqEntry {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        return DB::transaction(function () use ($actor, $faqEntryPublicId, $visible, $expectedVisible, $ipAddress): FaqEntry {
            /** @var FaqEntry $entry */
            $entry = FaqEntry::query()
                ->where('public_id', $faqEntryPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            $previouslyVisible = (bool) $entry->is_visible;

            if ($previouslyVisible !== $expectedVisible) {
                throw new AdminFaqEntryVisibilityConflict(
                    (string) $entry->public_id,
                    $previouslyVisible,
                );
            }

            if ($previouslyVisible === $visible) {
                return $entry;
            }

            $entry->is_visible = $visible;
            $entry->save();

            $this->recordStaffAudit->execute(
                $actor,
                $entry,
                new StaffAuditEvent(
                    action: 'faq_entries.visibility_changed',
                    metadata: [
                        'previous_visible' => $previouslyVisible,
                        'new_visible' => $visible,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $entry;
        });
    }
}
