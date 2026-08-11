<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Cart;
use App\Models\CartItemSecret;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('concurrent first additions create one active cart and two credential-bound lines', function () {
    if (! supportsConcurrentCartLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    createConcurrentCatalog();

    $user = User::factory()->create();
    $first = concurrentCartProcess($user->id, "concurrent-key-1-{$user->id}");
    $second = concurrentCartProcess($user->id, "concurrent-key-2-{$user->id}");
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();
    refreshConcurrentConnection();
    $userCart = Cart::where('user_id', $user->id)->sole();

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and(Cart::where('user_id', $user->id)->count())->toBe(1)
        ->and($userCart->items()->count())->toBe(2)
        ->and($userCart->items()->whereHas('secret')->count())->toBe(2);
});

test('concurrent same-key additions replay one identical safe response', function () {
    if (! supportsConcurrentCartLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    createConcurrentCatalog();
    $user = User::factory()->create();
    $idempotencyKey = "same-concurrent-key-{$user->id}";
    $first = concurrentCartProcess($user->id, $idempotencyKey);
    $second = concurrentCartProcess($user->id, $idempotencyKey);
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();
    refreshConcurrentConnection();
    $userCart = Cart::where('user_id', $user->id)->sole();

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and($first->getOutput())->not->toBe('')
        ->and($second->getOutput())->toBe($first->getOutput())
        ->and($first->getOutput())->not->toContain('Concurrency Password Sentinel')
        ->and(Cart::where('user_id', $user->id)->count())->toBe(1)
        ->and($userCart->items()->count())->toBe(1)
        ->and($userCart->items()->whereHas('secret')->count())->toBe(1)
        ->and(DB::table('idempotency_keys')->where('key', $idempotencyKey)->count())->toBe(1);
});

test('concurrent same-guest first cart acquisitions resolve to one active cart', function () {
    if (! supportsConcurrentCartLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    $guestHmac = hash_hmac('sha256', 'concurrent-guest-owner', 'synthetic-concurrency-key');
    $first = concurrentGuestCartProcess($guestHmac);
    $second = concurrentGuestCartProcess($guestHmac);
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();
    refreshConcurrentConnection();

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and(trim($first->getOutput()))->not->toBe('')
        ->and(trim($second->getOutput()))->toBe(trim($first->getOutput()))
        ->and(Cart::query()->whereNull('user_id')->where('session_key', $guestHmac)->count())->toBe(1)
        ->and(Cart::query()->where('active_owner_key', "guest:{$guestHmac}")->count())->toBe(1);
});

test('concurrent guest claims merge every item and secret exactly once into one user cart', function () {
    if (! supportsConcurrentCartLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    createConcurrentCatalog();
    $user = User::factory()->create();
    $guestHmac = hash_hmac('sha256', "concurrent-guest-claim-{$user->id}", 'synthetic-concurrency-key');
    $guestCart = Cart::query()->create([
        'user_id' => null,
        'session_key' => $guestHmac,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    $userCart = Cart::query()->create([
        'user_id' => $user->id,
        'session_key' => null,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    $variant = ProductVariant::query()->firstOrFail();

    foreach ([$guestCart, $userCart] as $index => $cart) {
        $item = $cart->items()->create([
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price_halalah' => 5_000,
            'total_halalah' => 5_000,
            'configuration' => ['service_type' => 'coins'],
        ]);
        $secret = new CartItemSecret([
            'cart_item_id' => $item->id,
            'masked_summary' => ['has_password' => true, 'backup_code_count' => 5],
            'retained_until' => now()->addHour(),
        ]);
        $secret->encrypted_payload = [
            'ea_email' => "concurrent-claim-{$index}@example.test",
            'ea_password' => "Concurrent claim secret {$index}",
            'backup_codes' => ['20000001', '20000002', '20000003', '20000004', '20000005'],
        ];
        $secret->save();
    }

    $itemIds = $guestCart->items()->pluck('id')->merge($userCart->items()->pluck('id'))->sort()->values()->all();
    $secretIds = CartItemSecret::query()->pluck('id')->sort()->values()->all();
    $first = concurrentGuestClaimProcess($guestHmac, $user->id);
    $second = concurrentGuestClaimProcess($guestHmac, $user->id);
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();
    refreshConcurrentConnection();

    $claimedCart = Cart::query()->where('active_owner_key', "user:{$user->id}")->sole();

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and(Cart::query()->where('active_owner_key', "guest:{$guestHmac}")->count())->toBe(0)
        ->and(Cart::query()->where('active_owner_key', "user:{$user->id}")->count())->toBe(1)
        ->and($claimedCart->items()->pluck('id')->sort()->values()->all())->toBe($itemIds)
        ->and(CartItemSecret::query()->pluck('id')->sort()->values()->all())->toBe($secretIds)
        ->and($claimedCart->items()->whereHas('secret')->count())->toBe(2);
});

test('concurrent claims convert a guest-only cart exactly once', function () {
    if (! supportsConcurrentCartLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    $user = User::factory()->create();
    $guestHmac = hash_hmac('sha256', "concurrent-guest-only-claim-{$user->id}", 'synthetic-concurrency-key');
    $guestCart = Cart::query()->create([
        'session_key' => $guestHmac,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    $first = concurrentGuestClaimProcess([$guestHmac], $user->id);
    $second = concurrentGuestClaimProcess([$guestHmac], $user->id);
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();
    refreshConcurrentConnection();

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and($guestCart->fresh()->active_owner_key)->toBe("user:{$user->id}")
        ->and(Cart::query()->where('active_owner_key', "user:{$user->id}")->count())->toBe(1)
        ->and(DB::table('guest_cart_claims')->where('guest_session_hmac', $guestHmac)->value('user_id'))
        ->toBe($user->id);
});

test('concurrent claims merge two guest identities into one user cart', function () {
    if (! supportsConcurrentCartLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    createConcurrentCatalog();
    $user = User::factory()->create();
    $guestHmacs = [
        hash_hmac('sha256', "concurrent-current-key-cart-{$user->id}", 'synthetic-concurrency-key'),
        hash_hmac('sha256', "concurrent-previous-key-cart-{$user->id}", 'synthetic-concurrency-key'),
    ];
    $variant = ProductVariant::query()->firstOrFail();
    $itemIds = [];

    foreach ($guestHmacs as $index => $guestHmac) {
        $cart = Cart::query()->create([
            'session_key' => $guestHmac,
            'status' => 'active',
            'currency' => 'SAR',
        ]);
        $itemIds[] = $cart->items()->create([
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price_halalah' => 5_000,
            'total_halalah' => 5_000,
            'configuration' => ['service_type' => 'coins', 'candidate' => $index],
        ])->id;
    }

    $first = concurrentGuestClaimProcess($guestHmacs, $user->id);
    $second = concurrentGuestClaimProcess($guestHmacs, $user->id);
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();
    refreshConcurrentConnection();
    $claimedCart = Cart::query()->where('active_owner_key', "user:{$user->id}")->sole();

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and($claimedCart->items()->pluck('id')->sort()->values()->all())
        ->toBe(collect($itemIds)->sort()->values()->all())
        ->and(Cart::query()->whereIn('active_owner_key', array_map(
            fn (string $hmac): string => "guest:{$hmac}",
            $guestHmacs,
        ))->count())->toBe(0);
});

test('an add that starts before claim is committed into the claimed user cart', function () {
    if (! supportsConcurrentCartLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    createConcurrentCatalog();
    $user = User::factory()->create();
    $guestHmac = hash_hmac('sha256', "add-before-claim-{$user->id}", 'synthetic-concurrency-key');
    $barriers = concurrentBarrierPaths('add-before-claim');
    $add = concurrentGuestAddProcess($guestHmac, "add-before-claim-{$user->id}", $barriers);
    $add->start();
    waitForConcurrentBarrier($barriers['ready']);
    $claim = concurrentGuestClaimProcess([$guestHmac], $user->id, null, $barriers['claim_started']);
    $claim->start();
    waitForConcurrentBarrier($barriers['claim_started']);
    touch($barriers['release']);
    $add->wait();
    $claim->wait();
    cleanupConcurrentBarriers($barriers);
    refreshConcurrentConnection();

    assertClaimAddResult($add, $claim, $guestHmac, $user->id);
});

test('an add that starts after claim routing begins is committed into the user cart', function () {
    if (! supportsConcurrentCartLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    createConcurrentCatalog();
    $user = User::factory()->create();
    $guestHmac = hash_hmac('sha256', "claim-before-add-{$user->id}", 'synthetic-concurrency-key');
    $barriers = concurrentBarrierPaths('claim-before-add');
    $claim = concurrentGuestClaimProcess([$guestHmac], $user->id, $barriers);
    $claim->start();
    waitForConcurrentBarrier($barriers['ready']);
    $add = concurrentGuestAddProcess(
        $guestHmac,
        "claim-before-add-{$user->id}",
        null,
        $barriers['add_started'],
    );
    $add->start();
    waitForConcurrentBarrier($barriers['add_started']);
    touch($barriers['release']);
    $claim->wait();
    $add->wait();
    cleanupConcurrentBarriers($barriers);
    refreshConcurrentConnection();

    assertClaimAddResult($add, $claim, $guestHmac, $user->id);
});

function supportsConcurrentCartLocking(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true);
}

function createConcurrentCatalog(): void
{
    $product = Product::query()
        ->where('service_type', ServiceType::Coins)
        ->where('is_visible', true)
        ->whereNull('archived_at')
        ->first() ?? Product::factory()->create([
            'service_type' => ServiceType::Coins,
            'is_visible' => true,
            'archived_at' => null,
        ]);

    foreach (Platform::cases() as $platform) {
        ProductVariant::query()
            ->whereBelongsTo($product)
            ->where('platform', $platform)
            ->first() ?? ProductVariant::factory()->for($product)->create([
                'service_type' => ServiceType::Coins,
                'platform' => $platform,
                'is_active' => true,
            ]);
    }

    foreach (['console_normal', 'console_fast', 'pc'] as $group) {
        PriceRule::firstOrCreate(
            ['name' => "Concurrent Coins {$group}"],
            [
                'service_type' => ServiceType::Coins,
                'configuration' => concurrentRuleConfiguration($group),
                'is_active' => true,
            ],
        );
    }
}

function refreshConcurrentConnection(): void
{
    DB::purge();
    DB::reconnect();
}

function concurrentCartProcess(int $userId, string $key): Process
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
        base_path('tests/Support/ConcurrentCoinsCartAdd.php'),
        (string) $userId,
        $key,
    ], base_path(), concurrentDatabaseEnvironment(), timeout: 30);
}

function concurrentGuestCartProcess(string $guestHmac): Process
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
        base_path('tests/Support/ConcurrentGuestCartCreate.php'),
        $guestHmac,
    ], base_path(), concurrentDatabaseEnvironment(), timeout: 30);
}

/**
 * @param  list<string>|string  $guestHmacs
 * @param  array{ready: string, release: string}|null  $barriers
 */
function concurrentGuestClaimProcess(
    array|string $guestHmacs,
    int $userId,
    ?array $barriers = null,
    ?string $startedPath = null,
): Process {
    $arguments = [
        PHP_BINARY,
        '-d',
        'extension_dir='.ini_get('extension_dir'),
        '-d',
        'extension=openssl',
        '-d',
        'extension=mbstring',
        '-d',
        'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentGuestCartClaim.php'),
        is_array($guestHmacs) ? implode(',', $guestHmacs) : $guestHmacs,
        (string) $userId,
    ];

    if ($barriers !== null || $startedPath !== null) {
        $arguments[] = $barriers['ready'] ?? '';
        $arguments[] = $barriers['release'] ?? '';
        $arguments[] = $startedPath ?? '';
    }

    return new Process($arguments, base_path(), concurrentDatabaseEnvironment(), timeout: 30);
}

/** @param array{ready: string, release: string}|null $barriers */
function concurrentGuestAddProcess(
    string $guestHmac,
    string $key,
    ?array $barriers = null,
    ?string $startedPath = null,
): Process {
    $arguments = [
        PHP_BINARY,
        '-d',
        'extension_dir='.ini_get('extension_dir'),
        '-d',
        'extension=openssl',
        '-d',
        'extension=mbstring',
        '-d',
        'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentGuestCoinsCartAdd.php'),
        $guestHmac,
        $key,
    ];

    if ($barriers !== null || $startedPath !== null) {
        $arguments[] = $barriers['ready'] ?? '';
        $arguments[] = $barriers['release'] ?? '';
        $arguments[] = $startedPath ?? '';
    }

    return new Process($arguments, base_path(), concurrentDatabaseEnvironment(), timeout: 30);
}

/** @return array{ready: string, release: string, add_started: string, claim_started: string} */
function concurrentBarrierPaths(string $scenario): array
{
    $directory = storage_path('framework/testing/cart-concurrency-'.bin2hex(random_bytes(8)));

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create concurrency barrier directory for {$scenario}.");
    }

    return [
        'ready' => $directory.DIRECTORY_SEPARATOR.'ready',
        'release' => $directory.DIRECTORY_SEPARATOR.'release',
        'add_started' => $directory.DIRECTORY_SEPARATOR.'add-started',
        'claim_started' => $directory.DIRECTORY_SEPARATOR.'claim-started',
    ];
}

function waitForConcurrentBarrier(string $path): void
{
    $deadline = microtime(true) + 20;

    while (! file_exists($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException("Timed out waiting for concurrency barrier: {$path}");
        }

        usleep(25_000);
    }
}

/** @param array<string, string> $barriers */
function cleanupConcurrentBarriers(array $barriers): void
{
    foreach ($barriers as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    $directory = dirname($barriers['ready']);

    if (is_dir($directory)) {
        rmdir($directory);
    }
}

function assertClaimAddResult(Process $add, Process $claim, string $guestHmac, int $userId): void
{
    expect($add->isSuccessful())->toBeTrue($add->getErrorOutput())
        ->and($claim->isSuccessful())->toBeTrue($claim->getErrorOutput());

    $claimedCart = Cart::query()->where('active_owner_key', "user:{$userId}")->sole();

    expect($claimedCart->items()->count())->toBe(1)
        ->and($claimedCart->items()->whereHas('secret')->count())->toBe(1)
        ->and(Cart::query()->where('active_owner_key', "guest:{$guestHmac}")->count())->toBe(0)
        ->and(DB::table('guest_cart_claims')->where('guest_session_hmac', $guestHmac)->value('user_id'))
        ->toBe($userId);
}

/** @return array<string, string> */
function concurrentDatabaseEnvironment(): array
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

/** @return array<string, mixed> */
function concurrentRuleConfiguration(string $group): array
{
    $configuration = [
        'version' => 1,
        'group' => $group,
        'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 0,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
    ];
    $configuration[$group === 'console_normal'
        ? 'flat_rate_halalah_per_million'
        : 'tier_rates_halalah_per_million'] = $group === 'console_normal'
            ? 5_000
            : array_fill(0, 6, 5_000);

    return $configuration;
}
