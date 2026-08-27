<?php

namespace App\ValueObjects\Cart;

use App\Enums\CartItemUnavailableReason;
use App\Models\CartItem;

final readonly class CartRepricing
{
    /** @param array<int, CartItemPrice> $prices keyed by cart-item id */
    public function __construct(private array $prices) {}

    public function for(CartItem $item): CartItemPrice
    {
        return $this->prices[(int) $item->id]
            ?? CartItemPrice::unavailable(CartItemUnavailableReason::ConfigurationInvalid);
    }

    public function hasUnavailable(): bool
    {
        foreach ($this->prices as $price) {
            if ($price->unavailableReason !== null) {
                return true;
            }
        }

        return false;
    }

    public function hasPricingRunInProgress(): bool
    {
        foreach ($this->prices as $price) {
            if ($price->pricingRunInProgress) {
                return true;
            }
        }

        return false;
    }
}
