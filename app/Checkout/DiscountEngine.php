<?php

namespace App\Checkout;

use App\Enums\CouponRejection;
use App\Enums\OrderStatus;
use App\Enums\ServiceType;
use App\Exceptions\Checkout\CouponRejected;
use App\Marketing\PromotionPrice;
use App\Marketing\PromotionPricing;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;

/**
 * Unified Discount Engine for Arab UT Store.
 *
 * Coordinates item promotions and cart coupons in a strict, deterministic sequence:
 * 1. Per-line promotions: Largest single discount wins, promotions never stack.
 * 2. Per-cart coupons: Applied to the promotion-net eligible base.
 *    Allocated across eligible lines via largest-remainder to ensure exact halalah totals.
 * 3. Loyalty cashback (accrued post-checkout on total - wallet).
 * 4. Wallet tender (applied last against the final payable amount).
 */
final class DiscountEngine
{
    public function __construct(
        private readonly PromotionPricing $promotionPricing,
    ) {}

    /**
     * Resolve the best active promotion for a single line item.
     */
    public function resolvePromotion(
        ?int $categoryId,
        ServiceType $serviceType,
        int $basePriceHalalah,
        ?int $productId = null,
    ): ?PromotionPrice {
        return $this->promotionPricing->resolve($categoryId, $serviceType, $basePriceHalalah, $productId);
    }

    /**
     * Calculate discounts for a cart (promotions + attached coupon).
     */
    public function calculateForCart(Cart $cart, ?User $user = null): DiscountResult
    {
        $lines = $this->cartToLines($cart);
        $coupon = $cart->coupon_id !== null ? $this->loadCoupon((int) $cart->coupon_id) : null;

        return $this->calculate($lines, $coupon, $user);
    }

    /**
     * Evaluate a coupon against an active cart.
     *
     * @throws CouponRejected
     */
    public function evaluateCartCoupon(Cart $cart, Coupon $coupon, ?User $user = null): AppliedCoupon
    {
        $lines = $this->cartToLines($cart);

        return $this->evaluateCoupon($coupon, $lines, $user);
    }

    /**
     * Calculate discounts for validated item snapshots from PlaceOrder.
     *
     * @param  iterable<int|string, array<string, mixed>>  $snapshots
     *
     * @throws CouponRejected
     */
    public function calculateForSnapshots(iterable $snapshots, ?Coupon $coupon = null, ?User $user = null): DiscountResult
    {
        $lines = $this->snapshotsToLines($snapshots);

        return $this->calculate($lines, $coupon, $user, throwOnCouponRejection: true);
    }

    /**
     * Evaluate an already-loaded coupon against validated item snapshots.
     *
     * @param  iterable<int|string, array<string, mixed>>  $snapshots
     *
     * @throws CouponRejected
     */
    public function evaluateSnapshotsCoupon(iterable $snapshots, Coupon $coupon, ?User $user = null): AppliedCoupon
    {
        $lines = $this->snapshotsToLines($snapshots);

        return $this->evaluateCoupon($coupon, $lines, $user);
    }

    /**
     * Evaluate a coupon against a simple subtotal (for tests or generic evaluations).
     *
     * @throws CouponRejected
     */
    public function evaluateSimpleCoupon(Coupon $coupon, int $subtotalHalalah, ?User $user = null): AppliedCoupon
    {
        $line = new DiscountLine(
            id: 'generic',
            categoryId: null,
            productId: null,
            serviceType: ServiceType::Coins,
            basePriceHalalah: $subtotalHalalah,
            quantity: 1,
        );

        return $this->evaluateCoupon($coupon, [$line], $user);
    }

