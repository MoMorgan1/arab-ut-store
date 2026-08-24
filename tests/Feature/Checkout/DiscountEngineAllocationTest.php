<?php

use App\Checkout\DiscountEngine;
use App\Checkout\DiscountLine;
use App\Enums\ServiceType;
use App\Marketing\PromotionPricing;
use App\Models\Coupon;

test('largest remainder allocation distributes discount exactly without drifting across 4 lines', function (): void {
    $promotionPricing = new PromotionPricing;
    $engine = new DiscountEngine($promotionPricing);

    $lines = [
        new DiscountLine('line-1', null, null, ServiceType::Coins, 1000),
        new DiscountLine('line-2', null, null, ServiceType::Coins, 2000),
        new DiscountLine('line-3', null, null, ServiceType::Coins, 3000),
        new DiscountLine('line-4', null, null, ServiceType::Coins, 4000),
    ];

    // Total eligible = 10,000 halalah. Coupon discount = 333 halalah fixed.
    $coupon = new Coupon([
        'id' => 1,
        'code' => 'TEST333',
        'discount_type' => 'fixed',
        'value' => 333,
        'minimum_order_halalah' => 0,
        'is_active' => true,
        'scope' => Coupon::SCOPE_ORDER,
    ]);

    $result = $engine->calculate($lines, $coupon);

    expect($result->totalDiscountHalalah)->toBe(333)
        ->and($result->payableTotalHalalah)->toBe(9667)
        ->and(array_sum($result->lineCouponDiscounts))->toBe(333);

    // Verify each line part
    // line 1: 333 * 1000 / 10000 = 33.3 -> 33
    // line 2: 333 * 2000 / 10000 = 66.6 -> 66 + 1 = 67
    // line 3: 333 * 3000 / 10000 = 99.9 -> 99 + 1 = 100
    // line 4: 333 * 4000 / 10000 = 133.2 -> 133
    // Total = 33 + 67 + 100 + 133 = 333 exactly!
    expect($result->lineCouponDiscounts['line-1'])->toBe(33)
        ->and($result->lineCouponDiscounts['line-2'])->toBe(67)
        ->and($result->lineCouponDiscounts['line-3'])->toBe(100)
        ->and($result->lineCouponDiscounts['line-4'])->toBe(133);
});

test('percent coupon capped by maximum_discount distributes capped amount by largest remainder', function (): void {
    $promotionPricing = new PromotionPricing;
    $engine = new DiscountEngine($promotionPricing);

    $lines = [
        new DiscountLine('line-a', null, null, ServiceType::Sbc, 7000),
        new DiscountLine('line-b', null, null, ServiceType::Sbc, 3000),
    ];

    // 50% of 10,000 = 5,000, capped at 1,000 maximum discount
    $coupon = new Coupon([
        'id' => 2,
        'code' => 'CAP50',
        'discount_type' => 'percent',
        'value' => 50,
        'maximum_discount_halalah' => 1000,
        'minimum_order_halalah' => 0,
        'is_active' => true,
        'scope' => Coupon::SCOPE_ORDER,
    ]);

    $result = $engine->calculate($lines, $coupon);

    expect($result->totalDiscountHalalah)->toBe(1000)
        ->and($result->lineCouponDiscounts['line-a'])->toBe(700)
        ->and($result->lineCouponDiscounts['line-b'])->toBe(300)
        ->and(array_sum($result->lineCouponDiscounts))->toBe(1000);
});

test('discount calculation floors division in halalah and never yields negative payable totals', function (): void {
    $promotionPricing = new PromotionPricing;
    $engine = new DiscountEngine($promotionPricing);

    $lines = [
        new DiscountLine('single', null, null, ServiceType::Coins, 999),
    ];

    // 15% of 999 = 149.85 -> floor 149
    $coupon = new Coupon([
        'id' => 3,
        'code' => 'FLOOR15',
        'discount_type' => 'percent',
        'value' => 15,
        'minimum_order_halalah' => 0,
        'is_active' => true,
        'scope' => Coupon::SCOPE_ORDER,
    ]);

    $result = $engine->calculate($lines, $coupon);

    expect($result->totalDiscountHalalah)->toBe(149)
        ->and($result->payableTotalHalalah)->toBe(850);
});
