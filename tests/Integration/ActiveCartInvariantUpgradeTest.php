<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

test('the invariant migration backfills legacy carts and enforces derived ownership', function () {
    withLegacyCartDatabase(function (): void {
        seedLegacyCart(1, 'active', 'SAR', null);
        seedLegacyCart(2, 'converted', 'SAR', 'wrong-history-key');
        seedLegacyCart(3, 'active', 'USD', 'wrong-currency-key');

        activeCartInvariantMigration()->up();

        expect(DB::table('carts')->where('user_id', 1)->value('active_owner_key'))->toBe('user:1')
            ->and(DB::table('carts')->where('user_id', 2)->value('active_owner_key'))->toBeNull()
            ->and(DB::table('carts')->where('user_id', 3)->value('active_owner_key'))->toBeNull();

        seedLegacyCart(4, 'active', 'SAR', 'caller-controlled-key');
        expect(DB::table('carts')->where('user_id', 4)->value('active_owner_key'))->toBe('user:4');
    });
});

test('the invariant migration rejects duplicate legacy active SAR carts', function () {
    withLegacyCartDatabase(function (): void {
        seedLegacyCart(1, 'active', 'SAR', null);
        seedLegacyCart(1, 'active', 'SAR', null);

        expect(fn () => activeCartInvariantMigration()->up())
            ->toThrow(RuntimeException::class, 'duplicate active authenticated SAR carts');
    });
});

function withLegacyCartDatabase(Closure $scenario): void
{
    $originalConnection = config('database.default');
    $databasePath = tempnam(sys_get_temp_dir(), 'active-cart-upgrade-');
    config()->set('database.connections.active_cart_upgrade', [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::setDefaultConnection('active_cart_upgrade');

    try {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('session_key')->nullable()->unique();
            $table->string('active_owner_key')->nullable()->unique();
            $table->string('status')->default('active')->index();
            $table->string('currency', 3)->default('SAR');
            $table->timestamps();
        });

        $scenario();
    } finally {
        DB::disconnect('active_cart_upgrade');
        DB::setDefaultConnection($originalConnection);
        @unlink($databasePath);
    }
}

function seedLegacyCart(int $userId, string $status, string $currency, ?string $ownerKey): void
{
    DB::table('carts')->insert([
        'public_id' => (string) str()->ulid(),
        'user_id' => $userId,
        'session_key' => null,
        'active_owner_key' => $ownerKey,
        'status' => $status,
        'currency' => $currency,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function activeCartInvariantMigration(): object
{
    return require database_path('migrations/2026_08_10_000002_enforce_active_cart_invariant.php');
}
