<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceRule extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'platform' => Platform::class,
            'configuration' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
