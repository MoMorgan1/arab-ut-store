<?php

namespace App\Services\Content;

use App\Models\FaqEntry;

final class StoreFaqReader
{
    /**
     * @return list<array{id: string, question: string, answer: string}>
     */
    public function entries(string $locale): array
    {
        return array_values(FaqEntry::query()
            ->visible()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (FaqEntry $entry): array => [
                'id' => (string) $entry->public_id,
                'question' => $entry->question($locale),
                'answer' => $entry->answer($locale),
            ])
            ->all());
    }
}
