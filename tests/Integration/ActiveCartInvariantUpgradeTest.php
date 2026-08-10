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

test('the guest invariant upgrade backfills valid guest owners and restores the authenticated invariant on rollback', function () {
    withLegacyCartDatabase(function (): void {
        $guestHmac = hash('sha256', 'upgrade-guest-owner');
        seedLegacyCart(null, 'active', 'SAR', null, $guestHmac);
        seedLegacyCart(8, 'active', 'SAR', null);

        activeCartInvariantMigration()->up();
        guestCartInvariantMigration()->up();

        expect(DB::table('carts')->where('session_key', $guestHmac)->value('active_owner_key'))
            ->toBe("guest:{$guestHmac}")
            ->and(DB::table('carts')->where('user_id', 8)->value('active_owner_key'))
            ->toBe('user:8');

        guestCartInvariantMigration()->down();

        expect(DB::table('carts')->where('session_key', $guestHmac)->value('active_owner_key'))
            ->toBeNull()
            ->and(DB::table('carts')->where('user_id', 8)->value('active_owner_key'))
            ->toBe('user:8');

        guestCartInvariantMigration()->up();

        expect(DB::table('carts')->where('session_key', $guestHmac)->value('active_owner_key'))
            ->toBe("guest:{$guestHmac}");
    });
});

test('the guest invariant migration rejects duplicate legacy active guest owners before changing schema', function () {
    withLegacyCartDatabase(function (): void {
        $guestHmac = hash('sha256', 'duplicate-upgrade-guest-owner');
        seedLegacyCart(null, 'active', 'SAR', null, $guestHmac);

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropUnique(['session_key']);
        });
        seedLegacyCart(null, 'active', 'SAR', null, $guestHmac);

        expect(fn () => guestCartInvariantMigration()->up())
            ->toThrow(RuntimeException::class, 'duplicate active cart owners');
    });
});

test('the guest invariant completes a real MariaDB down up and remigration lifecycle', function () {
    if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        $this->markTestSkipped('The generated-column lifecycle requires MariaDB/MySQL.');
    }

    DB::table('carts')->delete();
    $guestHmac = hash('sha256', 'mariadb-upgrade-guest-owner');
    $migration = guestCartInvariantMigration();
    $guestInvariantInstalled = true;

    try {
        $migration->down();
        $guestInvariantInstalled = false;
        DB::table('carts')->insert([
            'public_id' => (string) str()->ulid(),
            'user_id' => null,
            'session_key' => $guestHmac,
            'status' => 'active',
            'currency' => 'SAR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::table('carts')->value('active_owner_key'))->toBeNull();

        $migration->up();
        $guestInvariantInstalled = true;
        expect(DB::table('carts')->value('active_owner_key'))->toBe("guest:{$guestHmac}");

        $migration->down();
        $guestInvariantInstalled = false;
        expect(DB::table('carts')->value('active_owner_key'))->toBeNull();

        $migration->up();
        $guestInvariantInstalled = true;
        expect(DB::table('carts')->value('active_owner_key'))->toBe("guest:{$guestHmac}");
    } finally {
        if (! $guestInvariantInstalled) {
            $migration->up();
        }

        DB::table('carts')->delete();
    }
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

function seedLegacyCart(
    ?int $userId,
    string $status,
    string $currency,
    ?string $ownerKey,
    ?string $sessionKey = null,
): void {
    DB::table('carts')->insert([
        'public_id' => (string) str()->ulid(),
        'user_id' => $userId,
        'session_key' => $sessionKey,
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

function guestCartInvariantMigration(): object
{
    return require database_path('migrations/2026_08_10_000003_expand_active_cart_invariant_to_guests.php');
}
