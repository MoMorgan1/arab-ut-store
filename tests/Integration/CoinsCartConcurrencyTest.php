<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Cart;
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
