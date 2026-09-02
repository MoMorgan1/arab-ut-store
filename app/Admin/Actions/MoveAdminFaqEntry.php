<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Models\FaqEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MoveAdminFaqEntry
{
    public function __construct(
        private RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(
        User $actor,
        string $faqEntryPublicId,
        string $direction,
        ?string $ipAddress = null,
    ): FaqEntry {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages([
                'direction' => ['The direction must be either up or down.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $faqEntryPublicId, $direction, $ipAddress): FaqEntry {
            /** @var list<FaqEntry> $allEntries */
            $allEntries = FaqEntry::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all();

            $currentIndex = null;
            foreach ($allEntries as $index => $entry) {
                if ($entry->public_id === $faqEntryPublicId) {
                    $currentIndex = $index;
                    break;
                }
            }

            if ($currentIndex === null) {
                abort(404, 'FAQ entry not found.');
            }

            if ($direction === 'up' && $currentIndex === 0) {
                throw ValidationException::withMessages([
                    'direction' => ['Cannot move the first FAQ entry up.'],
                ]);
            }

            if ($direction === 'down' && $currentIndex === count($allEntries) - 1) {
                throw ValidationException::withMessages([
                    'direction' => ['Cannot move the last FAQ entry down.'],
                ]);
            }

            $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
            $currentEntry = $allEntries[$currentIndex];
            $neighbourEntry = $allEntries[$targetIndex];

            if ($currentEntry->sort_order === $neighbourEntry->sort_order) {
                foreach ($allEntries as $idx => $item) {
                    $item->sort_order = ($idx + 1) * 10;
                    $item->save();
                }
                $currentEntry->refresh();
                $neighbourEntry->refresh();
            }

            $previousSortOrder = (int) $currentEntry->sort_order;
            $neighbourSortOrder = (int) $neighbourEntry->sort_order;

            $currentEntry->sort_order = $neighbourSortOrder;
            $neighbourEntry->sort_order = $previousSortOrder;

            $currentEntry->save();
            $neighbourEntry->save();

            $this->recordStaffAudit->execute(
                $actor,
                $currentEntry,
                new StaffAuditEvent(
                    action: 'faq_entries.moved',
                    metadata: [
                        'direction' => $direction,
                        'previous_sort_order' => $previousSortOrder,
                        'new_sort_order' => $neighbourSortOrder,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $currentEntry;
        });
    }
}