    /**
     * Run the complete discount calculation pipeline across lines.
     *
     * @param  list<DiscountLine>  $lines
     *
     * @throws CouponRejected
     */
    public function calculate(
        array $lines,
        ?Coupon $coupon = null,
        ?User $user = null,
        bool $throwOnCouponRejection = false,
    ): DiscountResult {
        $baseSubtotal = 0;
        $promotedSubtotal = 0;
        $linePromotions = [];
        $linePromotionDiscounts = [];
        $lineNetHalalah = [];

        foreach ($lines as $line) {
            $promo = $this->promotionPricing->resolve(
                $line->categoryId,
                $line->serviceType,
                $line->basePriceHalalah,
                $line->productId,
            );

            $promoDiscount = $promo instanceof PromotionPrice ? $promo->discountHalalah : 0;
            $net = max(0, $line->basePriceHalalah - $promoDiscount);

            $baseSubtotal += $line->basePriceHalalah;
            $promotedSubtotal += $net;

            $linePromotions[$line->id] = $promo;
            $linePromotionDiscounts[$line->id] = $promoDiscount;
            $lineNetHalalah[$line->id] = $net;
        }

        $appliedCoupon = null;
        $lineCouponDiscounts = [];

        foreach ($lines as $line) {
            $lineCouponDiscounts[$line->id] = 0;
        }

        if ($coupon instanceof Coupon) {
            try {
                $appliedCoupon = $this->evaluateCouponAgainstNet(
                    $coupon,
                    $lines,
                    $lineNetHalalah,
                    $linePromotionDiscounts,
                    $user,
                );

                $lineCouponDiscounts = $appliedCoupon->allocations;
            } catch (CouponRejected $exception) {
                if ($throwOnCouponRejection) {
                    throw $exception;
                }

                $appliedCoupon = null;
            }
        }

        $couponDiscount = $appliedCoupon instanceof AppliedCoupon ? $appliedCoupon->discountHalalah : 0;
        $totalDiscount = ($baseSubtotal - $promotedSubtotal) + $couponDiscount;
        $payableTotal = max(0, $promotedSubtotal - $couponDiscount);

        return new DiscountResult(
            baseSubtotalHalalah: $baseSubtotal,
            promotedSubtotalHalalah: $promotedSubtotal,
            totalDiscountHalalah: $totalDiscount,
            payableTotalHalalah: $payableTotal,
            linePromotions: $linePromotions,
            linePromotionDiscounts: $linePromotionDiscounts,
            lineNetHalalah: $lineNetHalalah,
            lineCouponDiscounts: $lineCouponDiscounts,
            appliedCoupon: $appliedCoupon,
        );
    }

    /**
     * Evaluate a coupon against lines and throw CouponRejected on failure.
     *
     * @param  list<DiscountLine>  $lines
     *
     * @throws CouponRejected
     */
    public function evaluateCoupon(Coupon $coupon, array $lines, ?User $user = null): AppliedCoupon
    {
        $linePromotionDiscounts = [];
        $lineNetHalalah = [];

        foreach ($lines as $line) {
            $promo = $this->promotionPricing->resolve(
                $line->categoryId,
                $line->serviceType,
                $line->basePriceHalalah,
                $line->productId,
            );

            $promoDiscount = $promo instanceof PromotionPrice ? $promo->discountHalalah : 0;
            $net = max(0, $line->basePriceHalalah - $promoDiscount);

            $linePromotionDiscounts[$line->id] = $promoDiscount;
            $lineNetHalalah[$line->id] = $net;
        }

        return $this->evaluateCouponAgainstNet(
            $coupon,
            $lines,
            $lineNetHalalah,
            $linePromotionDiscounts,
            $user,
        );
    }

