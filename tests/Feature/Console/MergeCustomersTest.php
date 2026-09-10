<?php

use App\Enums\UserRole;
use App\Enums\WalletEntryType;
use App\Loyalty\Support\WalletLedgerWriter;
use App\Models\Order;
use App\Models\Review;
use App\Models\StaffAuditLog;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;

function creditWallet(User $user, int $halalah): WalletAccount
{
    $writer = app(WalletLedgerWriter::class);
    $account = $writer->lockAccountFor($user->id);
    $writer->append($account, [
        'type' => WalletEntryType::Credit,
        'amount_halalah' => $halalah,
        'balance_delta_halalah' => $halalah,
        'order_id' => null,
        'refund_id' => null,
        'created_by_user_id' => null,
        'reference' => 'test-topup:'.$user->id.':'.$halalah,
        'metadata' => [],
    ]);

    return $account;
}

test('a duplicate account folds into the survivor: rows move, the duplicate is closed, the merge is audited', function (): void {
    $survivor = User::factory()->create(['email' => 'old@example.test', 'phone' => '+966500000001']);
    $duplicate = User::factory()->create(['email' => 'new@example.test', 'phone' => null]);
    $order = Order::factory()->for($duplicate)->create();
    $kept = Order::factory()->for($survivor)->create();
    creditWallet($duplicate, 5_000);
    creditWallet($survivor, 5_000);

    $this->artisan('customers:merge', [
        'from' => $duplicate->customer_number,
        'into' => $survivor->email,
    ])->assertSuccessful();

    expect($order->fresh()->user_id)->toBe($survivor->id)
        ->and($kept->fresh()->user_id)->toBe($survivor->id)
        ->and(Order::query()->where('user_id', $duplicate->id)->count())->toBe(0);

    $duplicate->refresh();
    $survivor->refresh();

    expect($duplicate->is_active)->toBeFalse()
        ->and($duplicate->email)->toBeNull()
        ->and($duplicate->password)->toBeNull()
        ->and($survivor->email)->toBe('old@example.test')
        ->and($survivor->is_active)->toBeTrue();

    // The default drops the duplicate balance: it is written off, not lost silently.
    expect((int) WalletAccount::query()->where('user_id', $duplicate->id)->value('balance_halalah'))->toBe(0)
        ->and((int) WalletAccount::query()->where('user_id', $survivor->id)->value('balance_halalah'))->toBe(5_000)
        ->and(WalletEntry::query()->where('reference', "customer-merge:{$duplicate->id}:{$survivor->id}:out")->count())->toBe(1);

    $log = StaffAuditLog::query()->where('action', 'customer.merged')->sole();

    expect($log->auditable_id)->toBe($survivor->id)
        ->and($log->actor_user_id)->toBeNull()
        ->and($log->metadata['from_public_id'])->toBe((string) $duplicate->public_id)
        ->and($log->metadata['wallet'])->toBe('drop')
        ->and($log->metadata['wallet_halalah'])->toBe(5_000);
});

test('the survivor can take the duplicate email and password, and its balance', function (): void {
    $survivor = User::factory()->create(['email' => 'old@example.test']);
    $duplicate = User::factory()->create(['email' => 'new@example.test', 'password' => 'new-secret']);
    $duplicateHash = (string) $duplicate->getRawOriginal('password');
    creditWallet($duplicate, 5_000);

    $this->artisan('customers:merge', [
        'from' => (string) $duplicate->public_id,
        'into' => $survivor->customer_number,
        '--wallet' => 'transfer',
        '--email' => 'from',
    ])->assertSuccessful();

    $survivor->refresh();

    expect($survivor->email)->toBe('new@example.test')
        ->and((string) $survivor->getRawOriginal('password'))->toBe($duplicateHash)
        ->and($duplicate->fresh()->email)->toBeNull()
        ->and((int) WalletAccount::query()->where('user_id', $survivor->id)->value('balance_halalah'))->toBe(5_000)
        ->and((int) WalletAccount::query()->where('user_id', $duplicate->id)->value('balance_halalah'))->toBe(0);
});

test('a dry run reports and changes nothing', function (): void {
    $survivor = User::factory()->create();
    $duplicate = User::factory()->create();
    $order = Order::factory()->for($duplicate)->create();

    $this->artisan('customers:merge', [
        'from' => $duplicate->customer_number,
        'into' => $survivor->customer_number,
        '--dry-run' => true,
    ])->expectsOutputToContain('Dry run')->assertSuccessful();

    expect($order->fresh()->user_id)->toBe($duplicate->id)
        ->and($duplicate->fresh()->is_active)->toBeTrue()
        ->and(StaffAuditLog::query()->count())->toBe(0);
});

test('staff accounts, unknown handles, and self-merges are refused', function (): void {
    $customer = User::factory()->create();
    $staff = User::factory()->create(['role' => UserRole::Staff]);

    $this->artisan('customers:merge', ['from' => 'CUS-ZZZZZZ', 'into' => $customer->customer_number])->assertFailed();
    $this->artisan('customers:merge', ['from' => $customer->customer_number, 'into' => $staff->email])->assertExitCode(2);
    $this->artisan('customers:merge', ['from' => $customer->customer_number, 'into' => $customer->customer_number])->assertExitCode(2);

    expect($customer->fresh()->is_active)->toBeTrue()
        ->and(StaffAuditLog::query()->count())->toBe(0);
});

test('reviews follow the customer so the storefront keeps attributing them', function (): void {
    $survivor = User::factory()->create();
    $duplicate = User::factory()->create();
    $order = Order::factory()->for($duplicate)->create();
    $review = Review::query()->create([
        'user_id' => $duplicate->id,
        'order_id' => $order->id,
        'reviewer_name' => 'متعب',
        'rating' => 5,
        'body_ar' => 'ممتاز',
        'is_visible' => true,
    ]);

    $this->artisan('customers:merge', ['from' => $duplicate->customer_number, 'into' => $survivor->customer_number])->assertSuccessful();

    expect($review->fresh()->user_id)->toBe($survivor->id);
});
