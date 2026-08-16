<?php

use App\Enums\FulfillmentStatus;
use App\Enums\Market;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\Platform;
use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Models\CatalogSource;
use App\Models\Category;
use App\Models\FulfillmentJob;
use App\Models\Order;
use App\Models\OrderItemSecret;
use App\Models\PersonalAccessToken;
use App\Models\PhoneVerification;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StaffAuditLog;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const DOMAIN_TABLES = [
    'users', 'social_accounts', 'phone_verifications', 'personal_access_tokens', 'staff_audit_logs',
    'categories', 'products', 'product_variants', 'product_media', 'catalog_sources',
    'catalog_sync_runs', 'catalog_sync_items', 'price_rules', 'price_runs', 'price_proposals', 'price_history',
    'carts', 'cart_items', 'coupons', 'coupon_redemptions', 'orders', 'order_items', 'order_discounts',
    'payments', 'refunds', 'wallet_accounts', 'wallet_entries', 'loyalty_tiers', 'order_status_history', 'receipts',
    'order_item_secrets', 'secret_access_logs', 'fulfillment_jobs', 'fulfillment_attempts', 'fulfillment_attachments',
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

test('catalog source identity pairs are either both null or both populated', function (string $modelClass) {
    $source = CatalogSource::factory()->create();

    expect(fn () => $modelClass::factory()->create([
        'source_id' => $source->id,
        'external_id' => null,
    ]))->toThrow(QueryException::class)
        ->and(fn () => $modelClass::factory()->create([
            'source_id' => null,
            'external_id' => 'external-without-source',
        ]))->toThrow(QueryException::class);

    expect($modelClass::factory()->create([
        'source_id' => null,
        'external_id' => null,
    ])->exists)->toBeTrue()
        ->and($modelClass::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'external-with-source',
        ])->exists)->toBeTrue();

    $sourced = $modelClass::factory()->create([
        'source_id' => $source->id,
        'external_id' => 'external-for-update',
    ]);
    $sourced->external_id = null;
    expect(fn () => $sourced->save())->toThrow(QueryException::class);
})->with([
    'categories' => Category::class,
    'products' => Product::class,
    'variants' => ProductVariant::class,
]);

test('catalog source identity foreign keys are restrictive', function (string $table) {
    $sourceForeignKey = collect(Schema::getForeignKeys($table))
        ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['source_id']);

    expect($sourceForeignKey)->not->toBeNull()
        ->and($sourceForeignKey['foreign_table'])->toBe('catalog_sources')
        ->and($sourceForeignKey['on_delete'])->toBeIn(['restrict', 'no action']);
})->with([
    'categories' => 'categories',
    'products' => 'products',
    'variants' => 'product_variants',
]);

test('catalog sources cannot be deleted while source identities reference them', function (string $modelClass) {
    $source = CatalogSource::factory()->create();
    $sourcedRecord = $modelClass::factory()->create([
        'source_id' => $source->id,
        'external_id' => 'retained-source-identity',
    ]);

    expect(fn () => $source->delete())->toThrow(QueryException::class)
        ->and($source->fresh())->not->toBeNull()
        ->and($sourcedRecord->fresh()->source_id)->toBe($source->id);
})->with([
    'a category' => Category::class,
    'a product' => Product::class,
    'a variant' => ProductVariant::class,
]);