    /**
     * @param  list<DiscountLine>  $lines
     * @param  array<int|string, int>  $lineNetHalalah
     * @param  array<int|string, int>  $linePromotionDiscounts
     *
     * @throws CouponRejected
     */
    private function evaluateCouponAgainstNet(
        Coupon $coupon,
        array $lines,
        array $lineNetHalalah,
        array $linePromotionDiscounts,
        ?User $user,
    ): AppliedCoupon {
        if (! $coupon->is_active || ! in_array($coupon->discount_type, ['percent', 'fixed'], true)) {
            throw new CouponRejected(CouponRejection::Invalid);
        }

        $now = now();

        if (($coupon->starts_at !== null && $now->lt($coupon->starts_at))
            || ($coupon->ends_at !== null && $now->gt($coupon->ends_at))) {
            throw new CouponRejected(CouponRejection::Expired);
        }

        if ($coupon->first_order_only && $user !== null) {
            $hasPaidOrder = Order::query()
                ->where('user_id', $user->id)
                ->whereNotNull('paid_at')
                ->exists();

            if ($hasPaidOrder) {
                throw new CouponRejected(CouponRejection::FirstOrderOnly);
            }
        }

        if ($coupon->usage_limit !== null
            && $this->activeRedemptionsCount($coupon) >= $coupon->usage_limit) {
            throw new CouponRejected(CouponRejection::Limit);
        }

        if ($coupon->per_user_limit !== null
            && $user !== null
            && $this->activeRedemptionsCount($coupon, $user->id) >= $coupon->per_user_limit) {
            throw new CouponRejected(CouponRejection::Limit);
        }

        /** @var list<int> $targetCategoryIds */
        $targetCategoryIds = $coupon->relationLoaded('targets')
            ? $coupon->targets->where('target_type', CouponTarget::TYPE_CATEGORY)->pluck('target_id')->map(fn ($id): int => (int) $id)->all()
            : $coupon->targets()->where('target_type', CouponTarget::TYPE_CATEGORY)->pluck('target_id')->map(fn ($id): int => (int) $id)->all();

        /** @var list<int> $targetProductIds */
        $targetProductIds = $coupon->relationLoaded('targets')
            ? $coupon->targets->where('target_type', CouponTarget::TYPE_PRODUCT)->pluck('target_id')->map(fn ($id): int => (int) $id)->all()
            : $coupon->targets()->where('target_type', CouponTarget::TYPE_PRODUCT)->pluck('target_id')->map(fn ($id): int => (int) $id)->all();

        /** @var list<DiscountLine> $eligibleLines */
        $eligibleLines = [];
        $eligibleBase = 0;

        // A coupon with no scope is cart-wide. Coupons predating the targeting
        // migration take the column default, and a model built in memory has no
        // value at all - falling through to `default` would silently reject a
        // perfectly good coupon as invalid.
        $scope = $coupon->scope ?? Coupon::SCOPE_ORDER;

        foreach ($lines as $line) {
            $matchesScope = match ($scope) {
                Coupon::SCOPE_ORDER => true,
                Coupon::SCOPE_CATEGORY => $line->categoryId !== null && in_array($line->categoryId, $targetCategoryIds, true),
                Coupon::SCOPE_PRODUCT => $line->productId !== null && in_array($line->productId, $targetProductIds, true),
                Coupon::SCOPE_SERVICE => $coupon->service_type !== null && $line->serviceType->value === $coupon->service_type,
                default => false,
            };

            if ($coupon->excludes_promoted_items && ($linePromotionDiscounts[$line->id] ?? 0) > 0) {
                $matchesScope = false;
            }

            if ($matchesScope) {
                $eligibleLines[] = $line;
                $eligibleBase += $lineNetHalalah[$line->id] ?? 0;
            }
        }

        if ($eligibleBase < $coupon->minimum_order_halalah) {
            throw new CouponRejected(CouponRejection::Minimum, (int) $coupon->minimum_order_halalah);
        }

        if ($eligibleLines === [] || $eligibleBase === 0) {
            throw new CouponRejected(CouponRejection::Invalid);
        }

        $discount = $this->discountFor($coupon, $eligibleBase);
        $allocations = $this->allocateLargestRemainder($discount, $eligibleBase, $eligibleLines, $lineNetHalalah, $lines);

        return new AppliedCoupon(
            couponId: (int) $coupon->id,
            code: (string) $coupon->code,
            discountType: (string) $coupon->discount_type,
            discountHalalah: $discount,
            allocations: $allocations,
        );
    }

    /**
     * Compute coupon total discount for an eligible base.
     */
    private function discountFor(Coupon $coupon, int $eligibleBaseHalalah): int
    {
        if ($eligibleBaseHalalah < 1 || $coupon->value < 1) {
            return 0;
        }

        if ($coupon->discount_type === 'percent') {
            $discount = intdiv($eligibleBaseHalalah * (int) $coupon->value, 100);

            if ($coupon->maximum_discount_halalah !== null) {
                $discount = min($discount, (int) $coupon->maximum_discount_halalah);
            }

            return min($discount, $eligibleBaseHalalah);
        }

        return min((int) $coupon->value, $eligibleBaseHalalah);
    }

