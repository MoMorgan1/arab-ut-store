<?php

use App\Actions\Checkout\PlaceOrder;
use App\Enums\ServiceType;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\FulfillmentAttachment;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\ServicePriceSchedule;
use App\Models\User;
use App\ValueObjects\Cart\ManualServiceCredentials;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @return array{user: User, cart: Cart, item: CartItem, attachment: FulfillmentAttachment}
 */
function manualServiceCheckoutCart(ServiceType $service): array
{
    static $phoneSequence = 100;
    $phoneSequence++;
    $user = User::factory()->create([
        'phone' => '+9665'.str_pad((string) $phoneSequence, 8, '0', STR_PAD_LEFT),
        'phone_verified_at' => now(),
    ]);
    $sku = $service === ServiceType::FutChampions
        ? 'MANUAL_FUT_CHAMPIONS_PLAYSTATION'
        : 'MANUAL_RIVALS_PLAYSTATION';
    $variant = ProductVariant::query()->where('sku', $sku)->sole();
    $cart = Cart::create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    $configuration = [
        'service_type' => $service->value,
        'platform' => 'playstation',
        'market' => 'console',
        'pc_store' => null,
        'quoted_at' => now()->utc()->toIso8601String(),
        'price_version' => 1,
        'schedule_version' => 1,
        ...($service === ServiceType::FutChampions
            ? ['rank' => 3, 'urgent' => true, 'matches_played' => 4]
            : ['current_division' => '5', 'target_division' => 'elite']),
    ];
    $price = $service === ServiceType::FutChampions ? 21_000 : 75_000;
    $item = $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => $price,
        'total_halalah' => $price,
        'configuration' => $configuration,
    ]);
    $credentials = ManualServiceCredentials::fromValidated([
        'platform' => 'playstation',
        'playstation_email' => 'manual@example.test',
        'playstation_password' => 'Manual PS secret',
        'ea_backup_codes' => ['12345678', '23456789', '34567890'],
        'playstation_backup_codes' => ['A1B2C3', 'D4E5F6', 'Z9Y8X7'],
    ]);
    $secret = new CartItemSecret([
        'cart_item_id' => $item->id,
        'masked_summary' => $credentials->maskedSummary(),
        'retained_until' => null,
        'deleted_at' => null,
    ]);
    $secret->encrypted_payload = $credentials->payload();
    $secret->save();
    $path = 'fulfillment/squad-images/manual-checkout-'.$item->public_id.'.png';
    Storage::disk('local')->put($path, 'private squad image');
    $attachment = FulfillmentAttachment::create([
        'cart_item_id' => $item->id,
        'order_item_id' => null,
        'kind' => 'squad_image',
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'image/png',
        'bytes' => strlen('private squad image'),
        'sha256' => hash('sha256', 'private squad image'),
    ]);

    return compact('user', 'cart', 'item', 'attachment');
}

it('checks out FUT and Rivals with current server pricing and transfers private fulfillment atomically', function (ServiceType $service, int $expected) {
    $state = manualServiceCheckoutCart($service);

    $result = app(PlaceOrder::class)->execute(
        $state['user'],
        'ar',
        'manual-checkout-'.$service->value,
    );

    $orderItem = OrderItem::query()->sole();
    $orderSecret = OrderItemSecret::query()->sole();
    $attachment = $state['attachment']->fresh();
    $ciphertext = (string) DB::table('order_item_secrets')->value('encrypted_payload');
    $claimBody = (string) IdempotencyKey::query()->where('scope', 'like', 'checkout:%')->value('response_body');

    expect($result->order->total_halalah)->toBe($expected)
        ->and($result->payment->amount_halalah)->toBe($expected)
        ->and($orderItem->total_halalah)->toBe($expected)
        ->and($orderItem->service_type)->toBe($service)
        ->and($orderSecret->encrypted_payload['playstation_email'])->toBe('manual@example.test')
        ->and($ciphertext)->not->toContain('manual@example.test', 'Manual PS secret', '12345678', 'A1B2C3')
        ->and($attachment?->cart_item_id)->toBeNull()
        ->and($attachment?->order_item_id)->toBe($orderItem->id)
        ->and(FulfillmentAttachment::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBe([$state['attachment']->path])
        ->and($state['cart']->fresh()?->status)->toBe('converted')
        ->and($claimBody)->not->toContain('manual@example.test', 'password', 'backup', 'squad', 'path')
        ->and(Payment::sole()->provider_metadata)->toBeNull();
})->with([
    'FUT Champions' => [ServiceType::FutChampions, 21_000],
    'Division Rivals' => [ServiceType::Rivals, 75_000],
]);

it('rejects stale, inactive, or incomplete manual-service carts without partial conversion', function (string $failure) {
    $state = manualServiceCheckoutCart(ServiceType::FutChampions);

    match ($failure) {
        'stale schedule' => ServicePriceSchedule::query()
            ->where('service_type', ServiceType::FutChampions)
            ->update(['version' => 2]),
        'inactive schedule' => ServicePriceSchedule::query()
            ->where('service_type', ServiceType::FutChampions)
            ->update(['is_active' => false]),
        'missing secret' => $state['item']->secret()->delete(),
        'invalid secret' => tap($state['item']->secret, function (CartItemSecret $secret): void {
            $secret->encrypted_payload = ['platform' => 'playstation'];
            $secret->save();
        }),
        'missing image' => $state['attachment']->delete(),
        'missing image file' => Storage::disk('local')->delete($state['attachment']->path),
    };

    expect(fn () => app(PlaceOrder::class)->execute(
        $state['user'],
        'en',
        'manual-invalid-'.str_replace(' ', '-', $failure),
    ))->toThrow(CheckoutUnavailable::class);

    expect(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0)
        ->and(OrderItemSecret::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and($state['cart']->fresh()?->status)->toBe('active');
})->with([
    'stale schedule',
    'inactive schedule',
    'missing secret',
    'invalid secret',
    'missing image',
    'missing image file',
]);

it('rejects any manual-service price or route tampering even when the stored total was changed too', function () {
    $state = manualServiceCheckoutCart(ServiceType::Rivals);
    $state['item']->update([
        'unit_price_halalah' => 18_000,
        'total_halalah' => 18_000,
        'configuration' => [
            ...$state['item']->configuration,
            'current_division' => '1',
            'target_division' => 'elite',
        ],
    ]);

    expect(fn () => app(PlaceOrder::class)->execute(
        $state['user'],
        'ar',
        'manual-route-tampering',
    ))->toThrow(CheckoutUnavailable::class);

    expect(Order::query()->count())->toBe(0);
});
