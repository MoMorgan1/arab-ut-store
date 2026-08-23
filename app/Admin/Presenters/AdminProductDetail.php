<?php

namespace App\Admin\Presenters;

use App\Enums\ProductAuthority;
use App\Models\CatalogSource;
use App\Models\CatalogSyncItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\StaffAuditLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class AdminProductDetail
{
    /**
     * @param  list<ProductVariant>  $variants
     * @param  list<ProductMedia>  $media
     * @param  list<StaffAuditLog>|null  $auditLogs
     * @return array{
     *     id: string,
     *     slug: string,
     *     name: string,
     *     nameAr: string,
     *     nameEn: string,
     *     descriptionAr: string|null,
     *     descriptionEn: string|null,
     *     serviceType: string,
     *     authority: string,
     *     isEditable: bool,
     *     isVisible: bool,
     *     sortOrder: int,
     *     isArchived: bool,
     *     archivedAt: string|null,
     *     createdAt: string,
     *     updatedAt: string,
     *     category: array{id: string, name: string, slug: string}|null,
     *     source: array{id: string, key: string, name: string, authority: string}|null,
     *     variants: list<array{
     *         id: string,
     *         sku: string,
     *         serviceType: string,
     *         platform: string,
     *         market: string,
     *         authority: string,
     *         nameAr: string|null,
     *         nameEn: string|null,
     *         quantityK: int|null,
     *         price: array{amountMinor: string, currency: string},
     *         salePrice: array{amountMinor: string, currency: string}|null,
     *         priceVersion: int,
     *         configuration: array<string, mixed>|null,
     *         isActive: bool,
     *         createdAt: string,
     *         updatedAt: string
     *     }>,
     *     media: list<array{
     *         id: string,
     *         disk: string,
     *         path: string,
     *         altAr: string|null,
     *         altEn: string|null,
     *         sortOrder: int
     *     }>,
     *     automation: array{
     *         runId: string,
     *         status: string,
     *         outcome: string,
     *         completedAt: string|null,
     *         startedAt: string|null,
     *         error: string|null,
     *         syncedAt: string
     *     }|null,
     *     recentAuditLogs: list<array{
     *         id: string,
     *         action: string,
     *         actor: ?array{name: string, role: string},
     *         createdAt: string,
     *         metadata: array<string, mixed>
     *     }>|null
     * }
     */
    public function present(
        Product $product,
        array $variants,
        array $media,
        ?CatalogSyncItem $lastSyncItem,
        ?array $auditLogs,
        string $locale,
    ): array {
        return [
            'id' => (string) $product->public_id,
            'slug' => (string) $product->slug,
            'name' => $locale === 'en' ? (string) $product->name_en : (string) $product->name_ar,
            'nameAr' => (string) $product->name_ar,
            'nameEn' => (string) $product->name_en,
            'descriptionAr' => $product->description_ar !== null ? (string) $product->description_ar : null,
            'descriptionEn' => $product->description_en !== null ? (string) $product->description_en : null,
            'serviceType' => $product->service_type->value,
            'authority' => $product->authority->value,
            'isEditable' => $product->authority === ProductAuthority::Manual,
            'isVisible' => (bool) $product->is_visible,
            'sortOrder' => (int) $product->sort_order,
            'isArchived' => $product->archived_at !== null,
            'archivedAt' => $this->nullableIsoString($product->archived_at),
            'createdAt' => $this->isoString($product->created_at),
            'updatedAt' => $this->isoString($product->updated_at),
            'category' => $product->category instanceof Category
                ? [
                    'id' => (string) $product->category->public_id,
                    'name' => $locale === 'en' ? (string) $product->category->name_en : (string) $product->category->name_ar,
                    'slug' => (string) $product->category->slug,
                ]
                : null,
            'source' => $product->source instanceof CatalogSource
                ? [
                    'id' => (string) $product->source->public_id,
                    'key' => (string) $product->source->key,
                    'name' => (string) $product->source->name,
                    'authority' => $product->source->authority->value,
                ]
                : null,
            'variants' => array_map(
                fn (ProductVariant $variant): array => [
                    'id' => (string) $variant->public_id,
                    'sku' => (string) $variant->sku,
                    'serviceType' => $variant->service_type->value,
                    'platform' => $variant->platform->value,
                    'market' => $variant->market->value,
                    'authority' => $variant->authority->value,
                    'nameAr' => $variant->name_ar !== null ? (string) $variant->name_ar : null,
                    'nameEn' => $variant->name_en !== null ? (string) $variant->name_en : null,
                    'quantityK' => $variant->quantity_k !== null ? (int) $variant->quantity_k : null,
                    'price' => [
                        'amountMinor' => (string) $variant->price_halalah,
                        'currency' => 'SAR',
                    ],
                    'salePrice' => $variant->sale_price_halalah !== null
                        ? [
                            'amountMinor' => (string) $variant->sale_price_halalah,
                            'currency' => 'SAR',
                        ]
                        : null,
                    'priceVersion' => (int) $variant->price_version,
                    'configuration' => is_array($variant->configuration) ? $variant->configuration : null,
                    'isActive' => (bool) $variant->is_active,
                    'createdAt' => $this->isoString($variant->created_at),
                    'updatedAt' => $this->isoString($variant->updated_at),
                ],
                $variants,
            ),
            'media' => array_map(
                fn (ProductMedia $mediaItem): array => [
                    'id' => (string) $mediaItem->public_id,
                    'disk' => (string) $mediaItem->disk,
                    'path' => (string) $mediaItem->path,
                    'altAr' => $mediaItem->alt_ar !== null ? (string) $mediaItem->alt_ar : null,
                    'altEn' => $mediaItem->alt_en !== null ? (string) $mediaItem->alt_en : null,
                    'sortOrder' => (int) $mediaItem->sort_order,
                ],
                $media,
            ),
            'automation' => $lastSyncItem !== null
                ? [
                    'runId' => (string) $lastSyncItem->run->run_id,
                    'status' => (string) $lastSyncItem->run->status,
                    'outcome' => (string) $lastSyncItem->outcome,
                    'completedAt' => $this->nullableIsoString($lastSyncItem->run->completed_at),
                    'startedAt' => $this->nullableIsoString($lastSyncItem->run->started_at),
                    'error' => $lastSyncItem->error !== null ? (string) $lastSyncItem->error : null,
                    'syncedAt' => $this->isoString($lastSyncItem->created_at),
                ]
                : null,
            'recentAuditLogs' => $auditLogs !== null
                ? array_map(
                    fn (StaffAuditLog $log): array => [
                        'id' => (string) $log->public_id,
                        'action' => (string) $log->action,
                        'actor' => $log->actor instanceof User
                            ? [
                                'name' => $log->actor->name,
                                'role' => $log->actor->role->value,
                            ]
                            : null,
                        'createdAt' => $this->isoString($log->created_at),
                        'metadata' => $this->filterSafeAuditMetadata(is_array($log->metadata) ? $log->metadata : []),
                    ],
                    $auditLogs,
                )
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function filterSafeAuditMetadata(array $metadata): array
    {
        $safeKeys = [
            'product_changed',
            'product_previous',
            'product_new',
        ];

        return array_intersect_key($metadata, array_flip($safeKeys));
    }

    private function isoString(mixed $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->utc()->toIso8601String();
        }

        if (is_string($date) && $date !== '') {
            return Carbon::parse($date, 'UTC')->utc()->toIso8601String();
        }

        return '';
    }

    private function nullableIsoString(mixed $date): ?string
    {
        if ($date instanceof CarbonInterface) {
            return $date->utc()->toIso8601String();
        }

        if (is_string($date) && $date !== '') {
            return Carbon::parse($date, 'UTC')->utc()->toIso8601String();
        }

        return null;
    }
}
