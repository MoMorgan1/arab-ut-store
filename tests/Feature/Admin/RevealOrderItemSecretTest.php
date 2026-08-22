<?php

use App\Admin\Actions\RevealOrderItemSecret;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\SecretAccessLog;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('admin and staff actors can reveal order item secret with recent password confirmation', function (
    UserRole $role,
    string $prefix,
): void {
    $actor = createRevealTestActor($role);
    [$order, $item, $secret] = createRevealTestOrderWithSecret();

    $response = $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("{$prefix}/api/orders/{$order->public_id}/items/{$item->public_id}/reveal", [
            'purpose' => 'fulfillment',
            'case_reference' => 'TICKET-101',
        ]);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Type', 'application/json')
        ->assertJson([
            'data' => [
                'ea_email' => 'player@example.com',
                'ea_password' => 'SecretPassword123!',
                'ea_backup_codes' => ['11111111', '22222222'],
            ],
        ])
        ->assertJsonMissing(['encrypted_payload']);

    // Exactly one secret_access_logs row created
    $accessLogs = SecretAccessLog::query()
        ->where('order_item_secret_id', $secret->id)
        ->get();

    expect($accessLogs)->toHaveCount(1);
    $log = $accessLogs->first();
    expect($log->user_id)->toBe($actor->id)
        ->and($log->purpose)->toBe('fulfillment')
        ->and($log->case_reference)->toBe('TICKET-101')
        ->and($log->ip_address)->not->toBeNull();

    // Exactly one staff_audit_logs row created with EXACT metadata keys
    $auditLogs = StaffAuditLog::query()
        ->where('auditable_type', $secret->getMorphClass())
        ->where('auditable_id', $secret->id)
        ->get();

    expect($auditLogs)->toHaveCount(1);
    $audit = $auditLogs->first();
    expect($audit->actor_user_id)->toBe($actor->id)
        ->and($audit->action)->toBe('secrets.revealed')
        ->and($audit->metadata)->toBe([
            'purpose' => 'fulfillment',
            'case_reference' => 'TICKET-101',
            'order_item_public_id' => (string) $item->public_id,
        ]);
})->with([
    'admin default prefix' => [UserRole::Admin, '/admin'],
    'admin localized prefix' => [UserRole::Admin, '/en/admin'],
    'staff default prefix' => [UserRole::Staff, '/admin'],
    'staff localized prefix' => [UserRole::Staff, '/en/admin'],
]);

test('guests and nonprivileged accounts cannot reveal order item secrets', function (): void {
    [$order, $item] = createRevealTestOrderWithSecret();
    $url = "/admin/api/orders/{$order->public_id}/items/{$item->public_id}/reveal";
    $payload = ['purpose' => 'fulfillment'];

    $this->postJson($url, $payload)->assertUnauthorized();

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson($url, $payload)
            ->assertForbidden();
    }

    $inactiveStaff = createRevealTestActor(UserRole::Staff);
    $inactiveStaff->forceFill(['is_active' => false])->save();

    $this->actingAs($inactiveStaff)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson($url, $payload)
        ->assertForbidden();
});

test('unconfirmed MFA privileged actors are redirected to MFA setup', function (): void {
    $admin = createRevealTestActor(UserRole::Admin);
    $admin->forceFill(['two_factor_confirmed_at' => null])->save();
    [$order, $item] = createRevealTestOrderWithSecret();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/orders/{$order->public_id}/items/{$item->public_id}/reveal", [
            'purpose' => 'fulfillment',
        ])
        ->assertRedirect('/admin/security/mfa');
});

test('reveal endpoint requires a recent password confirmation else returns 423', function (): void {
    $admin = createRevealTestActor(UserRole::Admin);
    [$order, $item] = createRevealTestOrderWithSecret();

    $this->actingAs($admin)
        ->postJson("/admin/api/orders/{$order->public_id}/items/{$item->public_id}/reveal", [
            'purpose' => 'fulfillment',
        ])
        ->assertStatus(423);

    // No access logs or audit logs written on 423
    expect(SecretAccessLog::count())->toBe(0)
        ->and(StaffAuditLog::count())->toBe(0);
});

