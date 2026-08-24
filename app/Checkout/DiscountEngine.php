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
use App\Models\Promotion;
use App\Models\PromotionComponent;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Unified Discount Engine for Arab UT Store.
 *
 * Coordinates item promotions, cart promotions, and cart coupons in a strict, deterministic sequence:
 * 1. Item promotions, per line, unchanged. Best single promotion per line wins; they never stack
 *    with each other. Computed independently on the base price of each line.
 * 2. Cart promotions (new). Evaluate every active nth_item and bundle promotion against the cart.
 *    Compute what each would save. Exactly one applies: the one saving the customer most.
 *    Ties break on lower promotion id. A bundle and a buy-X offer never compound.
 *    Cart promotions compute on the promotion-net line prices from stage 1.
 *    When applies_to_promoted_items is false (the default), lines that already took an item
 *    promotion are excluded from qualifying and from being discounted.
 * 3. Coupon, unchanged, on what is left after stage 1 and stage 2.
 *    Allocated across eligible lines via largest-remainder to ensure exact halalah totals.
 * 4. Loyalty cashback (accrued post-checkout on total - wallet).
 * 5. Wallet tender (applied last against the final payable amount).
 */
final class DiscountEngine
{
    /** @var Collection<int, Promotion>|null */
    private ?Collection $activeCartPromotions = null;

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
        $stage1Promotions = [];
        $stage1LineDiscounts = [];
        $lineNetHalalahStage1 = [];

