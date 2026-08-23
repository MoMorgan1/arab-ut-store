<?php

namespace App\Marketing;

use App\Enums\ServiceType;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves automatic promotion pricing for a single priced item.
 *
 * Given an item's category, service type, base price (the price already
 * published by catalog or schedule pricing), and optional product id, this
 * returns the best currently active promotion and the discounted price.
 * Percent discounts are floored to whole halalah; fixed discounts never exceed
 * the base price, so the promoted price is never below zero. Overlapping
 * promotions are allowed and the largest discount wins; ties resolve
 * deterministically by the larger raw value, then the older promotion.
 */
final class PromotionPricing
{
    /** Promotions never change within a single request, so cache the active set per instance. */
    /** @var Collection<int, Promotion>|null */
    private ?Collection $activePromotions = null;

    public function resolve(
        ?int $categoryId,
        ServiceType $serviceType,
        int $basePriceHalalah,
        ?int $productId = null,
    ): ?PromotionPrice {
        if ($basePriceHalalah < 1) {
            return null;
        }

        $best = null;
        $bestDiscount = 0;

        foreach ($this->activePromotions() as $promotion) {
            if (! $this->matches($promotion, $categoryId, $serviceType, $productId)) {
                continue;
            }

            $discount = $this->discountFor($promotion, $basePriceHalalah);

            if ($discount > $bestDiscount) {
                $best = $promotion;
                $bestDiscount = $discount;
            }
        }

        if (! $best instanceof Promotion) {
            return null;
        }

        return new PromotionPrice(
            promotion: $best,
            baseHalalah: $basePriceHalalah,
            discountHalalah: $bestDiscount,
            discountedHalalah: $basePriceHalalah - $bestDiscount,
        );
    }

    /** The applied discount for one item at its base price: floored, capped, never negative. */
    public function discountFor(Promotion $promotion, int $basePriceHalalah): int
    {
        if ($basePriceHalalah < 1 || $promotion->value < 1) {
            return 0;
        }

        $discount = $promotion->discount_type === 'percent'
            ? intdiv($basePriceHalalah * $promotion->value, 100)
            : $promotion->value;

        return max(0, min($discount, $basePriceHalalah));
    }

    /** @return Collection<int, Promotion> */
    private function activePromotions(): Collection
    {
        return $this->activePromotions ??= Promotion::query()
            ->active()
            ->orderByDesc('value')
            ->orderBy('id')
            ->get();
    }

    private function matches(
        Promotion $promotion,
        ?int $categoryId,
        ServiceType $serviceType,
        ?int $productId,
    ): bool {
        return match ($promotion->scope) {
            Promotion::SCOPE_ALL => true,
            Promotion::SCOPE_CATEGORY => $categoryId !== null && $promotion->category_id === $categoryId,
            Promotion::SCOPE_SERVICE => $promotion->service_type === $serviceType->value,
            Promotion::SCOPE_PRODUCT => $productId !== null && $promotion->product_id === $productId,
            default => false,
        };
    }
}
