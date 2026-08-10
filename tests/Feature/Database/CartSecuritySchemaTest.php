<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\IdempotencyKey;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('cart secret storage and active cart ownership have database constraints', function () {
    expect(Schema::hasColumns('cart_item_secrets', [
        'public_id',
        'cart_item_id',
        'encrypted_payload',
        'masked_summary',
        'retained_until',
        'deleted_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('carts', 'active_owner_key'))->toBeTrue();

    $user = User::factory()->create();
    Cart::create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);

    expect(fn () => Cart::create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]))->toThrow(QueryException::class);
});

test('the database derives active ownership from user status and SAR currency', function () {
    $user = User::factory()->create();
    $now = now();

    DB::table('carts')->insert([
        'public_id' => (string) str()->ulid(),
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(DB::table('carts')->value('active_owner_key'))->toBe("user:{$user->id}");

    expect(fn () => DB::table('carts')->insert([
        'public_id' => (string) str()->ulid(),
        'user_id' => $user->id,
        'active_owner_key' => 'mismatched-owner',
        'status' => 'active',
        'currency' => 'SAR',
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);

    DB::table('carts')->where('user_id', $user->id)->update(['status' => 'converted']);
    expect(DB::table('carts')->value('active_owner_key'))->toBeNull();

    $insertWithMismatchedKey = fn () => DB::table('carts')->insert([
        'public_id' => (string) str()->ulid(),
        'user_id' => $user->id,
        'active_owner_key' => 'ignored-input',
        'status' => 'active',
        'currency' => 'SAR',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    if (in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        expect($insertWithMismatchedKey)->toThrow(QueryException::class);
        DB::table('carts')->insert([
            'public_id' => (string) str()->ulid(),
            'user_id' => $user->id,
            'status' => 'active',
            'currency' => 'SAR',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    } else {
        $insertWithMismatchedKey();
    }

    expect(DB::table('carts')->where('status', 'active')->value('active_owner_key'))
        ->toBe("user:{$user->id}");
});

test('cart secrets are encrypted hidden guarded and cascade with their item', function () {
    $variant = ProductVariant::factory()->create();
    $cart = Cart::create([
        'user_id' => User::factory()->create()->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    $item = CartItem::create([
        'cart_id' => $cart->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => 500,
        'total_halalah' => 500,
        'configuration' => ['service' => 'coins'],
    ]);
    $payload = [
        'ea_email' => 'schema-sentinel@example.test',
        'ea_password' => 'Schema-Password-Sentinel',
        'backup_codes' => ['10000001', '10000002', '10000003', '10000004', '10000005'],
    ];

    $guarded = CartItemSecret::create([
        'cart_item_id' => $item->id,
        'encrypted_payload' => $payload,
        'masked_summary' => ['email' => 's***@example.test'],
        'retained_until' => now()->addDay(),
    ]);
    expect($guarded->getRawOriginal('encrypted_payload'))->toBeNull();

    $guarded->encrypted_payload = $payload;
    $guarded->save();
    $rawPayload = DB::table('cart_item_secrets')->where('id', $guarded->id)->value('encrypted_payload');

    expect($rawPayload)->toBeString()
        ->not->toContain('Schema-Password-Sentinel')
        ->not->toContain('10000001')
        ->and($guarded->fresh()->encrypted_payload)->toBe($payload)
        ->and($guarded->fresh()->toArray())->not->toHaveKey('encrypted_payload')
        ->and($item->fresh()->secret->is($guarded))->toBeTrue()
        ->and($guarded->fresh()->cartItem->is($item))->toBeTrue();

    expect(fn () => CartItemSecret::create([
        'cart_item_id' => $item->id,
        'retained_until' => now()->addDay(),
    ]))->toThrow(QueryException::class);

    $item->delete();
    expect(CartItemSecret::find($guarded->id))->toBeNull();
});

test('purged cart secrets retain only safe nullable state', function () {
    $variant = ProductVariant::factory()->create();
    $cart = Cart::create([
        'user_id' => User::factory()->create()->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    $item = CartItem::create([
        'cart_id' => $cart->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => 500,
        'total_halalah' => 500,
    ]);
    $secret = new CartItemSecret([
        'cart_item_id' => $item->id,
        'masked_summary' => ['email' => 'p***@example.test', 'backup_code_count' => 5],
        'retained_until' => now()->subMinute(),
    ]);
    $secret->encrypted_payload = ['ea_password' => 'Purge-Sentinel'];
    $secret->save();

    $secret->encrypted_payload = null;
    $secret->masked_summary = null;
    $secret->deleted_at = now();
    $secret->save();

    expect($secret->fresh()->encrypted_payload)->toBeNull()
        ->and($secret->fresh()->masked_summary)->toBeNull()
        ->and($secret->fresh()->deleted_at)->not->toBeNull();
});

test('idempotency fingerprints and response bodies are hidden', function () {
    $key = IdempotencyKey::create([
        'key' => 'schema-idempotency-key',
        'scope' => 'coins-cart:user:1',
        'request_hash' => str_repeat('a', 64),
        'response_status' => 201,
        'response_body' => json_encode(['data' => ['cartItemId' => 'safe']], JSON_THROW_ON_ERROR),
    ]);

    expect($key->getRawOriginal('request_hash'))->toBeNull()
        ->and($key->getRawOriginal('response_body'))->toBeNull();

    $key->forceFill([
        'request_hash' => str_repeat('a', 64),
        'response_body' => json_encode(['data' => ['cartItemId' => 'safe']], JSON_THROW_ON_ERROR),
    ])->save();

    expect($key->toArray())->not->toHaveKeys(['request_hash', 'response_body']);
});
