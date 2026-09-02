<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ReadAdminStorePage;
use App\Enums\AdminPermission;
use App\Models\User;

final readonly class AdminStorePageEditorPage
{
    /** @var array<string, string> */
    private const ADDRESSES = [
        'privacy' => '/privacy',
        'returns' => '/returns',
        'warranty' => '/warranty',
        'ea_backup_codes' => '/ea-backup-codes',
        'terms' => '/terms',
    ];

    public function __construct(
        private AdminShell $shell,
        private ReadAdminStorePage $query,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $actor, string $key, string $locale): array
    {
        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $pageData = $this->query->get($key);
        $address = self::ADDRESSES[$key] ?? "/{$key}";
        $storeUrl = $locale === 'en' ? "/en{$address}" : $address;

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'pageKey' => $key,
            'storeUrl' => $storeUrl,
            'saveUrl' => route($prefix.'marketing.pages.update', ['key' => $key], absolute: false),
            'content' => [
                'ar' => $pageData['ar'],
                'en' => $pageData['en'],
            ],
            'canManage' => $actor->can(AdminPermission::MarketingManage->value),
        ];
    }
}
