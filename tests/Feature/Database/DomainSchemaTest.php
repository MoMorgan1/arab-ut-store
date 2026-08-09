<?php

use App\Enums\FulfillmentStatus;
use App\Enums\Market;
use App\Enums\Platform;
use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Models\CatalogSource;
use App\Models\FulfillmentJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const DOMAIN_TABLES = [
    'users', 'social_accounts', 'phone_verifications', 'personal_access_tokens', 'staff_audit_logs',
    'categories', 'products', 'product_variants', 'product_media', 'catalog_sources',
    'catalog_sync_runs', 'catalog_sync_items', 'price_rules', 'price_runs', 'price_proposals', 'price_history',
    'carts', 'cart_items', 'coupons', 'coupon_redemptions', 'orders', 'order_items', 'order_discounts',
    'payments', 'refunds', 'wallet_accounts', 'wallet_entries', 'loyalty_tiers', 'order_status_history', 'receipts',
    'order_item_secrets', 'secret_access_logs', 'fulfillment_jobs', 'fulfillment_attempts',
    'integration_events', 'notification_deliveries', 'idempotency_keys',
    'reviews', 'faq_entries', 'exchange_rates',
];

test('the complete domain schema uses numeric primary keys and public ULIDs', function () {
    foreach (DOMAIN_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table [{$table}]");
        expect(Schema::hasColumns($table, ['id', 'public_id']))->toBeTrue("Missing identifiers on [{$table}]");
    }
});

test('customer-facing catalog and content fields are explicitly bilingual', function (string $table, array $columns) {
    expect(Schema::hasColumns($table, $columns))->toBeTrue();
})->with([
    'categories' => ['categories', ['name_ar', 'name_en', 'description_ar', 'description_en']],
    'products' => ['products', ['name_ar', 'name_en', 'description_ar', 'description_en']],
    'coupons' => ['coupons', ['description_ar', 'description_en']],
    'loyalty tiers' => ['loyalty_tiers', ['name_ar', 'name_en']],
    'reviews' => ['reviews', ['body_ar', 'body_en']],
    'FAQ entries' => ['faq_entries', ['question_ar', 'question_en', 'answer_ar', 'answer_en']],
]);

test('catalog source identities are unique within a source', function () {
    $source = CatalogSource::factory()->create();
    Product::factory()->for($source, 'source')->create(['external_id' => 'external-42']);

    expect(fn () => Product::factory()->for($source, 'source')->create(['external_id' => 'external-42']))
        ->toThrow(QueryException::class);
});

test('variant SKUs are globally unique', function () {
    ProductVariant::factory()->create(['sku' => 'SBC_42']);

    expect(fn () => ProductVariant::factory()->create(['sku' => 'SBC_42']))
        ->toThrow(QueryException::class);
});

test('fulfillment jobs are unique per order item', function () {
    $order = Order::factory()->hasItems(1)->create();
    $item = $order->items()->firstOrFail();

    FulfillmentJob::factory()->for($item, 'orderItem')->create();

    expect(fn () => FulfillmentJob::factory()->for($item, 'orderItem')->create())
        ->toThrow(QueryException::class);
});

test('fulfillment models expose enum casts through their relationships', function () {
    $order = Order::factory()->hasItems(1)->create();
    $item = $order->items()->firstOrFail();
    $job = FulfillmentJob::factory()->for($item, 'orderItem')->create([
        'status' => FulfillmentStatus::Ready,
    ]);

    expect($job->status)->toBe(FulfillmentStatus::Ready)
        ->and($job->orderItem->is($item))->toBeTrue()
        ->and($order->fresh()->items)->toHaveCount(1);
});

test('catalog models cast the approved domain vocabulary', function () {
    $variant = ProductVariant::factory()->create([
        'service_type' => ServiceType::Coins,
        'platform' => Platform::Xbox,
        'authority' => ProductAuthority::Automation,
    ]);

    expect($variant->service_type)->toBe(ServiceType::Coins)
        ->and($variant->platform)->toBe(Platform::Xbox)
        ->and($variant->authority)->toBe(ProductAuthority::Automation)
        ->and($variant->product)->toBeInstanceOf(Product::class);
});

