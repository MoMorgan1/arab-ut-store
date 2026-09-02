<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ListAdminFaqEntries;
use App\Enums\AdminPermission;
use App\Models\User;

final readonly class AdminFaqPage
{
    public function __construct(
        private AdminShell $shell,
        private ListAdminFaqEntries $query,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $actor, string $locale): array
    {
        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'entries' => $this->query->get(),
            'createUrl' => route($prefix.'marketing.faq.store', absolute: false),
            'updateUrlTemplate' => route($prefix.'marketing.faq.update', ['publicId' => '__ID__'], absolute: false),
            'visibilityUrlTemplate' => route($prefix.'marketing.faq.visibility.store', ['publicId' => '__ID__'], absolute: false),
            'moveUrlTemplate' => route($prefix.'marketing.faq.move', ['publicId' => '__ID__'], absolute: false),
            'deleteUrlTemplate' => route($prefix.'marketing.faq.destroy', ['publicId' => '__ID__'], absolute: false),
            'canManage' => $actor->can(AdminPermission::MarketingManage->value),
        ];
    }
}
