<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogSyncItem extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<CatalogSyncRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CatalogSyncRun::class, 'catalog_sync_run_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
