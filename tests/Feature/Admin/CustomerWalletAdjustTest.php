<?php

use App\Enums\UserRole;
use App\Enums\WalletEntryType;
use App\Models\StaffAuditLog;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('guests and nonprivileged accounts cannot adjust customer wallet', function (): void {
    $customer = createWalletTestCustomer(5000);

    $this->postJson("/admin/api/customers/{$customer->public_id}/wallet/adjust", [
        'amount_halalah' => 2000,
        'reason' => 'Goodwill compensation for delay',
    ])->assertUnauthorized();

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)
            ->postJson("/admin/api/customers/{$customer->public_id}/wallet/adjust", [
                'amount_halalah' => 2000,
                'reason' => 'Goodwill compensation for delay',
            ])
            ->assertForbidden();
    }
});

test('staff users are forbidden from adjusting customer wallet', function (): void {
    $staff = createWalletTestAdmin(UserRole::Staff);
    $customer = createWalletTestCustomer(5000);

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/wallet/adjust", [
            'amount_halalah' => 2000,
            'reason' => 'Goodwill compensation for delay',
        ])
        ->assertForbidden();
});

test('confirmed admin can credit customer wallet and write staff audit log', function (): void {
    $admin = createWalletTestAdmin(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/wallet/adjust", [
            'amount_halalah' => 3000,
            'reason' => 'Customer appreciation goodwill credit',
        ]);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'data' => [
                'balance' => [
                    'amountMinor' => '3000',
                    'currency' => 'SAR',
                ],
                'entry' => [
                    'type' => 'adjustment',
                    'direction' => 'credit',
                    'amount' => [
                        'amountMinor' => '3000',
                        'currency' => 'SAR',
                    ],
                ],
            ],
        ]);

    $account = WalletAccount::query()->where('user_id', $customer->id)->first();
    expect($account?->balance_halalah)->toBe(3000);

    $entry = WalletEntry::query()->where('wallet_account_id', $account?->id)->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->type)->toBe(WalletEntryType::Adjustment)
        ->and($entry->amount_halalah)->toBe(3000)
        ->and($entry->balance_after_halalah)->toBe(3000)
        ->and($entry->created_by_user_id)->toBe($admin->id)
        ->and(str_starts_with((string) $entry->reference, 'admin-adjustment:'))->toBeTrue();

    $log = StaffAuditLog::query()
        ->where('auditable_type', $customer->getMorphClass())
        ->where('auditable_id', $customer->getKey())
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('customers.wallet_adjusted')
        ->and($log->actor_user_id)->toBe($admin->id)
        ->and($log->metadata['balance_after_halalah'])->toBe(3000);
});

test('confirmed admin can debit customer wallet', function (): void {
    $admin = createWalletTestAdmin(UserRole::Admin);
    $customer = createWalletTestCustomer(10000);

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/wallet/adjust", [
            'amount_halalah' => -4000,
            'reason' => 'Correction for duplicated promo code payout',
        ]);

    $response->assertOk()
        ->assertJson([
            'data' => [
                'balance' => [
                    'amountMinor' => '6000',
                    'currency' => 'SAR',
                ],
                'entry' => [
                    'type' => 'adjustment',
                    'direction' => 'debit',
                    'amount' => [
                        'amountMinor' => '4000',
                        'currency' => 'SAR',
                    ],
                ],
            ],
        ]);

    $account = WalletAccount::query()->where('user_id', $customer->id)->first();
    expect($account?->balance_halalah)->toBe(6000);
});

test('wallet debit driving balance negative is rejected with 422 error', function (): void {
    $admin = createWalletTestAdmin(UserRole::Admin);
    $customer = createWalletTestCustomer(2000);

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/wallet/adjust", [
            'amount_halalah' => -5000,
            'reason' => 'Attempted overdraft debit',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('amount_halalah');

    $account = WalletAccount::query()->where('user_id', $customer->id)->first();
    expect($account?->balance_halalah)->toBe(2000);
});

test('wallet adjustment enforces validation constraints', function (
    array $payload,
    string $expectedErrorField,
): void {
    $admin = createWalletTestAdmin(UserRole::Admin);
    $customer = createWalletTestCustomer(5000);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/wallet/adjust", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($expectedErrorField);
})->with([
    'zero amount' => [
        [
            'amount_halalah' => 0,
            'reason' => 'Zero amount test',
        ],
        'amount_halalah',
    ],
    'amount too large' => [
        [
            'amount_halalah' => 150000,
            'reason' => 'Amount exceeds 100,000 Halalah',
        ],
        'amount_halalah',
    ],
    'negative amount too large' => [
        [
            'amount_halalah' => -150000,
            'reason' => 'Negative amount exceeds 100,000 Halalah',
        ],
        'amount_halalah',
    ],
    'reason too short' => [
        [
            'amount_halalah' => 1000,
            'reason' => 'bad',
        ],
        'reason',
    ],
    'reason too long' => [
        [
            'amount_halalah' => 1000,
            'reason' => str_repeat('A', 250),
        ],
        'reason',
    ],
    'unknown field' => [
        [
            'amount_halalah' => 1000,
            'reason' => 'Valid reason here',
            'extra_field' => 'evil',
        ],
        'unexpected_fields',
    ],
]);

function createWalletTestCustomer(int $balanceHalalah): User
{
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    WalletAccount::factory()->create([
        'user_id' => $customer->id,
        'balance_halalah' => $balanceHalalah,
    ]);

    return $customer;
}

function createWalletTestAdmin(UserRole $role): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINWALLETSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
