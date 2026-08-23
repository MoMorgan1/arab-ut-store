<?php

use App\Admin\Actions\UpdateAdminCustomerContact;
use App\Enums\UserRole;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('guests and nonprivileged accounts cannot update customer contact details', function (): void {
    $customer = createContactTestCustomer();

    $this->postJson("/admin/api/customers/{$customer->public_id}/contact", [
        'first_name' => 'NewFirst',
        'last_name' => 'NewLast',
        'email' => 'new.email@example.test',
        'phone' => '+966500000002',
        'expected_updated_at' => $customer->updated_at->toIso8601String(),
    ])->assertUnauthorized();

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)
            ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
                'first_name' => 'NewFirst',
                'last_name' => 'NewLast',
                'email' => 'new.email@example.test',
                'phone' => '+966500000002',
                'expected_updated_at' => $customer->updated_at->toIso8601String(),
            ])
            ->assertForbidden();
    }
});

test('staff actors and inactive admin actors are forbidden from updating customer contact details', function (): void {
    $staff = createContactTestAdmin(UserRole::Staff);
    $customer = createContactTestCustomer();

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => 'NewFirst',
            'last_name' => 'NewLast',
            'email' => 'new.email@example.test',
            'phone' => '+966500000002',
            'expected_updated_at' => $customer->updated_at->toIso8601String(),
        ])
        ->assertForbidden();

    $inactiveAdmin = createContactTestAdmin(UserRole::Admin);
    $inactiveAdmin->forceFill(['is_active' => false])->save();

    $this->actingAs($inactiveAdmin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => 'NewFirst',
            'last_name' => 'NewLast',
            'email' => 'new.email@example.test',
            'phone' => '+966500000002',
            'expected_updated_at' => $customer->updated_at->toIso8601String(),
        ])
        ->assertForbidden();
});

test('admin actor without password confirmation receives 423', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer = createContactTestCustomer();

    $this->actingAs($admin)
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => 'NewFirst',
            'last_name' => 'NewLast',
            'email' => 'new.email@example.test',
            'phone' => '+966500000002',
            'expected_updated_at' => $customer->updated_at->toIso8601String(),
        ])
        ->assertStatus(423);
});

test('confirmed admin can update name, email, and phone successfully', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer = createContactTestCustomer();

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => 'UpdatedFirst',
            'last_name' => 'UpdatedLast',
            'email' => 'updated.email@example.test',
            'phone' => '+966501112233',
            'expected_updated_at' => $customer->updated_at->toIso8601String(),
        ]);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'data' => [
                'firstName' => 'UpdatedFirst',
                'lastName' => 'UpdatedLast',
                'email' => 'updated.email@example.test',
                'phone' => '+966501112233',
            ],
        ]);

    $refreshed = $customer->fresh();
    expect($refreshed->first_name)->toBe('UpdatedFirst')
        ->and($refreshed->last_name)->toBe('UpdatedLast')
        ->and($refreshed->email)->toBe('updated.email@example.test')
        ->and($refreshed->phone)->toBe('+966501112233');
});

test('email_verified_at and phone_verified_at are unchanged after edit and sessions are not deleted', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer = createContactTestCustomer();

    $originalEmailVerifiedAt = $customer->email_verified_at;
    $originalPhoneVerifiedAt = $customer->phone_verified_at;

    // The app runs the array session driver under test, so there is no
    // sessions table unless one is created here. Without it both assertions
    // below silently skip and this test proves nothing.
    createSessionsTableForTest();
    DB::table('sessions')->insert([
        'id' => 'cust-contact-session-1',
        'user_id' => $customer->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
        'payload' => 'payload-data',
        'last_activity' => time(),
    ]);

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => 'NewName',
            'last_name' => $customer->last_name,
            'email' => 'new.verified.email@example.test',
            'phone' => '+966509998877',
            'expected_updated_at' => $customer->updated_at->toIso8601String(),
        ]);

    $response->assertOk();

    $refreshed = $customer->fresh();
    expect($refreshed->email_verified_at?->timestamp)->toBe($originalEmailVerifiedAt?->timestamp)
        ->and($refreshed->phone_verified_at?->timestamp)->toBe($originalPhoneVerifiedAt?->timestamp);

    // Verify session was NOT destroyed
    expect(DB::table('sessions')->where('user_id', $customer->id)->count())->toBe(1);
});

test('phone can be set to null', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer = createContactTestCustomer();
    expect($customer->phone)->not->toBeNull();

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => null,
            'expected_updated_at' => $customer->updated_at->toIso8601String(),
        ]);

    $response->assertOk()
        ->assertJson([
            'data' => [
                'phone' => null,
            ],
        ]);

    expect($customer->fresh()->phone)->toBeNull();
});

test('duplicate email belonging to another user is rejected with 422', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer1 = createContactTestCustomer();
    $customer2 = createContactTestCustomer();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer1->public_id}/contact", [
            'first_name' => $customer1->first_name,
            'last_name' => $customer1->last_name,
            'email' => $customer2->email,
            'phone' => $customer1->phone,
            'expected_updated_at' => $customer1->updated_at->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('duplicate phone belonging to another user is rejected with 422', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer1 = createContactTestCustomer();
    $customer2 = createContactTestCustomer();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer1->public_id}/contact", [
            'first_name' => $customer1->first_name,
            'last_name' => $customer1->last_name,
            'email' => $customer1->email,
            'phone' => $customer2->phone,
            'expected_updated_at' => $customer1->updated_at->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});

