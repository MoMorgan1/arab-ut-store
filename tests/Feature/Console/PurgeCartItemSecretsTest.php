<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;

function cartSecretForRetention(DateTimeInterface $retainedUntil): CartItemSecret
{
    $cart = Cart::create([
        'user_id' => User::factory()->create()->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    $item = CartItem::create([
        'cart_id' => $cart->id,
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'quantity' => 1,
        'unit_price_halalah' => 500,
        'total_halalah' => 500,
        'configuration' => ['service_type' => 'coins'],
    ]);
    $secret = new CartItemSecret([
        'cart_item_id' => $item->id,
        'masked_summary' => [
            'email' => 'r***@example.test',
            'has_password' => true,
            'backup_code_count' => 5,
        ],
        'retained_until' => $retainedUntil,
    ]);
    $secret->encrypted_payload = [
        'ea_email' => 'retention-sentinel@example.test',
        'ea_password' => 'Retention Password Sentinel',
        'backup_codes' => ['82000001', '82000002', '82000003', '82000004', '82000005'],
    ];
    $secret->save();

    return $secret;
}

test('due cart secrets are purged idempotently without deleting safe cart lines', function () {
    $due = cartSecretForRetention(now()->subMinute());
    $future = cartSecretForRetention(now()->addMinute());

    $this->artisan('cart-secrets:purge')->assertSuccessful();

    expect($due->fresh()->encrypted_payload)->toBeNull()
        ->and($due->fresh()->masked_summary)->toBeNull()
        ->and($due->fresh()->deleted_at)->not->toBeNull()
        ->and($due->cartItem()->exists())->toBeTrue()
        ->and($future->fresh()->encrypted_payload)->not->toBeNull()
        ->and($future->fresh()->masked_summary)->not->toBeNull()
        ->and($future->fresh()->deleted_at)->toBeNull();

    $deletedAt = $due->fresh()->deleted_at;
    $this->artisan('cart-secrets:purge')->assertSuccessful();
    expect($due->fresh()->deleted_at->equalTo($deletedAt))->toBeTrue();
});

test('cart secret retention defaults to 24 hours and purge is scheduled hourly', function () {
    expect(config('coins.cart.secret_retention_hours'))->toBe(24);

    $events = collect(app(Schedule::class)->events());
    $event = $events->first(fn ($event): bool => str_contains($event->command ?? '', 'cart-secrets:purge'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');
});