        // Stage 1: Item promotions per line (unchanged)
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
            $stage1Promotions[$line->id] = $promo;
            $stage1LineDiscounts[$line->id] = $promoDiscount;
            $lineNetHalalahStage1[$line->id] = $net;
        }

        // Stage 2: Cart promotions (nth_item and bundle)
        $winningCartPromotion = null;
        $winningCartDiscount = 0;
        $winningCartAllocations = [];

        foreach ($lines as $line) {
            $winningCartAllocations[$line->id] = 0;
        }

        foreach ($this->activeCartPromotions() as $cartPromotion) {
            if ($cartPromotion->mechanic === Promotion::MECHANIC_NTH_ITEM) {
                [$discount, $allocations] = $this->evaluateNthItemPromotion(
                    $cartPromotion,
                    $lines,
                    $lineNetHalalahStage1,
                    $stage1LineDiscounts,
                );
            } elseif ($cartPromotion->mechanic === Promotion::MECHANIC_BUNDLE) {
                [$discount, $allocations] = $this->evaluateBundlePromotion(
                    $cartPromotion,
                    $lines,
                    $lineNetHalalahStage1,
                    $stage1LineDiscounts,
                );
            } else {
                continue;
            }

            if ($discount > $winningCartDiscount) {
                $winningCartPromotion = $cartPromotion;
                $winningCartDiscount = $discount;
                $winningCartAllocations = $allocations;
            } elseif ($discount > 0 && $discount === $winningCartDiscount && $winningCartPromotion !== null) {
                if ($cartPromotion->id < $winningCartPromotion->id) {
                    $winningCartPromotion = $cartPromotion;
                    $winningCartDiscount = $discount;
                    $winningCartAllocations = $allocations;
                }
            }
        }

        $linePromotions = [];
        $linePromotionDiscounts = [];
        $lineNetHalalah = [];
        $totalPromotionDiscount = 0;

        foreach ($lines as $line) {
            $itemDiscount = $stage1LineDiscounts[$line->id] ?? 0;
            $cartDiscount = $winningCartAllocations[$line->id] ?? 0;
            $combinedDiscount = $itemDiscount + $cartDiscount;
            $netAfterPromotions = max(0, $line->basePriceHalalah - $combinedDiscount);

            $linePromotionDiscounts[$line->id] = $combinedDiscount;
            $lineNetHalalah[$line->id] = $netAfterPromotions;
            $totalPromotionDiscount += $combinedDiscount;

            if ($cartDiscount > 0 && $winningCartPromotion instanceof Promotion) {
                $linePromotions[$line->id] = new PromotionPrice(
                    promotion: $winningCartPromotion,
                    baseHalalah: $line->basePriceHalalah,
                    discountHalalah: $combinedDiscount,
                    discountedHalalah: $netAfterPromotions,
                );
            } elseif ($itemDiscount > 0 && $stage1Promotions[$line->id] instanceof PromotionPrice) {
                $linePromotions[$line->id] = $stage1Promotions[$line->id];
            } else {
                $linePromotions[$line->id] = null;
            }
        }

        $promotedSubtotal = max(0, $baseSubtotal - $totalPromotionDiscount);

        // Stage 3: Cart coupon
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
        $totalDiscount = $totalPromotionDiscount + $couponDiscount;
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
            appliedCartPromotion: $winningCartPromotion,
            cartPromotionDiscountHalalah: $winningCartDiscount,
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
        $result = $this->calculate($lines, $coupon, $user, throwOnCouponRejection: true);

        if (! $result->appliedCoupon instanceof AppliedCoupon) {
            throw new CouponRejected(CouponRejection::Invalid);
        }

        return $result->appliedCoupon;
    }

    /**
     * Evaluate an nth_item promotion against cart lines.
     *
     * @param  list<DiscountLine>  $lines
     * @param  array<int|string, int>  $lineNetHalalah
     * @param  array<int|string, int>  $lineItemDiscounts
     * @return array{0: int, 1: array<int|string, int>} [totalDiscount, allocations]
     */
    private function evaluateNthItemPromotion(
        Promotion $promotion,
        array $lines,
        array $lineNetHalalah,
        array $lineItemDiscounts,
    ): array {
        $allocations = [];
        foreach ($lines as $line) {
            $allocations[$line->id] = 0;
        }

        // A stored 0 is an admin saying "none", so the promotion must not apply.
        // Coercing it to 1 turned "no free items" into a free item, and coercing
        // max_applications 0 to null turned "off" into unlimited - the opposite of
        // the intent in both cases. Null still means "not configured" -> default.
        if ($promotion->buy_quantity !== null && (int) $promotion->buy_quantity < 1) {
            return [0, $allocations];
        }

        if ($promotion->get_quantity !== null && (int) $promotion->get_quantity < 1) {
            return [0, $allocations];
        }

        if ($promotion->max_applications !== null && (int) $promotion->max_applications < 1) {
            return [0, $allocations];
        }

        $buyQty = $promotion->buy_quantity !== null ? (int) $promotion->buy_quantity : 1;
        $getQty = $promotion->get_quantity !== null ? (int) $promotion->get_quantity : 1;
        $groupSize = $buyQty + $getQty;

        /** @var list<DiscountLine> $qualifyingLines */
        $qualifyingLines = [];
        foreach ($lines as $line) {
            // Stage 2 reasons in LINES, not units: "the next one" is the next
            // matching line. A quantity > 1 line would be counted once and then
            // discounted in full, comping every unit on it, so it is excluded
            // until this stage learns to expand lines into units. Every current
            // add-to-cart action writes quantity 1, so nothing is excluded today.
            if ($line->quantity > 1) {
                continue;
            }

            if (! $promotion->applies_to_promoted_items && ($lineItemDiscounts[$line->id] ?? 0) > 0) {
                continue;
            }

            $matchesScope = match ($promotion->scope) {
                Promotion::SCOPE_ALL => true,
                Promotion::SCOPE_CATEGORY => $line->categoryId !== null && $promotion->category_id === $line->categoryId,
                Promotion::SCOPE_SERVICE => $promotion->service_type !== null && $line->serviceType->value === $promotion->service_type,
                Promotion::SCOPE_PRODUCT => $line->productId !== null && $promotion->product_id === $line->productId,
                default => false,
            };

            if ($matchesScope) {
                $qualifyingLines[] = $line;
            }
        }

        if ($qualifyingLines === []) {
            return [0, $allocations];
        }

        $qualifyingScope = $promotion->qualifying_scope ?? Promotion::QUALIFYING_SCOPE_ANY;

        /** @var array<string, list<DiscountLine>> $buckets */
        $buckets = [];
        foreach ($qualifyingLines as $line) {
            $bucketKey = match ($qualifyingScope) {
                Promotion::QUALIFYING_SCOPE_SAME_PRODUCT => $line->productId !== null ? 'prod_'.$line->productId : null,
                Promotion::QUALIFYING_SCOPE_SAME_CATEGORY => $line->categoryId !== null ? 'cat_'.$line->categoryId : null,
                Promotion::QUALIFYING_SCOPE_SAME_SERVICE => 'svc_'.$line->serviceType->value,
                Promotion::QUALIFYING_SCOPE_ANY, 'all' => 'any',
                default => 'any',
            };

            if ($bucketKey !== null) {
                $buckets[$bucketKey][] = $line;
            }
        }

        $discountTarget = $promotion->discount_target ?? Promotion::TARGET_CHEAPEST;

        /** @var list<array{discount: int, discountedLines: list<array{line: DiscountLine, discount: int}>}> $candidateGroups */
        $candidateGroups = [];

        foreach ($buckets as $bucketLines) {
            $count = count($bucketLines);
            if ($count < $groupSize) {
                continue;
            }

            if ($discountTarget === Promotion::TARGET_MOST_EXPENSIVE) {
                usort($bucketLines, function (DiscountLine $a, DiscountLine $b) use ($lineNetHalalah): int {
                    $priceA = $lineNetHalalah[$a->id] ?? 0;
                    $priceB = $lineNetHalalah[$b->id] ?? 0;
                    $diff = $priceB <=> $priceA;
                    if ($diff !== 0) {
                        return $diff;
                    }

                    return strcmp((string) $a->id, (string) $b->id);
                });
            } else {
                usort($bucketLines, function (DiscountLine $a, DiscountLine $b) use ($lineNetHalalah): int {
                    $priceA = $lineNetHalalah[$a->id] ?? 0;
                    $priceB = $lineNetHalalah[$b->id] ?? 0;
                    $diff = $priceA <=> $priceB;
                    if ($diff !== 0) {
                        return $diff;
                    }

                    return strcmp((string) $a->id, (string) $b->id);
                });
            }

            $numGroups = intdiv($count, $groupSize);
            for ($g = 0; $g < $numGroups; $g++) {
                $groupSlice = array_slice($bucketLines, $g * $groupSize, $groupSize);
                $discountedSlice = array_slice($groupSlice, 0, $getQty);

                $groupDiscount = 0;
                $groupDiscountedLines = [];
                foreach ($discountedSlice as $discLine) {
                    $lineNet = $lineNetHalalah[$discLine->id] ?? 0;
                    $lineDisc = $this->calculateLineDiscount($promotion, $lineNet);
                    $groupDiscount += $lineDisc;
                    $groupDiscountedLines[] = ['line' => $discLine, 'discount' => $lineDisc];
                }

                $candidateGroups[] = [
                    'discount' => $groupDiscount,
                    'discountedLines' => $groupDiscountedLines,
                ];
            }
        }

        if ($candidateGroups === []) {
            return [0, $allocations];
        }

        usort($candidateGroups, fn (array $a, array $b): int => $b['discount'] <=> $a['discount']);

        // A 0 here already returned above, so null is the only "uncapped" value.
        $maxApps = $promotion->max_applications !== null
            ? (int) $promotion->max_applications
            : null;

        $appliedGroups = $maxApps !== null ? array_slice($candidateGroups, 0, $maxApps) : $candidateGroups;

        $totalDiscount = 0;
        foreach ($appliedGroups as $group) {
            foreach ($group['discountedLines'] as $item) {
                /** @var DiscountLine $dLine */
                $dLine = $item['line'];
                $dDisc = (int) $item['discount'];
                $allocations[$dLine->id] += $dDisc;
                $totalDiscount += $dDisc;
            }
        }

        return [$totalDiscount, $allocations];
    }

    /**
     * Evaluate a bundle promotion against cart lines.
     *
     * @param  list<DiscountLine>  $lines
     * @param  array<int|string, int>  $lineNetHalalah
     * @param  array<int|string, int>  $lineItemDiscounts
     * @return array{0: int, 1: array<int|string, int>} [totalDiscount, allocations]
     */
    private function evaluateBundlePromotion(
        Promotion $promotion,
        array $lines,
        array $lineNetHalalah,
        array $lineItemDiscounts,
    ): array {
        $allocations = [];
        foreach ($lines as $line) {
            $allocations[$line->id] = 0;
        }

        $components = $promotion->relationLoaded('components')
            ? $promotion->components
            : $promotion->components()->get();

        if ($components->isEmpty() || $promotion->bundle_price_halalah === null) {
            return [0, $allocations];
        }

        /** @var list<DiscountLine> $matchedComponentLines */
        $matchedComponentLines = [];
        $usedLineIds = [];

        foreach ($components as $component) {
            /** @var PromotionComponent $component */
            $reqProduct = $component->product_id;
            $reqQuantity = $component->quantity > 0 ? (int) $component->quantity : 1;

            if ($reqProduct === null) {
                return [0, $allocations];
            }

            /** @var list<DiscountLine> $candidateLines */
            $candidateLines = [];
            foreach ($lines as $line) {
                if (in_array($line->id, $usedLineIds, true)) {
                    continue;
                }

                if (! $promotion->applies_to_promoted_items && ($lineItemDiscounts[$line->id] ?? 0) > 0) {
                    continue;
                }

                if ($line->productId === $reqProduct) {
                    $candidateLines[] = $line;
                }
            }

            if (count($candidateLines) < $reqQuantity) {
                return [0, $allocations];
            }

            usort($candidateLines, function (DiscountLine $a, DiscountLine $b) use ($lineNetHalalah): int {
                $priceA = $lineNetHalalah[$a->id] ?? 0;
                $priceB = $lineNetHalalah[$b->id] ?? 0;
                $diff = $priceB <=> $priceA;
                if ($diff !== 0) {
                    return $diff;
                }

                return strcmp((string) $a->id, (string) $b->id);
            });

            $selected = array_slice($candidateLines, 0, $reqQuantity);
            foreach ($selected as $sLine) {
                $matchedComponentLines[] = $sLine;
                $usedLineIds[] = $sLine->id;
            }
        }

        $partsTotal = 0;
        foreach ($matchedComponentLines as $mLine) {
            $partsTotal += $lineNetHalalah[$mLine->id] ?? 0;
        }

        $bundlePrice = (int) $promotion->bundle_price_halalah;
        $discount = $partsTotal - $bundlePrice;

        if ($discount <= 0) {
            return [0, $allocations];
        }

        $allocations = $this->allocateLargestRemainder(
            $discount,
            $partsTotal,
            $matchedComponentLines,
            $lineNetHalalah,
            $lines,
        );

        return [$discount, $allocations];
    }

    /**
     * Compute line discount for percent/fixed floored with intdiv, never below zero, never more than line.
     */
    private function calculateLineDiscount(Promotion $promotion, int $netHalalah): int
    {
        if ($netHalalah < 1 || $promotion->value < 1) {
            return 0;
        }

        $discount = $promotion->discount_type === 'percent'
            ? intdiv($netHalalah * (int) $promotion->value, 100)
            : (int) $promotion->value;

        return max(0, min($discount, $netHalalah));
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
            // Clamp here rather than trusting a CHECK constraint: sqlite has none,
            // and a row written by a seeder, a console command or an import never
            // passes the form request. Without this, value = 150 silently becomes
            // a 100% comp on every order instead of being refused.
            $percent = min(100, max(0, (int) $coupon->value));
            $discount = intdiv($eligibleBaseHalalah * $percent, 100);

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
     */
    private function activeRedemptionsCount(Coupon $coupon, ?int $userId = null): int
    {
        // lockForUpdate() is load-bearing, not decoration. PlaceOrder issues a
        // non-locking $cart->load() before it locks the coupon row, and on InnoDB
        // at REPEATABLE READ that first plain SELECT opens the transaction's read
        // view. A plain count() here would therefore still see the pre-lock
        // snapshot: two concurrent checkouts would serialize on the coupon lock,
        // both count zero redemptions, and both redeem a usage_limit=1 coupon.
        // A locking read is what actually crosses the snapshot.
        $query = $coupon->redemptions()
            ->join('orders', 'coupon_redemptions.order_id', '=', 'orders.id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->lockForUpdate();

        if ($userId !== null) {
            $query->where('coupon_redemptions.user_id', $userId);
        }

        return $query->count();
    }

    /**
     * @return Collection<int, Promotion>
     */
    private function activeCartPromotions(): Collection
    {
        return $this->activeCartPromotions ??= Promotion::query()
            ->active()
            ->whereIn('mechanic', [Promotion::MECHANIC_NTH_ITEM, Promotion::MECHANIC_BUNDLE])
            ->with('components')
            ->orderBy('id')
            ->get();
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
