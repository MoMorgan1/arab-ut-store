<?php

namespace App\Admin\Queries;

use App\Models\FaqEntry;

final class ListAdminFaqEntries
{
    /**
     * @return list<array{
     *     id: string,
     *     questionAr: string,
     *     questionEn: string,
     *     answerAr: string,
     *     answerEn: string,
     *     sortOrder: int,
     *     isVisible: bool,
     *     updatedAt: string
     * }>
     */
    public function get(): array
    {
        return array_values(FaqEntry::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (FaqEntry $entry): array => [
                'id' => (string) $entry->public_id,
                'questionAr' => (string) $entry->question_ar,
                'questionEn' => (string) $entry->question_en,
                'answerAr' => (string) $entry->answer_ar,
                'answerEn' => (string) $entry->answer_en,
                'sortOrder' => (int) $entry->sort_order,
                'isVisible' => (bool) $entry->is_visible,
                'updatedAt' => $entry->updated_at?->toIso8601String() ?? '',
            ])
            ->all());
    }
}
