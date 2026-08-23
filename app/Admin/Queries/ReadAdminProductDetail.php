<?php

namespace App\Admin\Queries;

use App\Enums\AdminPermission;
use App\Models\CatalogSyncItem;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\StaffAuditLog;
use App\Models\User;

final class ReadAdminProductDetail
{
    /**
     * @return array{
     *     product: Product,
     *     variants: list<ProductVariant>,
     *     media: list<ProductMedia>,
     *     lastSyncItem: CatalogSyncItem|null,
     *     auditLogs: list<StaffAuditLog>|null
     * }|null
     */
    public function findByPublicId(string $publicId, User $actor): ?array
    {
        /** @var Product|null $product */
        $product = Product::query()
            ->where('public_id', $publicId)
            ->with(['category', 'source'])
            ->first();

        if ($product === null) {
            return null;
        }

        /** @var list<ProductVariant> $variants */
        $variants = ProductVariant::query()
            ->where('product_id', $product->id)
            ->orderBy('id', 'asc')
            ->get()
            ->all();

        /** @var list<ProductMedia> $media */
        $media = ProductMedia::query()
            ->where('product_id', $product->id)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->all();

        /** @var CatalogSyncItem|null $lastSyncItem */
        $lastSyncItem = CatalogSyncItem::query()
            ->where('product_id', $product->id)
            ->with('run')
            ->orderByDesc('id')
            ->first();

        $auditLogs = null;

        if ($actor->can(AdminPermission::AuditView->value)) {
            /** @var list<StaffAuditLog> $auditLogs */
            $auditLogs = StaffAuditLog::query()
                ->where('auditable_type', $product->getMorphClass())
                ->where('auditable_id', $product->getKey())
                ->with('actor')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->all();
        }

        return [
            'product' => $product,
            'variants' => $variants,
            'media' => $media,
            'lastSyncItem' => $lastSyncItem,
            'auditLogs' => $auditLogs,
        ];
    }
}
