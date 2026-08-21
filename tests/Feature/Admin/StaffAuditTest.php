<?php

use App\Admin\Actions\RecordStaffAudit;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

test('recording an audit event persists the actor subject stable action IP and safe metadata', function (): void {
    $actor = User::factory()->create(['role' => UserRole::Staff]);
    $subject = Order::factory()->create();
    $event = new StaffAuditEvent(
        action: 'orders.status_changed',
        metadata: [
            'previous_status' => 'received',
            'new_status' => 'in_progress',
            'request_id' => 'req_01HXYZ',
            'context' => ['case_reference' => 'CASE-42'],
        ],
        ipAddress: '2001:db8::1',
    );

    $audit = app(RecordStaffAudit::class)->execute($actor, $subject, $event);

    expect($audit->actor_user_id)->toBe($actor->id)
        ->and($audit->action)->toBe('orders.status_changed')
        ->and($audit->ip_address)->toBe('2001:db8::1')
        ->and($audit->metadata)->toBe([
            'previous_status' => 'received',
            'new_status' => 'in_progress',
            'request_id' => 'req_01HXYZ',
            'context' => ['case_reference' => 'CASE-42'],
        ])
        ->and($audit->auditable->is($subject))->toBeTrue();
});

test('recording an audit event rejects actors without active privileged access', function (
    UserRole $role,
    bool $isActive,
): void {
    $actor = User::factory()->create([
        'role' => $role,
        'is_active' => $isActive,
    ]);
    $event = new StaffAuditEvent('orders.status_changed', [], null);

    expect(fn () => app(RecordStaffAudit::class)->execute($actor, null, $event))
        ->toThrow(AuthorizationException::class);
})->with([
    'inactive admin' => [UserRole::Admin, false],
    'inactive staff' => [UserRole::Staff, false],
    'customer' => [UserRole::Customer, true],
    'service account' => [UserRole::ServiceAccount, true],
]);

test('audit events reject malformed action names', function (string $action): void {
    expect(fn () => new StaffAuditEvent($action, [], null))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing namespace' => ['order'],
    'uppercase segment' => ['orders.StatusChanged'],
    'numeric first segment' => ['1orders.updated'],
    'empty segment' => ['orders..updated'],
    'hyphenated segment' => ['orders.status-changed'],
]);

test('audit events reject IP addresses beyond the database boundary', function (): void {
    expect(fn () => new StaffAuditEvent('orders.status_changed', [], str_repeat('1', 46)))
        ->toThrow(InvalidArgumentException::class);
});

test('audit events reject forbidden metadata keys at every nesting level', function (string $key): void {
    expect(fn () => new StaffAuditEvent('orders.status_changed', [
        'safe_context' => [$key => 'synthetic-secret'],
    ], null))->toThrow(InvalidArgumentException::class);
})->with([
    'password' => ['password'],
    'credential' => ['credential'],
    'secret' => ['secret'],
    'token' => ['token'],
    'recovery code' => ['recovery_code'],
    'encrypted payload' => ['encrypted_payload'],
    'provider metadata' => ['provider_metadata'],
]);
