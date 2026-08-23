<?php

namespace App\Admin\Presenters;

use App\Models\CatalogSyncItem;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\StaffAuditLog;
use App\Models\User;

final readonly class AdminProductDetailPage
{
    public function __construct(
        private AdminShell $shell,
        private AdminProductDetail $detailPresenter,
    ) {}

    /**
     * @param array{
     *     product: Product,
     *     variants: list<ProductVariant>,
     *     media: list<ProductMedia>,
     *     lastSyncItem: CatalogSyncItem|null,
     *     auditLogs: list<StaffAuditLog>|null
     * } $detail
     * @return array<string, mixed>
     */
    public function for(
        User $actor,
        string $locale,
        array $detail,
    ): array {
        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $presented = $this->detailPresenter->present(
            $detail['product'],
            $detail['variants'],
            $detail['media'],
            $detail['lastSyncItem'],
            $detail['auditLogs'],
            $locale,
        );

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'product' => $presented,
            'updateUrl' => route($prefix.'products.update', ['publicId' => (string) $detail['product']->public_id], absolute: false),
            'visibilityUrl' => route($prefix.'products.visibility.store', ['publicId' => (string) $detail['product']->public_id], absolute: false),
            // A template because the page posts per variant; the id is
            // substituted client-side rather than building one URL per row.
            'variantPriceUrlTemplate' => route($prefix.'variants.price.store', ['publicId' => '__ID__'], absolute: false),
            'confirmPasswordUrl' => route('password.confirm', absolute: false),
        ];
    }
}
