<?php

use App\Admin\Queries\ReadAdminOverview;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

test('MariaDB captured revenue preserves an aggregate beyond PHP integer range', function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        $this->markTestSkipped('The aggregate precision contract requires MariaDB/MySQL.');
    }

    Carbon::setTestNow(Carbon::parse('2040-01-15 12:00:00', 'UTC'));
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $order = Order::factory()->for($admin)->create([
        'status' => OrderStatus::Completed,
        'placed_at' => now()->subDay(),
    ]);

    foreach (range(1, 2) as $sequence) {
        $order->payments()->create([
            'provider' => 'paylink',
            'provider_payment_id' => (string) Str::uuid(),
            'status' => PaymentStatus::Paid,
            'currency' => 'SAR',
            'amount_halalah' => PHP_INT_MAX,
            'captured_halalah' => PHP_INT_MAX,
            'refunded_halalah' => 0,
            'idempotency_key' => "admin-overview-max-{$sequence}-".Str::uuid(),
            'paid_at' => now()->subHour(),
        ]);
    }

    expect(app(ReadAdminOverview::class)->for($admin, 7)['capturedRevenue'])->toBe([
        'amountMinor' => '18446744073709551614',
        'currency' => 'SAR',
    ]);
});