test('catalog sources load categories through source_id', function () {
    $source = CatalogSource::factory()->create();
    $category = Category::factory()->for($source, 'source')->create([
        'external_id' => 'category-42',
    ]);

    expect($source->categories()->sole()->is($category))->toBeTrue();
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

test('variants can have multiple scoped price rules', function () {
    $variant = ProductVariant::factory()->create();

    $variant->priceRules()->createMany([
        ['name' => 'Base automation rule', 'configuration' => ['margin_basis_points' => 1000]],
        ['name' => 'Campaign override', 'configuration' => ['margin_basis_points' => 500]],
    ]);

    expect($variant->fresh()->priceRules)->toHaveCount(2);
});

test('order status history persists through the singular history table', function () {
    $order = Order::factory()->create();

    $history = $order->statusHistory()->create([
        'status' => OrderStatusHistoryStatus::Received,
    ]);

    $this->assertDatabaseHas('order_status_history', [
        'id' => $history->id,
        'order_id' => $order->id,
    ]);
    expect($history->status)->toBe(OrderStatusHistoryStatus::Received);
});

test('status history has an explicit boundary for order and item failure states', function () {
    $order = Order::factory()->hasItems(1)->create();
    $item = $order->items()->firstOrFail();

    $history = $order->statusHistory()->create([
        'order_item_id' => $item->id,
        'status' => OrderStatusHistoryStatus::Failed,
    ]);

    expect($history->status)->toBe(OrderStatusHistoryStatus::Failed);
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

test('money storage uses signed 64-bit integer columns', function () {
    foreach (DOMAIN_TABLES as $table) {
        foreach (Schema::getColumns($table) as $column) {
            if (str_ends_with($column['name'], '_halalah')) {
                expect(strtolower($column['type']))
                    ->not->toContain('unsigned', "Unsigned money column [{$table}.{$column['name']}]");
            }
        }
    }
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
        'sequence' => 1,
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

test('wallet entry parents cannot be deleted after an entry is posted', function () {
    $account = WalletAccount::factory()->create();
    WalletEntry::factory()->for($account, 'walletAccount')->create();

    $orderAccount = WalletAccount::factory()->create();
    $order = Order::factory()->create();
    WalletEntry::factory()->for($orderAccount, 'walletAccount')->for($order)->create();

    $refundAccount = WalletAccount::factory()->create();
    $refundOrder = Order::factory()->create();
    $refund = $refundOrder->refunds()->create([
        'method' => 'wallet',
        'status' => 'completed',
        'amount_halalah' => 500,
    ]);
    WalletEntry::factory()->for($refundAccount, 'walletAccount')->for($refund)->create();

    $creatorAccount = WalletAccount::factory()->create();
    $creator = User::factory()->create();
    WalletEntry::factory()->for($creatorAccount, 'walletAccount')->for($creator, 'createdBy')->create();

    expect(fn () => $account->delete())->toThrow(QueryException::class)
        ->and(fn () => $order->delete())->toThrow(QueryException::class)
        ->and(fn () => $refund->delete())->toThrow(QueryException::class)
        ->and(fn () => $creator->delete())->toThrow(QueryException::class);
});

test('wallet entry parent foreign keys are restrictive', function () {
    $parentColumns = ['wallet_account_id', 'order_id', 'refund_id', 'created_by_user_id'];
    $foreignKeys = collect(Schema::getForeignKeys('wallet_entries'))
        ->filter(fn (array $key): bool => count(array_intersect($key['columns'], $parentColumns)) > 0);

    expect($foreignKeys)->toHaveCount(4);

    foreach ($foreignKeys as $foreignKey) {
        expect($foreignKey['on_delete'])->toBeIn(['restrict', 'no action']);
    }
});

test('wallet entry money values are integers', function () {
    $entry = new WalletEntry;
    $entry->setRawAttributes([
        'amount_halalah' => '1000',
        'balance_after_halalah' => '2500',
    ]);

    expect($entry->amount_halalah)->toBeInt()
        ->and($entry->balance_after_halalah)->toBeInt();
});

test('order item secrets require trusted writes and serialize without ciphertext', function () {
    $order = Order::factory()->hasItems(1)->create();
    $secret = new OrderItemSecret([
        'order_item_id' => $order->items()->firstOrFail()->id,
        'encrypted_payload' => ['account' => 'synthetic-account'],
        'masked_summary' => ['account' => 's***t'],
    ]);

    expect($secret->getAttributes())->not->toHaveKey('encrypted_payload');

    $secret->forceFill([
        'encrypted_payload' => ['account' => 'synthetic-account'],
    ])->save();

    $ciphertext = DB::table('order_item_secrets')->where('id', $secret->id)->value('encrypted_payload');

    expect($ciphertext)->not->toContain('synthetic-account')
        ->and($secret->fresh()->encrypted_payload)->toBe(['account' => 'synthetic-account'])
        ->and($secret->fresh()->toArray())->not->toHaveKey('encrypted_payload');
});

test('verification and access token hashes stay out of serialization', function () {
    $user = User::factory()->create();
    $verification = PhoneVerification::create([
        'user_id' => $user->id,
        'phone' => '+966500000000',
        'code_hash' => 'synthetic-code-hash',
        'expires_at' => now()->addMinutes(5),
    ]);
    $token = PersonalAccessToken::create([
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id,
        'name' => 'Synthetic test token',
        'token' => hash('sha256', 'synthetic-token-value'),
    ]);

    expect($verification->toArray())->not->toHaveKey('code_hash')
        ->and($token->toArray())->not->toHaveKey('token');
});

test('personal access tokens resolve their polymorphic owners', function () {
    $user = User::factory()->create();
    $token = PersonalAccessToken::create([
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id,
        'name' => 'Synthetic test token',
        'token' => hash('sha256', 'another-synthetic-token'),
    ]);

    expect($token->tokenable->is($user))->toBeTrue();
});

test('staff audit logs resolve their polymorphic subjects', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create();
    $audit = StaffAuditLog::create([
        'actor_user_id' => $user->id,
        'action' => 'synthetic.audit',
        'auditable_type' => Order::class,
        'auditable_id' => $order->id,
    ]);

    expect($audit->auditable->is($order))->toBeTrue();
});

test('public IDs are generated as valid ULIDs and ignore mass assignment overrides', function () {
    $attemptedOverride = (string) Str::ulid();
    $source = CatalogSource::create([
        'public_id' => $attemptedOverride,
        'key' => 'mass-assignment-source',
        'name' => 'Mass assignment source',
        'authority' => ProductAuthority::Automation,
        'is_enabled' => true,
    ]);

    expect(Str::isUlid($source->public_id))->toBeTrue()
        ->and($source->public_id)->not->toBe($attemptedOverride);
});

test('public ID imports reject invalid ULIDs', function () {
    $invalid = CatalogSource::factory()->make();
    expect(fn () => $invalid->usePublicIdForImport('not-a-ulid'))
        ->toThrow(InvalidArgumentException::class);

    $invalidDirectAssignment = CatalogSource::factory()->make();
    $invalidDirectAssignment->public_id = 'not-a-ulid';
    expect(fn () => $invalidDirectAssignment->save())
        ->toThrow(InvalidArgumentException::class);
});

test('public ID imports accept an explicit valid ULID before creation', function () {
    $importedId = (string) Str::ulid();
    $imported = CatalogSource::factory()->make()->usePublicIdForImport($importedId);
    $imported->save();
    expect($imported->public_id)->toBe($importedId);
});

test('public IDs cannot change after creation', function () {
    $source = CatalogSource::factory()->create();
    $source->public_id = (string) Str::ulid();
    expect(fn () => $source->save())->toThrow(LogicException::class);
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

test('Paylink refund provider identities and idempotency are uniquely enforced', function () {
    expect(Schema::hasColumns('refunds', [
        'idempotency_key',
        'provider_refund_id',
        'provider_metadata',
    ]))->toBeTrue()
        ->and(Schema::hasIndex('refunds', ['idempotency_key'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('refunds', ['provider_refund_id'], 'unique'))->toBeTrue();
});

test('critical commerce records keep enforced foreign keys', function (string $table, string $referencedTable) {
    $foreignTables = collect(Schema::getForeignKeys($table))
        ->pluck('foreign_table');

    expect($foreignTables)->toContain($referencedTable);
})->with([
    'products belong to categories' => ['products', 'categories'],
    'variants belong to products' => ['product_variants', 'products'],
    'orders belong to users' => ['orders', 'users'],
    'items belong to orders' => ['order_items', 'orders'],
    'wallet entries belong to wallet accounts' => ['wallet_entries', 'wallet_accounts'],
    'fulfillment jobs belong to order items' => ['fulfillment_jobs', 'order_items'],
]);
