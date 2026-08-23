<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\LoyaltyTier;
use App\Models\Order;
use App\Models\User;

/**
 * Seeds the four owner-approved tiers (same values as loyalty:seed-tiers).
 */
function loyaltySeedTiers(): void
{
    foreach ([
        ['key' => 'bronze', 'name_ar' => 'برونزي', 'name_en' => 'Bronze', 'rank' => 1, 'minimum_lifetime_spend_halalah' => 0, 'cashback_basis_points' => 200],
        ['key' => 'silver', 'name_ar' => 'فضي', 'name_en' => 'Silver', 'rank' => 2, 'minimum_lifetime_spend_halalah' => 50_000, 'cashback_basis_points' => 300],
        ['key' => 'gold', 'name_ar' => 'ذهبي', 'name_en' => 'Gold', 'rank' => 3, 'minimum_lifetime_spend_halalah' => 200_000, 'cashback_basis_points' => 500],
        ['key' => 'platinum', 'name_ar' => 'بلاتيني', 'name_en' => 'Platinum', 'rank' => 4, 'minimum_lifetime_spend_halalah' => 1_000_000, 'cashback_basis_points' => 700],
    ] as $tier) {
        LoyaltyTier::query()->create($tier);
    }
}

/**
 * A completed SAR order with a settled gateway payment covering the total.
 *
 * @param  array<string, mixed>  $attributes
 */
function loyaltyPaidOrder(User $user, array $attributes = []): Order
{
    $order = Order::factory()->for($user)->create([
        'status' => OrderStatus::Completed,
        'completed_at' => now(),
        'payment_halalah' => 10_000,
        'total_halalah' => 10_000,
        ...$attributes,
    ]);

    loyaltySettledPayment($order, PaymentStatus::Paid, (int) $order->payment_halalah);

    return $order;
}

function loyaltySettledPayment(Order $order, PaymentStatus $status, int $capturedHalalah): void
{
    $order->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => (string) str()->ulid(),
        'status' => $status,
        'currency' => 'SAR',
        'amount_halalah' => max($capturedHalalah, 0),
        'captured_halalah' => $capturedHalalah,
        'refunded_halalah' => 0,
        'idempotency_key' => 'paylink:'.hash('sha256', $order->id.'|'.(string) str()->ulid()),
    ]);
}
