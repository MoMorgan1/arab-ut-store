<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Models\FaqEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class CreateAdminFaqEntry
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
    public function execute(User $actor, array $data, ?string $ipAddress = null): FaqEntry
    {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        return DB::transaction(function () use ($actor, $data, $ipAddress): FaqEntry {
            $maxSort = FaqEntry::query()->lockForUpdate()->max('sort_order');
            $sortOrder = ($maxSort !== null ? (int) $maxSort : 0) + 10;

            /** @var FaqEntry $entry */
            $entry = FaqEntry::query()->create([
                'question_ar' => $data['question_ar'],
                'question_en' => $data['question_en'],
                'answer_ar' => $data['answer_ar'],
                'answer_en' => $data['answer_en'],
                'sort_order' => $sortOrder,
                'is_visible' => true,
            ]);

            $this->recordStaffAudit->execute(
                $actor,
                $entry,
                new StaffAuditEvent(
                    action: 'faq_entries.created',
                    metadata: [
                        'question_ar' => $entry->question_ar,
                        'question_en' => $entry->question_en,
                        'sort_order' => (int) $entry->sort_order,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $entry;
        });
    }
}
