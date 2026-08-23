<?php

require_once __DIR__.'/LoyaltyFixtures.php';

use App\Actions\Checkout\RefundPaylinkOrder;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Loyalty\Actions\AccrueOrderCashback;
use App\Loyalty\Actions\ReverseOrderCashback;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use Laravel\Fortify\Fortify;

beforeEach(function (): void {
    config()->set('store.features.loyalty_enabled', true);
    config()->set('services.paylink', [
        'environment' => 'test',
        'api_id' => 'merchant-id',
        'secret_key' => 'merchant-secret',
        'webhook_token' => 'webhook-secret',
        'partner_profile_no' => 'profile-no',
        'partner_api_key' => 'partner-api-key',
        'merchant_lookup_key' => 'accountNo',
        'merchant_lookup_value' => '123456',
    ]);
    loyaltySeedTiers();
});

function loyaltyRefundAdmin(): User
{
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('LOYALTYTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $admin;
}

function loyaltyFakePaylinkRefund(Order $order): void
{
    Http::fake([
        'https://restpilot.paylink.sa/api/partner/auth' => Http::response(['id_token' => 'partner-token']),
        'https://restpilot.paylink.sa/rest/partner/v2/merchant/accountNo/123456/refund' => Http::response([
            'id' => 237,
            'orderNumber' => $order->order_number,
            'amount' => ((int) $order->total_halalah) / 100,
            'currency' => 'SAR',
            'refundReason' => 'Customer request.',
            'createDatetime' => 1716194603030,
        ]),
    ]);
}

function loyaltyRefundableOrder(User $customer, OrderStatus $status, int $total = 20_000): Order
{
    $order = Order::factory()->for($customer)->create([
        'status' => $status,
        'completed_at' => $status === OrderStatus::Completed ? now() : null,
        'payment_halalah' => $total,
        'total_halalah' => $total,
        'paid_at' => now(),
    ]);
    $order->items()->create([
        'sku' => 'AUT-LOYALTY-REFUND',
        'name_ar' => 'عملة',
        'name_en' => 'Coins',
        'service_type' => 'coins',
        'platform' => 'playstation',
        'status' => OrderItemStatus::Received,
        'quantity' => 1,
        'unit_price_halalah' => $total,
        'subtotal_halalah' => $total,
        'discount_halalah' => 0,
        'total_halalah' => $total,
    ]);
    loyaltySettledPayment($order, PaymentStatus::Paid, $total);

    return $order;
}

test('completing a refund on an accrued order writes a reversal that restores the balance', function (): void {
    $customer = User::factory()->create();
    $admin = loyaltyRefundAdmin();
    $order = loyaltyRefundableOrder($customer, OrderStatus::Completed);
    loyaltyFakePaylinkRefund($order);

    $accrued = app(AccrueOrderCashback::class)->execute($order);

    expect($accrued)->toBeInstanceOf(WalletEntry::class)
        ->and(WalletAccount::query()->sole()->balance_halalah)->toBe(400);

    $refund = app(RefundPaylinkOrder::class)->execute($order->fresh(), 'Customer request.', $admin);

    expect($refund->status)->toBe('completed');

    $reversal = WalletEntry::query()->where('reference', "cashback-reversal:{$refund->id}")->sole();

    expect($reversal->type->value)->toBe('cashback_reversal')
        ->and($reversal->amount_halalah)->toBe(400)
        ->and($reversal->balance_after_halalah)->toBe(0)
        ->and($reversal->refund_id)->toBe($refund->id)
        ->and((int) WalletAccount::query()->sole()->balance_halalah)->toBe(0)
        ->and(WalletEntry::query()->count())->toBe(2);
});

test('refunding a never-completed order leaves the ledger untouched', function (): void {
    $customer = User::factory()->create();
    $admin = loyaltyRefundAdmin();
    $order = loyaltyRefundableOrder($customer, OrderStatus::Received);
    loyaltyFakePaylinkRefund($order);

    $refund = app(RefundPaylinkOrder::class)->execute($order, 'Customer request.', $admin);

    expect($refund->status)->toBe('completed')
        ->and(WalletEntry::query()->count())->toBe(0)
        ->and(WalletAccount::query()->count())->toBe(0);
});

test('a replayed reversal returns the existing entry without changing the balance', function (): void {
    $customer = User::factory()->create();
    $admin = loyaltyRefundAdmin();
    $order = loyaltyRefundableOrder($customer, OrderStatus::Completed);
    loyaltyFakePaylinkRefund($order);

    app(AccrueOrderCashback::class)->execute($order);
    $refund = app(RefundPaylinkOrder::class)->execute($order->fresh(), 'Customer request.', $admin);

    $reversal = app(ReverseOrderCashback::class)->execute($refund->fresh());

    expect($reversal?->reference)->toBe("cashback-reversal:{$refund->id}")
        ->and(WalletEntry::query()->count())->toBe(2)
        ->and((int) WalletAccount::query()->sole()->balance_halalah)->toBe(0);
});