    /**
     * Allocate the computed coupon discount across eligible lines via largest-remainder.
     *
     * Invariant: sum($allocations) === $totalDiscount.
     *
     * @param  list<DiscountLine>  $eligibleLines
     * @param  array<int|string, int>  $lineNetHalalah
     * @param  list<DiscountLine>  $allLines
     * @return array<int|string, int>
     */
    private function allocateLargestRemainder(
        int $totalDiscount,
        int $eligibleBase,
        array $eligibleLines,
        array $lineNetHalalah,
        array $allLines,
    ): array {
        $allocations = [];

        foreach ($allLines as $line) {
            $allocations[$line->id] = 0;
        }

        if ($totalDiscount < 1 || $eligibleBase < 1 || $eligibleLines === []) {
            return $allocations;
        }

        $integerParts = [];
        $remainders = [];
        $sumInteger = 0;

        foreach ($eligibleLines as $line) {
            $weight = $lineNetHalalah[$line->id] ?? 0;
            $product = $totalDiscount * $weight;
            $q = intdiv($product, $eligibleBase);
            $r = $product % $eligibleBase;

            $integerParts[$line->id] = $q;
            $remainders[$line->id] = $r;
            $sumInteger += $q;
            $allocations[$line->id] = $q;
        }

        $remainderHalalah = $totalDiscount - $sumInteger;

        if ($remainderHalalah > 0) {
            $sortedLines = $eligibleLines;
            usort($sortedLines, function (DiscountLine $a, DiscountLine $b) use ($remainders): int {
                $diff = $remainders[$b->id] <=> $remainders[$a->id];
                if ($diff !== 0) {
                    return $diff;
                }

                return strcmp((string) $a->id, (string) $b->id);
            });

            for ($i = 0; $i < $remainderHalalah && $i < count($sortedLines); $i++) {
                $lineId = $sortedLines[$i]->id;
                $allocations[$lineId]++;
            }
        }

        assert(
            array_sum($allocations) === $totalDiscount,
            "Largest-remainder allocation drifted: expected {$totalDiscount}, got ".array_sum($allocations)
        );

        return $allocations;
    }

    /**
     * Count active coupon redemptions toward campaign and per-user limits.
     *
     * Reasoning: Redemptions attached to orders with OrderStatus::Cancelled are
     * explicitly excluded because cancelled orders release their coupon reservation,
     * allowing the customer or campaign limit to be reused. Orders in PendingPayment
     * (or any non-cancelled status) DO count to prevent double-spending during
     * live checkout.
     */
    private function activeRedemptionsCount(Coupon $coupon, ?int $userId = null): int
    {
        $query = $coupon->redemptions()
            ->join('orders', 'coupon_redemptions.order_id', '=', 'orders.id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value);

        if ($userId !== null) {
            $query->where('coupon_redemptions.user_id', $userId);
        }

        return $query->count();
    }

    /**
     * Convert Cart items into DiscountLine DTOs.
     *
     * @return list<DiscountLine>
     */
    private function cartToLines(Cart $cart): array
    {
        $lines = [];

        foreach ($cart->items as $item) {
            /** @var CartItem $item */
            $variant = $item->productVariant;
            $product = $variant?->product;

            $lines[] = new DiscountLine(
                id: (int) $item->id,
                categoryId: $product?->category_id,
                productId: $product?->id,
                serviceType: $variant->service_type ?? ServiceType::Coins,
                basePriceHalalah: (int) $item->total_halalah,
                quantity: (int) $item->quantity,
            );
        }

        return $lines;
    }

    /**
     * Convert PlaceOrder snapshots into DiscountLine DTOs.
     *
     * @param  iterable<int|string, array<string, mixed>>  $snapshots
     * @return list<DiscountLine>
     */
    private function snapshotsToLines(iterable $snapshots): array
    {
        $lines = [];

        foreach ($snapshots as $index => $snapshot) {
            /** @var ProductVariant $variant */
            $variant = $snapshot['variant'];
            /** @var Product $product */
            $product = $variant->product;
            /** @var ServiceType $serviceType */
            $serviceType = $snapshot['service_type'];

            $lines[] = new DiscountLine(
                id: $index,
                categoryId: $product->category_id,
                productId: $product->id,
                serviceType: $serviceType,
                basePriceHalalah: (int) $snapshot['total_halalah'],
                quantity: (int) $snapshot['quantity'],
            );
        }

        return $lines;
    }

    private function loadCoupon(int $couponId): ?Coupon
    {
        /** @var Coupon|null $coupon */
        $coupon = Coupon::query()
            ->whereKey($couponId)
            ->with('targets')
            ->first();

        return $coupon;
    }
}
