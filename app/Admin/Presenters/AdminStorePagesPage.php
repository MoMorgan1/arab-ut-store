<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ListAdminStorePages;
use App\Enums\AdminPermission;
use App\Models\User;

final readonly class AdminStorePagesPage
{
    public function __construct(
        private AdminShell $shell,
        private ListAdminStorePages $query,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $actor, string $locale): array
    {
        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'pages' => $this->query->get($locale),
            'canManage' => $actor->can(AdminPermission::MarketingManage->value),
        ];
    }
}
