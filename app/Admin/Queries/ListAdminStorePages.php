<?php

namespace App\Admin\Queries;

use App\Models\StorePage;

final class ListAdminStorePages
{
    /** @var list<string> */
    private const ORDER = [
        'privacy',
        'returns',
        'warranty',
        'ea_backup_codes',
        'terms',
    ];

    /** @var array<string, string> */
    private const ADDRESSES = [
        'privacy' => '/privacy',
        'returns' => '/returns',
        'warranty' => '/warranty',
        'ea_backup_codes' => '/ea-backup-codes',
        'terms' => '/terms',
    ];

    /**
     * @return list<array{
     *     key: string,
     *     titleAr: string,
     *     titleEn: string,
     *     blockCount: int,
     *     address: string,
     *     updatedLabel: string,
     *     editUrl: string
     * }>
     */
    public function get(string $locale): array
    {
        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $pages = StorePage::query()->get()->keyBy('key');

        $rows = [];
        foreach (self::ORDER as $key) {
            /** @var StorePage|null $page */
            $page = $pages->get($key);
            if ($page === null) {
                continue;
            }

            $rows[] = [
                'key' => $page->key,
                'titleAr' => (string) $page->title_ar,
                'titleEn' => (string) $page->title_en,
                'blockCount' => count($page->blocks_ar),
                'address' => self::ADDRESSES[$key],
                'updatedLabel' => $locale === 'en' ? (string) $page->updated_label_en : (string) $page->updated_label_ar,
                'editUrl' => route($prefix.'marketing.pages.edit', ['key' => $page->key], absolute: false),
            ];
        }

        return $rows;
    }
}