test('an admin cannot edit another admin or their own account through the route', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $otherAdmin = createContactTestAdmin(UserRole::Admin);

    foreach ([$otherAdmin, $admin] as $target) {
        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/admin/api/customers/{$target->public_id}/contact", [
                'first_name' => 'NewFirst',
                'last_name' => 'NewLast',
                'email' => 'route.target@example.test',
                'phone' => '+966500000123',
                'expected_updated_at' => $target->updated_at->utc()->toIso8601String(),
            ])
            ->assertForbidden();
    }
});

test('a nonempty but unparseable expected_updated_at is a conflict, not a validation error', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer = createContactTestCustomer();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'expected_updated_at' => 'not-a-timestamp',
        ])
        ->assertStatus(409);
});

test('an email differing only by case is still rejected as a duplicate', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer1 = createContactTestCustomer();
    $customer2 = createContactTestCustomer();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer1->public_id}/contact", [
            'first_name' => $customer1->first_name,
            'last_name' => $customer1->last_name,
            'email' => strtoupper($customer2->email),
            'phone' => $customer1->phone,
            'expected_updated_at' => $customer1->updated_at->utc()->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('an edited email is stored lowercased', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer = createContactTestCustomer();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => '  MiXeD.Case@Example.Test  ',
            'phone' => $customer->phone,
            'expected_updated_at' => $customer->updated_at->utc()->toIso8601String(),
        ])
        ->assertOk();

    expect($customer->fresh()->email)->toBe('mixed.case@example.test');
});

test('stale expected_updated_at throws AdminCustomerContactConflict with 409 response', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer = createContactTestCustomer();

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => 'NewName',
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'expected_updated_at' => '2020-01-01T00:00:00Z',
        ]);

    $response->assertStatus(409)
        ->assertJson([
            'customer' => (string) $customer->public_id,
            'updatedAt' => $customer->updated_at->toIso8601String(),
        ]);
});

test('staff audit record with action customers.contact_updated names only changed fields', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer = createContactTestCustomer();

    $oldEmail = $customer->email;
    $newEmail = 'brand.new.email@example.test';

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $newEmail,
            'phone' => $customer->phone,
            'expected_updated_at' => $customer->updated_at->toIso8601String(),
        ]);

    $response->assertOk();

    $log = StaffAuditLog::query()
        ->where('auditable_type', $customer->getMorphClass())
        ->where('auditable_id', $customer->getKey())
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('customers.contact_updated')
        ->and($log->actor_user_id)->toBe($admin->id)
        ->and($log->metadata)->toMatchArray([
            'contact_changed' => ['email'],
            'contact_previous' => ['email' => $oldEmail],
            'contact_new' => ['email' => $newEmail],
        ]);
});

test('no audit record is written when nothing changed', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer = createContactTestCustomer();

    $initialLogCount = StaffAuditLog::query()->count();

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'expected_updated_at' => $customer->updated_at->toIso8601String(),
        ]);

    $response->assertOk();

    expect(StaffAuditLog::query()->count())->toBe($initialLogCount);
});

test('contact request rejects unknown fields and invalid parameters', function (
    array $payload,
    string $expectedErrorField,
): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer = createContactTestCustomer();

    $basePayload = [
        'first_name' => 'ValidFirst',
        'last_name' => 'ValidLast',
        'email' => 'valid.contact@example.test',
        'phone' => '+966501234567',
        'expected_updated_at' => $customer->updated_at->toIso8601String(),
    ];

    $merged = array_merge($basePayload, $payload);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", $merged)
        ->assertStatus(422)
        ->assertJsonValidationErrors($expectedErrorField);
})->with([
    'unknown field' => [
        ['malicious_key' => 'evil'],
        'unexpected_fields',
    ],
    'invalid email format' => [
        ['email' => 'not-an-email'],
        'email',
    ],
    'invalid phone format' => [
        ['phone' => '0501234567'],
        'phone',
    ],
    'missing first name' => [
        ['first_name' => ''],
        'first_name',
    ],
    'missing last name' => [
        ['last_name' => ''],
        'last_name',
    ],
    'missing expected_updated_at' => [
        ['expected_updated_at' => ''],
        'expected_updated_at',
    ],
]);

test('cannot update contact details of non-customer accounts', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $staff = createContactTestAdmin(UserRole::Staff);

    expect(fn () => app(UpdateAdminCustomerContact::class)->execute(
        actor: $admin,
        customerPublicId: (string) $staff->public_id,
        firstName: 'NewName',
        lastName: 'NewLast',
        email: 'new.staff@example.test',
        phone: null,
        expectedUpdatedAt: $staff->updated_at->toIso8601String(),
    ))->toThrow(AuthorizationException::class);
});

function createContactTestCustomer(): User
{
    // The factory leaves phone null, and several cases here need a distinct
    // stored number per customer, so assign one explicitly.
    static $sequence = 0;
    $sequence++;

    return User::factory()->create([
        'role' => UserRole::Customer,
        'first_name' => 'OriginalFirst',
        'last_name' => 'OriginalLast',
        'phone' => sprintf('+96650100%04d', $sequence),
        'email_verified_at' => now()->subDays(10),
        'phone_verified_at' => now()->subDays(5),
        'is_active' => true,
    ]);
}

function createContactTestAdmin(UserRole $role): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINCONTACTTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