test('unknown order, unknown item, or missing secret returns 404', function (): void {
    $admin = createRevealTestActor(UserRole::Admin);
    [$order, $item] = createRevealTestOrderWithSecret();

    // Unknown order
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/orders/01K5UNKNOWN0000000000000000/items/{$item->public_id}/reveal", [
            'purpose' => 'fulfillment',
        ])
        ->assertNotFound();

    // Unknown item
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/orders/{$order->public_id}/items/01K5UNKNOWN0000000000000000/reveal", [
            'purpose' => 'fulfillment',
        ])
        ->assertNotFound();

    // Item without secret
    $itemWithoutSecret = $order->items()->create([
        'sku' => 'AUT-NO-SECRET',
        'name_ar' => 'عنصر بدون سر',
        'name_en' => 'No Secret Item',
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::Received,
        'quantity' => 1,
        'unit_price_halalah' => 1000,
        'subtotal_halalah' => 1000,
        'discount_halalah' => 0,
        'total_halalah' => 1000,
    ]);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/orders/{$order->public_id}/items/{$itemWithoutSecret->public_id}/reveal", [
            'purpose' => 'fulfillment',
        ])
        ->assertNotFound();
});

test('all four purpose codes are accepted and invalid purpose codes return 422', function (
    string $purpose,
    bool $isValid,
): void {
    $admin = createRevealTestActor(UserRole::Admin);
    [$order, $item] = createRevealTestOrderWithSecret();

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/orders/{$order->public_id}/items/{$item->public_id}/reveal", [
            'purpose' => $purpose,
        ]);

    if ($isValid) {
        $response->assertOk();
    } else {
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['purpose']);
    }
})->with([
    'fulfillment' => ['fulfillment', true],
    'customer_support' => ['customer_support', true],
    'order_review' => ['order_review', true],
    'incident_investigation' => ['incident_investigation', true],
    'invalid purpose' => ['curiosity', false],
    'empty string purpose' => ['', false],
    'arbitrary string purpose' => ['debugging_production', false],
]);

test('case reference boundary validation enforces length and allowed characters', function (
    ?string $caseReference,
    bool $isValid,
): void {
    $admin = createRevealTestActor(UserRole::Admin);
    [$order, $item] = createRevealTestOrderWithSecret();

    $body = ['purpose' => 'fulfillment'];
    if ($caseReference !== null) {
        $body['case_reference'] = $caseReference;
    }

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/orders/{$order->public_id}/items/{$item->public_id}/reveal", $body);

    if ($isValid) {
        $response->assertOk();
    } else {
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['case_reference']);
    }
})->with([
    'null case reference' => [null, true],
    'empty string case reference' => ['', true],
    'valid standard case' => ['CR-2026.08_123:ABC', true],
    'valid single character' => ['A', true],
    'valid 64 characters' => [str_repeat('a', 64), true],
    'invalid 65 characters' => [str_repeat('a', 65), false],
    'invalid spaces' => ['TICKET 1234', false],
    'invalid special character #' => ['TICKET#1234', false],
    'invalid quotes' => ['"ticket"', false],
]);

test('unknown body fields are strictly rejected with 422', function (): void {
    $admin = createRevealTestActor(UserRole::Admin);
    [$order, $item] = createRevealTestOrderWithSecret();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/orders/{$order->public_id}/items/{$item->public_id}/reveal", [
            'purpose' => 'fulfillment',
            'case_reference' => 'CR-101',
            'extra_field' => 'should_fail',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['unexpected_fields']);
});

