<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Cart;
use App\Models\CartItemSecret;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('a usage-limited coupon redeems exactly once under concurrent checkout', function (): void {
    if (! supportsCouponConcurrencyLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);

    $coupon = Coupon::query()->create(couponConcurrencyAttributes([
        'code' => 'ONCEONLY',
        'usage_limit' => 1,
        'value' => 500,
        'discount_type' => 'fixed',
    ]));

    $first = couponConcurrencyShopper('concurrent-coupon-a');
    $second = couponConcurrencyShopper('concurrent-coupon-b');

    $firstProcess = concurrentCouponRedemptionProcess($first->id, "coupon-race-{$first->id}", $coupon->code);
    $secondProcess = concurrentCouponRedemptionProcess($second->id, "coupon-race-{$second->id}", $coupon->code);
    $firstProcess->start();
    $secondProcess->start();
    $firstProcess->wait();
    $secondProcess->wait();
    refreshCouponConcurrencyConnection();

    expect(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(1)
        ->and(OrderDiscount::query()->where('coupon_id', $coupon->id)->count())->toBe(1)
        ->and(Order::query()->whereIn('user_id', [$first->id, $second->id])->count())->toBe(1)
        ->and(
            $firstProcess->isSuccessful() xor $secondProcess->isSuccessful()
        )->toBeTrue("Both processes agreed: {$firstProcess->getOutput()} / {$secondProcess->getErrorOutput()}");
});

function supportsCouponConcurrencyLocking(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true);
}

function refreshCouponConcurrencyConnection(): void
{
    DB::purge();
    DB::reconnect();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function couponConcurrencyAttributes(array $overrides = []): array
{
    return array_merge([
        'public_id' => (string) Str::ulid(),
        'code' => 'RACECODE',
        'discount_type' => 'percent',
        'value' => 10,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ], $overrides);
}

function couponConcurrencyShopper(string $phoneSuffix): User
{
    $user = User::factory()->create([
        'phone' => '+9665'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'phone_verified_at' => now(),
    ]);

    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'name_ar' => "تحدي {$phoneSuffix}",
        'name_en' => "Challenge {$phoneSuffix}",
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'price_halalah' => 2500,
        'sale_price_halalah' => null,
        'price_version' => 4,
        'is_active' => true,
    ]);

    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    $item = $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => 2500,
        'total_halalah' => 2500,
        'configuration' => [
            'service_type' => 'sbc',
            'platform' => 'playstation',
            'market' => 'console',
            'completion_count' => 1,
            'quoted_at' => now()->utc()->toIso8601String(),
            'price_version' => 4,
        ],
    ]);
    $secret = new CartItemSecret([
        'cart_item_id' => $item->id,
        'masked_summary' => ['has_password' => true, 'backup_code_count' => 3],
        'retained_until' => null,
        'deleted_at' => null,
    ]);
    $secret->encrypted_payload = [
        'ea_email' => "{$phoneSuffix}@example.test",
        'ea_password' => 'Concurrent Coupon Password',
        'backup_codes' => ['73000001', '73000002', '73000003'],
    ];
    $secret->save();

    return $user;
}

function concurrentCouponRedemptionProcess(int $userId, string $key, string $couponCode): Process
{
    return new Process([
        PHP_BINARY,
        '-d',
        'extension_dir='.ini_get('extension_dir'),
        '-d',
        'extension=openssl',
        '-d',
        'extension=mbstring',
        '-d',
        'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentCouponRedemption.php'),
        (string) $userId,
        $key,
        $couponCode,
    ], base_path(), couponConcurrencyDatabaseEnvironment(), timeout: 30);
}

/** @return array<string, string> */
function couponConcurrencyDatabaseEnvironment(): array
{
    $connection = (string) config('database.default');
    $database = config("database.connections.{$connection}");

    return [
        'APP_ENV' => 'testing',
        'DB_URL' => '',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) $database['host'],
        'DB_PORT' => (string) $database['port'],
        'DB_DATABASE' => (string) $database['database'],
        'DB_USERNAME' => (string) $database['username'],
        'DB_PASSWORD' => (string) $database['password'],
    ];
}
