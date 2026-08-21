<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItemSecret;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

test('Admin overview Inertia props never serialize credentials provider metadata or private actor fields', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'UTC'));
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
    ]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINOVERVIEWTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $order = Order::factory()->for($admin)->create([
        'order_number' => 'AUT-PRIVACY-1001',
        'status' => OrderStatus::Received,
        'placed_at' => now()->subHour(),
    ]);
    $item = $order->items()->create([
        'sku' => 'SYNTHETIC-PRIVACY',
        'name_ar' => 'عنصر اختباري',
        'name_en' => 'Synthetic item',
        'service_type' => 'coins',
        'platform' => 'playstation',
        'status' => 'received',
        'quantity' => 1,
        'unit_price_halalah' => 100,
        'subtotal_halalah' => 100,
        'discount_halalah' => 0,
        'total_halalah' => 100,
    ]);
    $secret = new OrderItemSecret([
        'order_item_id' => $item->id,
        'masked_summary' => ['account' => 's***t'],
    ]);
    $secret->forceFill([
        'encrypted_payload' => ['credential' => 'admin-overview-credential-sentinel'],
    ])->save();
    $order->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => (string) Str::uuid(),
        'status' => PaymentStatus::Paid,
        'currency' => 'SAR',
        'amount_halalah' => 100,
        'captured_halalah' => 100,
        'refunded_halalah' => 0,
        'idempotency_key' => (string) Str::uuid(),
        'provider_metadata' => ['providerPayload' => 'must-never-load'],
        'paid_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($admin)->get('/admin');
    $content = $response->getContent();

    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    foreach ([
        'admin-overview-credential-sentinel',
        'must-never-load',
        'encrypted_payload',
        'provider_metadata',
        'two_factor_secret',
        'two_factor_recovery_codes',
        (string) $admin->getRawOriginal('password'),
        $admin->email,
    ] as $forbiddenValue) {
        expect($content)->not->toContain($forbiddenValue);
    }
});
