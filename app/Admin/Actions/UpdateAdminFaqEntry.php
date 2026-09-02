<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Models\FaqEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateAdminFaqEntry
{
    public function __construct(
        private RecordStaffAudit $recordStaffAudit,
    ) {}

    /**
     * @param array{
     *     question_ar: string,
     *     question_en: string,
     *     answer_ar: string,
     *     answer_en: string
     * } $data
     */
    public function execute(User $actor, string $publicId, array $data, ?string $ipAddress = null): FaqEntry
    {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        return DB::transaction(function () use ($actor, $publicId, $data, $ipAddress): FaqEntry {
            /** @var FaqEntry $entry */
            $entry = FaqEntry::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->firstOrFail();

            $entry->question_ar = $data['question_ar'];
            $entry->question_en = $data['question_en'];
            $entry->answer_ar = $data['answer_ar'];
            $entry->answer_en = $data['answer_en'];
            $entry->save();

            $this->recordStaffAudit->execute(
                $actor,
                $entry,
                new StaffAuditEvent(
                    action: 'faq_entries.updated',
                    metadata: [
                        'question_ar' => $entry->question_ar,
                        'question_en' => $entry->question_en,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $entry;
        });
    }
}