test('purged or expired secrets return 410 Gone with secret_purged error', function (
    bool $isSoftDeleted,
    bool $isPastRetention,
): void {
    $admin = createRevealTestActor(UserRole::Admin);
    [$order, $item, $secret] = createRevealTestOrderWithSecret();

    if ($isSoftDeleted) {
        $secret->forceFill(['deleted_at' => now()->subHour()])->save();
    }

    if ($isPastRetention) {
        $secret->forceFill(['retained_until' => now()->subDay()])->save();
    }

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/orders/{$order->public_id}/items/{$item->public_id}/reveal", [
            'purpose' => 'fulfillment',
        ]);

    $response->assertStatus(410)
        ->assertJson(['error' => 'secret_purged']);

    // Neither access log nor audit log is created for purged/expired secret
    expect(SecretAccessLog::count())->toBe(0)
        ->and(StaffAuditLog::count())->toBe(0);
})->with([
    'soft deleted' => [true, false],
    'past retained_until' => [false, true],
    'both soft deleted and past retained_until' => [true, true],
]);

test('retained_until in the future is not purged and successfully reveals', function (): void {
    $admin = createRevealTestActor(UserRole::Admin);
    [$order, $item, $secret] = createRevealTestOrderWithSecret();

    $secret->forceFill(['retained_until' => now()->addDays(7)])->save();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/orders/{$order->public_id}/items/{$item->public_id}/reveal", [
            'purpose' => 'order_review',
        ])
        ->assertOk()
        ->assertJsonPath('data.ea_email', 'player@example.com');
});

test('audit failure rolls back the entire reveal transaction including access log', function (): void {
    $admin = createRevealTestActor(UserRole::Admin);
    [$order, $item] = createRevealTestOrderWithSecret();

    // A 46-character IP makes StaffAuditEvent reject AFTER the access log
    // insert but BEFORE commit, proving the transactional pairing.
    expect(fn () => app(RevealOrderItemSecret::class)->execute(
        actor: $admin,
        orderPublicId: (string) $order->public_id,
        itemPublicId: (string) $item->public_id,
        purpose: 'fulfillment',
        caseReference: null,
        ipAddress: str_repeat('9', 46),
    ))->toThrow(InvalidArgumentException::class);

    expect(SecretAccessLog::count())->toBe(0)
        ->and(StaffAuditLog::count())->toBe(0);
});

test('successful reveal writes case_reference null in metadata when omitted', function (): void {
    $staff = createRevealTestActor(UserRole::Staff);
    [$order, $item, $secret] = createRevealTestOrderWithSecret();

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/orders/{$order->public_id}/items/{$item->public_id}/reveal", [
            'purpose' => 'customer_support',
        ])
        ->assertOk();

    $audit = StaffAuditLog::query()
        ->where('auditable_type', $secret->getMorphClass())
        ->where('auditable_id', $secret->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata)->toBe([
            'purpose' => 'customer_support',
            'case_reference' => null,
            'order_item_public_id' => (string) $item->public_id,
        ]);
});

/**
 * @return array{0: Order, 1: OrderItem, 2: OrderItemSecret}
 */
function createRevealTestOrderWithSecret(): array
{
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    $order = Order::factory()->for($customer)->create([
        'order_number' => 'AUT-REVEAL-'.Str::random(6),
        'status' => OrderStatus::InProgress,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'wallet_halalah' => 0,
        'payment_halalah' => 5000,
        'total_halalah' => 5000,
        'currency' => 'SAR',
        'placed_at' => now()->subDay(),
        'paid_at' => now()->subDay(),
    ]);

    $item = $order->items()->create([
        'sku' => 'AUT-REVEAL-SKU',
        'name_ar' => 'خدمة فيفا سرية',
        'name_en' => 'FC Secret Service',
        'service_type' => ServiceType::FutChampions,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::InProgress,
        'quantity' => 1,
        'unit_price_halalah' => 5000,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'total_halalah' => 5000,
    ]);

    $secret = new OrderItemSecret([
        'order_item_id' => $item->id,
        'masked_summary' => ['account' => 'p***r@example.com', 'backupCodesCount' => 2],
    ]);
    $secret->forceFill([
        'encrypted_payload' => [
            'ea_email' => 'player@example.com',
            'ea_password' => 'SecretPassword123!',
            'ea_backup_codes' => ['11111111', '22222222'],
        ],
    ])->save();

    return [$order, $item, $secret];
}

function createRevealTestActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINREVEALSTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
