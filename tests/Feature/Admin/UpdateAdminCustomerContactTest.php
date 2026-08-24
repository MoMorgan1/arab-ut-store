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
        'expected' => currentContactOf($customer),
    ])->assertUnauthorized();

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)
            ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
                'first_name' => 'NewFirst',
                'last_name' => 'NewLast',
                'email' => 'new.email@example.test',
                'phone' => '+966500000002',
                'expected' => currentContactOf($customer),
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
            'expected' => currentContactOf($customer),
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
            'expected' => currentContactOf($customer),
        ])
        ->assertForbidden();
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
            'expected' => currentContactOf($customer),
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
            'expected' => currentContactOf($customer),
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
            'expected' => currentContactOf($customer),
        ]);

    $response->assertOk()
        ->assertJson([
            'data' => [
                'phone' => null,
            ],
        ]);

    $refreshed = $customer->fresh();

    // Clearing the number clears its verification stamp: a verified-at for a
    // phone that no longer exists is a claim about nothing. The email stamp is
    // untouched.
    expect($refreshed->phone)->toBeNull()
        ->and($refreshed->phone_verified_at)->toBeNull()
        ->and($refreshed->email_verified_at)->not->toBeNull();
});

test('replacing a phone keeps its verification stamp', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer = createContactTestCustomer();
    $originalPhoneVerifiedAt = $customer->phone_verified_at;

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => '+966509876543',
            'expected' => currentContactOf($customer),
        ])
        ->assertOk();

    expect($customer->fresh()->phone_verified_at?->timestamp)
        ->toBe($originalPhoneVerifiedAt?->timestamp);
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
            'expected' => currentContactOf($customer1),
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
            'expected' => currentContactOf($customer1),
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
                'expected' => currentContactOf($target),
            ])
            ->assertForbidden();
    }
});

test('an edit whose expectation matches is accepted even when the row was touched in the same second', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer = createContactTestCustomer();
    $expected = currentContactOf($customer);

    // A same-second unrelated write is exactly what a timestamp token could not
    // distinguish from a stale read.
    $customer->forceFill(['preferred_locale' => 'en'])->save();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => 'NewFirst',
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'expected' => $expected,
        ])
        ->assertOk();

    expect($customer->fresh()->first_name)->toBe('NewFirst');
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
            'expected' => currentContactOf($customer1),
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
            'expected' => currentContactOf($customer),
        ])
        ->assertOk();

    expect($customer->fresh()->email)->toBe('mixed.case@example.test');
});

test('an expectation that no longer matches the row is a 409 carrying the live values', function (): void {
    $admin = createContactTestAdmin(UserRole::Admin);
    $customer = createContactTestCustomer();

    $stale = currentContactOf($customer);
    $customer->forceFill(['email' => 'moved.by.someone.else@example.test'])->save();

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/contact", [
            'first_name' => 'NewName',
            'last_name' => $customer->last_name,
            'email' => $stale['email'],
            'phone' => $customer->phone,
            'expected' => $stale,
        ]);

    $response->assertStatus(409)
        ->assertJson([
            'customer' => (string) $customer->public_id,
            'current' => [
                'email' => 'moved.by.someone.else@example.test',
            ],
        ]);

    expect($customer->fresh()->first_name)->toBe('OriginalFirst');
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
            'expected' => currentContactOf($customer),
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
            'expected' => currentContactOf($customer),
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
        'expected' => currentContactOf($customer),
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
    'missing expectation' => [
        ['expected' => ''],
        'expected',
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
        expected: currentContactOf($staff),
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

/**
 * @return array{first_name: string, last_name: string, email: string, phone: string|null}
 */
function currentContactOf(User $user): array
{
    return [
        'first_name' => (string) $user->first_name,
        'last_name' => (string) $user->last_name,
        'email' => (string) $user->email,
        'phone' => $user->phone,
    ];
}