test('variant market is derived from the customer platform', function () {
    $variant = ProductVariant::factory()->create([
        'platform' => Platform::Xbox,
        'market' => Market::Pc,
    ]);

    expect($variant->market)->toBe(Market::Console);
});

test('variant price history persists through the singular history table', function () {
    $variant = ProductVariant::factory()->create();

    $history = $variant->priceHistory()->create([
        'price_halalah' => 10_000,
        'sale_price_halalah' => null,
        'version' => 1,
        'effective_at' => now(),
    ]);

    $this->assertDatabaseHas('price_history', [
        'id' => $history->id,
        'product_variant_id' => $variant->id,
    ]);
});

test('order status history persists through the singular history table', function () {
    $order = Order::factory()->create();

    $history = $order->statusHistory()->create([
        'status' => 'received',
    ]);

    $this->assertDatabaseHas('order_status_history', [
        'id' => $history->id,
        'order_id' => $order->id,
    ]);
});

test('each customer has at most one wallet account', function () {
    $user = User::factory()->create();

    DB::table('wallet_accounts')->insert([
        'public_id' => (string) str()->ulid(),
        'user_id' => $user->id,
        'balance_halalah' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('wallet_accounts')->insert([
        'public_id' => (string) str()->ulid(),
        'user_id' => $user->id,
        'balance_halalah' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('idempotency keys are globally unique across scopes', function () {
    DB::table('idempotency_keys')->insert([
        'public_id' => (string) str()->ulid(),
        'key' => 'order-paid-42',
        'scope' => 'orders',
        'created_at' => now(),
    ]);

    expect(fn () => DB::table('idempotency_keys')->insert([
        'public_id' => (string) str()->ulid(),
        'key' => 'order-paid-42',
        'scope' => 'fulfillment',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('money columns reject negative values', function () {
    $user = User::factory()->create();

    expect(fn () => DB::table('wallet_accounts')->insert([
        'public_id' => (string) str()->ulid(),
        'user_id' => $user->id,
        'balance_halalah' => -1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('wallet entries are append-only at the database boundary', function () {
    $user = User::factory()->create();
    $walletId = DB::table('wallet_accounts')->insertGetId([
        'public_id' => (string) str()->ulid(),
        'user_id' => $user->id,
        'balance_halalah' => 500,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $entryId = DB::table('wallet_entries')->insertGetId([
        'public_id' => (string) str()->ulid(),
        'wallet_account_id' => $walletId,
        'type' => 'credit',
        'amount_halalah' => 500,
        'balance_after_halalah' => 500,
        'created_at' => now(),
    ]);

    expect(Schema::hasColumn('wallet_entries', 'updated_at'))->toBeFalse()
        ->and(fn () => DB::table('wallet_entries')->where('id', $entryId)->update(['amount_halalah' => 1]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('wallet_entries')->where('id', $entryId)->delete())
        ->toThrow(QueryException::class);
});

test('operational lookup columns are indexed', function (string $table, array $columns) {
    expect(Schema::hasIndex($table, $columns))->toBeTrue("Missing index on [{$table}] for [".implode(', ', $columns).']');
})->with([
    'orders by customer' => ['orders', ['user_id']],
    'orders by status' => ['orders', ['status']],
    'order items by order and status' => ['order_items', ['order_id', 'status']],
    'fulfillment polling' => ['fulfillment_jobs', ['status', 'next_poll_at']],
    'integration event dispatch' => ['integration_events', ['status', 'available_at']],
    'notification outbox' => ['notification_deliveries', ['status', 'available_at']],
]);

test('critical commerce records keep enforced foreign keys', function (string $table, string $referencedTable) {
    $foreignTables = collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
        ->pluck('table');

    expect($foreignTables)->toContain($referencedTable);
})->with([
    'products belong to categories' => ['products', 'categories'],
    'variants belong to products' => ['product_variants', 'products'],
    'orders belong to users' => ['orders', 'users'],
    'items belong to orders' => ['order_items', 'orders'],
    'wallet entries belong to wallet accounts' => ['wallet_entries', 'wallet_accounts'],
    'fulfillment jobs belong to order items' => ['fulfillment_jobs', 'order_items'],
]);
