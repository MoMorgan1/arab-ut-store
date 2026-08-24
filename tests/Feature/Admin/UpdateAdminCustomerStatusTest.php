<?php

use App\Admin\Actions\UpdateAdminCustomerStatus;
use App\Enums\UserRole;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('guests and nonprivileged accounts cannot update customer status', function (): void {
    $customer = createStatusTestCustomer(true);

    $this->postJson("/admin/api/customers/{$customer->public_id}/status", [
        'action' => 'suspend',
        'reason_code' => 'abuse',
        'expected_active' => true,
    ])->assertUnauthorized();

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)
            ->postJson("/admin/api/customers/{$customer->public_id}/status", [
                'action' => 'suspend',
                'reason_code' => 'abuse',
                'expected_active' => true,
            ])
            ->assertForbidden();
    }
});

test('staff actors are forbidden from updating customer status', function (): void {
    $staff = createStatusTestAdmin(UserRole::Staff);
    $customer = createStatusTestCustomer(true);

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/customers/{$customer->public_id}/status", [
            'action' => 'suspend',
            'reason_code' => 'abuse',
            'expected_active' => true,
        ])
        ->assertForbidden();
});

test('confirmed admin can suspend customer, destroying sessions and writing audit log', function (): void {
    $admin = createStatusTestAdmin(UserRole::Admin);
    $customer = createStatusTestCustomer(true);

    // Create session for the customer
    createSessionsTableForTest();
    if (Schema::hasTable('sessions')) {
        DB::table('sessions')->insert([
            'id' => 'cust-session-1',
            'user_id' => $customer->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestBrowser',
            'payload' => 'payload-data',
            'last_activity' => time(),
        ]);
    }

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/status", [
            'action' => 'suspend',
            'reason_code' => 'fraud_suspected',
            'case_reference' => 'CASE-FRAUD-001',
            'expected_active' => true,
        ]);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'data' => [
                'isActive' => false,
            ],
        ]);

    expect($customer->fresh()->is_active)->toBeFalse();

    // Verify session was destroyed
    expect(DB::table('sessions')->where('user_id', $customer->id)->count())->toBe(0);

    // Verify audit log
    $log = StaffAuditLog::query()
        ->where('auditable_type', $customer->getMorphClass())
        ->where('auditable_id', $customer->getKey())
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('customers.suspended')
        ->and($log->actor_user_id)->toBe($admin->id)
        ->and($log->metadata)->toMatchArray([
            'reason_code' => 'fraud_suspected',
            'case_reference' => 'CASE-FRAUD-001',
            'previous_active' => true,
            'new_active' => false,
        ]);
});

test('confirmed admin can reactivate customer and write audit log', function (): void {
    $admin = createStatusTestAdmin(UserRole::Admin);
    $customer = createStatusTestCustomer(false);

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/status", [
            'action' => 'reactivate',
            'reason_code' => 'account_recovery',
            'case_reference' => 'REC-7890',
            'expected_active' => false,
        ]);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'data' => [
                'isActive' => true,
            ],
        ]);

    expect($customer->fresh()->is_active)->toBeTrue();

    $log = StaffAuditLog::query()
        ->where('auditable_type', $customer->getMorphClass())
        ->where('auditable_id', $customer->getKey())
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('customers.reactivated')
        ->and($log->metadata['new_active'])->toBeTrue();
});

test('stale mutation throws AdminCustomerStatusConflict with 409 json response', function (): void {
    $admin = createStatusTestAdmin(UserRole::Admin);
    $customer = createStatusTestCustomer(true);

    // Provide expected_active = false when DB is currently true
    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/status", [
            'action' => 'reactivate',
            'reason_code' => 'customer_request',
            'expected_active' => false,
        ]);

    $response->assertStatus(409)
        ->assertJson([
            'customer' => (string) $customer->public_id,
            'isActive' => true,
        ]);
});

test('status request rejects unknown fields and invalid parameters', function (
    array $payload,
    string $expectedErrorField,
): void {
    $admin = createStatusTestAdmin(UserRole::Admin);
    $customer = createStatusTestCustomer(true);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/status", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($expectedErrorField);
})->with([
    'unknown field' => [
        [
            'action' => 'suspend',
            'reason_code' => 'abuse',
            'expected_active' => true,
            'malicious_key' => 'evil',
        ],
        'unexpected_fields',
    ],
    'invalid action' => [
        [
            'action' => 'delete_account',
            'reason_code' => 'abuse',
            'expected_active' => true,
        ],
        'action',
    ],
    'invalid reason code' => [
        [
            'action' => 'suspend',
            'reason_code' => 'not_a_valid_reason',
            'expected_active' => true,
        ],
        'reason_code',
    ],
    'invalid case reference' => [
        [
            'action' => 'suspend',
            'reason_code' => 'abuse',
            'case_reference' => 'INVALID CHARS *&^%',
            'expected_active' => true,
        ],
        'case_reference',
    ],
    'missing expected_active' => [
        [
            'action' => 'suspend',
            'reason_code' => 'abuse',
        ],
        'expected_active',
    ],
]);

test('cannot update status of non-customer accounts', function (): void {
    $admin = createStatusTestAdmin(UserRole::Admin);
    $staff = createStatusTestAdmin(UserRole::Staff);

    expect(fn () => app(UpdateAdminCustomerStatus::class)->execute(
        actor: $admin,
        customerPublicId: (string) $staff->public_id,
        action: 'suspend',
        reasonCode: 'abuse',
        caseReference: null,
        expectedActive: true,
    ))->toThrow(AuthorizationException::class);
});

function createStatusTestCustomer(bool $active): User
{
    return User::factory()->create([
        'role' => UserRole::Customer,
        'is_active' => $active,
    ]);
}

function createStatusTestAdmin(UserRole $role): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINSTATUSSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
