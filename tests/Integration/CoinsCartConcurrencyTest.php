<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Cart;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('concurrent first additions create one active cart and two credential-bound lines', function () {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    $product = Product::factory()->create([
        'service_type' => ServiceType::Coins,
        'is_visible' => true,
        'archived_at' => null,
    ]);

    foreach (Platform::cases() as $platform) {
        ProductVariant::factory()->for($product)->create([
            'service_type' => ServiceType::Coins,
            'platform' => $platform,
            'is_active' => true,
        ]);
    }

    foreach (['console_normal', 'console_fast', 'pc'] as $group) {
        PriceRule::create([
            'name' => "Concurrent Coins {$group}",
            'service_type' => ServiceType::Coins,
            'configuration' => concurrentRuleConfiguration($group),
            'is_active' => true,
        ]);
    }

    $user = User::factory()->create();
    $first = concurrentCartProcess($user->id, 'concurrent-key-1');
    $second = concurrentCartProcess($user->id, 'concurrent-key-2');
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and(Cart::where('user_id', $user->id)->count())->toBe(1)
        ->and(Cart::sole()->items()->count())->toBe(2)
        ->and(Cart::sole()->items()->whereHas('secret')->count())->toBe(2);
});

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
    ], base_path(), timeout: 30);
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
