<?php

namespace App\ValueObjects\Cart;

use App\Enums\CartItemUnavailableReason;
use App\Models\ProductVariant;

/**
 * What one cart item costs right now, or why it cannot be priced.
 *
 * This returns outcomes instead of throwing because its two callers need
 * opposite things from the same facts: the cart page renders an unavailable
 * item as a badge, while checkout refuses on it.
 */
final readonly class CartItemPrice
{
    private function __construct(
        public ?int $unitPriceHalalah,
        public ?int $totalHalalah,
        public ?int $priceVersion,
        public ?int $scheduleVersion,
        public ?string $quotedAt,
        public ?ProductVariant $variant,
        public ?CartItemUnavailableReason $unavailableReason,
        public bool $pricingRunInProgress,
    ) {}

    public static function priced(
        int $unitPriceHalalah,
        int $totalHalalah,
        int $priceVersion,
        ProductVariant $variant,
        ?int $scheduleVersion = null,
        ?string $quotedAt = null,
    ): self {
        return new self(
            $unitPriceHalalah,
            $totalHalalah,
            $priceVersion,
            $scheduleVersion,
            $quotedAt,
            $variant,
            null,
            false,
        );
    }

    public static function unavailable(CartItemUnavailableReason $reason): self
    {
        return new self(null, null, null, null, null, null, $reason, false);
    }

    /**
     * A coins quote computed from pre-run rules against a variant row that
     * already carries the new price_version. Transient by construction: a
     * pricing run is one short transaction, and it never changes variant ids.
     */
    public static function pricingRunInProgress(): self
    {
        return new self(null, null, null, null, null, null, null, true);
    }

    public function isPriced(): bool
    {
        return $this->unavailableReason === null && ! $this->pricingRunInProgress;
    }
}
