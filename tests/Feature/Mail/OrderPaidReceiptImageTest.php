<?php

use App\Enums\ServiceType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\OrderPaidNotification;

/**
 * A receipt is the one page a customer keeps, and a broken image on it reads
 * as a broken store. MirrorCatalogMedia accepts image/webp, which Outlook and
 * Yahoo cannot draw at all and image proxies flatten onto black - so the
 * receipt has to decide what it is willing to put in front of a customer.
 */
function receiptHtmlFor(string $mediaPath): string
{
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();

    ProductMedia::query()->create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => $mediaPath,
        'sort_order' => 0,
    ]);

    $order = Order::factory()->for($user)->create(['locale' => 'ar']);
    OrderItem::factory()->for($order)->for($variant, 'productVariant')->create([
        'service_type' => ServiceType::Sbc,
    ]);

    return (string) (new OrderPaidNotification($order->fresh()))->toMail($user)->render();
}

test('a PNG product image reaches the receipt', function (): void {
    $html = receiptHtmlFor('catalog/abc123.png');

    expect($html)->toContain('catalog/abc123.png');
});

test('a WebP product image is dropped rather than shipped broken', function (): void {
    $html = receiptHtmlFor('catalog/abc123.webp');

    expect($html)->not->toContain('catalog/abc123.webp')
        ->and($html)->not->toContain('.webp');
});

test('coins are drawn from the mail asset, never the storefront WebP', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->create(['locale' => 'ar']);
    OrderItem::factory()->for($order)->create(['service_type' => ServiceType::Coins]);

    $html = (string) (new OrderPaidNotification($order->fresh()))->toMail($user)->render();

    expect($html)->toContain('/images/mail/ut-coin-mail.png')
        ->and($html)->not->toContain('.webp');
});
