<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Models\FaqEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class DeleteAdminFaqEntry
{
    public function __construct(
        private RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(User $actor, string $faqEntryPublicId, ?string $ipAddress = null): void
    {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        DB::transaction(function () use ($actor, $faqEntryPublicId, $ipAddress): void {
            /** @var FaqEntry $entry */
            $entry = FaqEntry::query()
                ->where('public_id', $faqEntryPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->recordStaffAudit->execute(
                $actor,
                $entry,
                new StaffAuditEvent(
                    action: 'faq_entries.deleted',
                    metadata: [
                        'question_ar' => (string) $entry->question_ar,
                        'question_en' => (string) $entry->question_en,
                        'answer_ar' => (string) $entry->answer_ar,
                        'answer_en' => (string) $entry->answer_en,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            $entry->delete();
        });
    }
}
