<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('two simultaneous completions for one customer produce distinct sequences and the combined balance', function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        $this->markTestSkipped('The cashback concurrency contract requires MariaDB/MySQL row locking.');
    }

    config()->set('store.features.loyalty_enabled', true);
    $this->artisan('loyalty:seed-tiers')->assertSuccessful();

    $customer = User::factory()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $firstOrder = loyaltyConcurrentCompletionOrder($customer);
    $secondOrder = loyaltyConcurrentCompletionOrder($customer);

    $barrier = loyaltyConcurrencyBarrier();
    $first = loyaltyCompletionProcess($admin->id, (string) $firstOrder->public_id, $barrier['ready'][0], $barrier);
    $second = loyaltyCompletionProcess($admin->id, (string) $secondOrder->public_id, $barrier['ready'][1], $barrier);

    try {
        $first->start();
        $second->start();
        waitForLoyaltyConcurrencyReadiness($barrier);
        releaseLoyaltyConcurrencyWorkers($barrier);
        $first->wait();
        $second->wait();
        DB::purge();
        DB::reconnect();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput());

        $entries = WalletEntry::query()
            ->where('wallet_account_id', WalletAccount::query()->where('user_id', $customer->id)->sole()->id)
            ->orderBy('sequence')
            ->get();

        expect($entries)->toHaveCount(2)
            ->and($entries->pluck('sequence')->all())->toBe([1, 2])
            ->and($entries->pluck('type')->map(fn ($type) => $type->value)->all())->toBe(['cashback', 'cashback'])
            ->and($entries->pluck('amount_halalah')->all())->toBe([400, 400])
            ->and($entries->pluck('balance_after_halalah')->all())->toBe([400, 800])
            ->and((int) WalletAccount::query()->where('user_id', $customer->id)->sole()->balance_halalah)->toBe(800)
            ->and(WalletEntry::query()->count())->toBe(2);
    } finally {
        cleanupLoyaltyConcurrencyBarrier($barrier);
    }
});

function loyaltyConcurrentCompletionOrder(User $customer): Order
{
    $order = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Received,
        'payment_halalah' => 20_000,
        'total_halalah' => 20_000,
        'paid_at' => now(),
    ]);
    $order->items()->create([
        'sku' => 'AUT-LOYALTY-CONC-'.str()->ulid(),
        'name_ar' => 'عملة',
        'name_en' => 'Coins',
        'service_type' => 'coins',
        'platform' => 'playstation',
        'status' => OrderItemStatus::Received,
        'quantity' => 1,
        'unit_price_halalah' => 20_000,
        'subtotal_halalah' => 20_000,
        'discount_halalah' => 0,
        'total_halalah' => 20_000,
    ]);
    $order->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => (string) str()->ulid(),
        'status' => PaymentStatus::Paid,
        'currency' => 'SAR',
        'amount_halalah' => 20_000,
        'captured_halalah' => 20_000,
        'refunded_halalah' => 0,
        'idempotency_key' => 'paylink:'.hash('sha256', $order->id.'|'.(string) str()->ulid()),
    ]);

    return $order;
}

/** @return array{directory: string, ready: list<string>, release: string} */
function loyaltyConcurrencyBarrier(): array
{
    $directory = storage_path('framework/testing/loyalty-concurrency-'.bin2hex(random_bytes(8)));

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create the loyalty concurrency barrier directory.');
    }

    return [
        'directory' => $directory,
        'ready' => [$directory.DIRECTORY_SEPARATOR.'first-ready', $directory.DIRECTORY_SEPARATOR.'second-ready'],
        'release' => $directory.DIRECTORY_SEPARATOR.'release',
    ];
}

/** @param array{directory: string, ready: list<string>, release: string} $barrier */
function loyaltyCompletionProcess(int $adminId, string $orderPublicId, string $readyPath, array $barrier): Process
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
        base_path('tests/Support/ConcurrentLoyaltyCompletion.php'),
        (string) $adminId,
        $orderPublicId,
        OrderStatus::Received->value,
        $readyPath,
        $barrier['release'],
    ], base_path(), loyaltyConcurrentDatabaseEnvironment(), timeout: 60);
}

/** @return array<string, string> */
function loyaltyConcurrentDatabaseEnvironment(): array
{
    $connection = (string) config('database.default');
    $database = config("database.connections.{$connection}");

    // The parent turns loyalty on with config()->set(), which cannot cross a
    // process boundary. Without this the worker reads STORE_LOYALTY_ENABLED from
    // .env -- true on a developer machine, false in the .env.example CI copies --
    // so it completes the order, accrues nothing, and the wallet never exists.
    return [
        'APP_ENV' => 'testing',
        'DB_URL' => '',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) $database['host'],
        'DB_PORT' => (string) $database['port'],
        'DB_DATABASE' => (string) $database['database'],
        'DB_USERNAME' => (string) $database['username'],
        'DB_PASSWORD' => (string) $database['password'],
        'STORE_LOYALTY_ENABLED' => 'true',
    ];
}

/** @param array{ready: list<string>} $barrier */
function waitForLoyaltyConcurrencyReadiness(array $barrier): void
{
    $deadline = microtime(true) + 20;

    while (! file_exists($barrier['ready'][0]) || ! file_exists($barrier['ready'][1])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the concurrent completion workers.');
        }

        usleep(25_000);
    }
}

/** @param array{release: string} $barrier */
function releaseLoyaltyConcurrencyWorkers(array $barrier): void
{
    file_put_contents($barrier['release'], 'release');
}

/** @param array{directory: string, release: string} $barrier */
function cleanupLoyaltyConcurrencyBarrier(array $barrier): void
{
    foreach ([$barrier['release'], ...($barrier['ready'] ?? [])] as $path) {
        if (is_string($path) && file_exists($path)) {
            unlink($path);
        }
    }

    if (is_dir($barrier['directory'])) {
        rmdir($barrier['directory']);
    }
}
