<?php

namespace App\Admin\Presenters;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Support\Facades\Route;

final readonly class AdminMorePage
{
    public function __construct(
        private AdminShell $shell,
    ) {}

    /**
     * @return array{
     *     locale: string,
     *     direction: 'rtl' | 'ltr',
     *     adminUi: array<string, mixed>,
     *     adminIdentity: array{name: string, role: string},
     *     adminNavigation: list<array{key: string, label: string, url: string, children?: list<array{key: string, label: string, url: string}>}>,
     *     permissions: list<string>,
     *     groups: list<array{
     *         key: string,
     *         label: string,
     *         tiles: list<array{
     *             key: string,
     *             label: string,
     *             description: string,
     *             url: string
     *         }>
     *     }>,
     *     logoutUrl: string
     * }
     */
    public function for(User $actor, string $locale): array
    {
        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $groups = [];

        // 1. Catalog group (Categories tile)
        $catalogTiles = [];
        if ($actor->can(AdminPermission::CatalogView->value)) {
            $catalogTiles[] = [
                'key' => 'categories',
                'label' => (string) trans('admin.more.tiles.categories.title', locale: $locale),
                'description' => (string) trans('admin.more.tiles.categories.description', locale: $locale),
                'url' => Route::has($prefix.'categories')
                    ? route($prefix.'categories', absolute: false)
                    : ($prefix === 'localized.admin.' ? '/en/admin/categories' : '/admin/categories'),
            ];
        }

        if ($catalogTiles !== []) {
            $groups[] = [
                'key' => 'catalog',
                'label' => (string) trans('admin.more.groups.catalog', locale: $locale),
                'tiles' => $catalogTiles,
            ];
        }

        // 2. Marketing group (Coupons, Promotions, Loyalty tiles)
        $marketingTiles = [];
        if ($actor->can(AdminPermission::MarketingView->value)) {
            $marketingTiles[] = [
                'key' => 'coupons',
                'label' => (string) trans('admin.more.tiles.coupons.title', locale: $locale),
                'description' => (string) trans('admin.more.tiles.coupons.description', locale: $locale),
                'url' => route($prefix.'marketing.coupons', absolute: false),
            ];
            $marketingTiles[] = [
                'key' => 'promotions',
                'label' => (string) trans('admin.more.tiles.promotions.title', locale: $locale),
                'description' => (string) trans('admin.more.tiles.promotions.description', locale: $locale),
                'url' => route($prefix.'marketing.promotions', absolute: false),
            ];
        }

        if ($actor->can(AdminPermission::LoyaltyView->value)) {
            $marketingTiles[] = [
                'key' => 'loyalty',
                'label' => (string) trans('admin.more.tiles.loyalty.title', locale: $locale),
                'description' => (string) trans('admin.more.tiles.loyalty.description', locale: $locale),
                'url' => route($prefix.'marketing.loyalty', absolute: false),
            ];
        }

        if ($marketingTiles !== []) {
            $groups[] = [
                'key' => 'marketing',
                'label' => (string) trans('admin.more.groups.marketing', locale: $locale),
                'tiles' => $marketingTiles,
            ];
        }

        // 3. Support & System group (Conversations, Settings tiles)
        $systemTiles = [];
        if ($actor->can(AdminPermission::ChatView->value)) {
            $systemTiles[] = [
                'key' => 'conversations',
                'label' => (string) trans('admin.more.tiles.conversations.title', locale: $locale),
                'description' => (string) trans('admin.more.tiles.conversations.description', locale: $locale),
                'url' => route($prefix.'conversations', absolute: false),
            ];
        }

        if ($actor->can(AdminPermission::SettingsView->value)) {
            $systemTiles[] = [
                'key' => 'settings',
                'label' => (string) trans('admin.more.tiles.settings.title', locale: $locale),
                'description' => (string) trans('admin.more.tiles.settings.description', locale: $locale),
                'url' => route($prefix.'settings', absolute: false),
            ];
        }

        if ($systemTiles !== []) {
            $groups[] = [
                'key' => 'system',
                'label' => (string) trans('admin.more.groups.system', locale: $locale),
                'tiles' => $systemTiles,
            ];
        }

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'groups' => $groups,
        ];
    }
}
