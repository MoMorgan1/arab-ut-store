<?php

namespace App\Admin\Queries;

use App\Models\StorePage;
use App\Services\Content\StoreInformationMarkup;
use LogicException;

final class ReadAdminStorePage
{
    /**
     * @return array{
     *     key: string,
     *     ar: array{
     *         title: string,
     *         subtitle: ?string,
     *         updatedLabel: string,
     *         blocks: list<array<string, mixed>>
     *     },
     *     en: array{
     *         title: string,
     *         subtitle: ?string,
     *         updatedLabel: string,
     *         blocks: list<array<string, mixed>>
     *     }
     * }
     */
    public function get(string $key): array
    {
        /** @var StorePage|null $page */
        $page = StorePage::query()->where('key', $key)->first();

        if ($page === null) {
            throw new LogicException("Store page [{$key}] not found.");
        }

        return [
            'key' => $page->key,
            'ar' => [
                'title' => (string) $page->title_ar,
                'subtitle' => $page->subtitle_ar ? (string) $page->subtitle_ar : null,
                'updatedLabel' => (string) $page->updated_label_ar,
                'blocks' => StoreInformationMarkup::blocksToEditor($page->blocks_ar),
            ],
            'en' => [
                'title' => (string) $page->title_en,
                'subtitle' => $page->subtitle_en ? (string) $page->subtitle_en : null,
                'updatedLabel' => (string) $page->updated_label_en,
                'blocks' => StoreInformationMarkup::blocksToEditor($page->blocks_en),
            ],
        ];
    }
}
