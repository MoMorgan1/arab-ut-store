<?php

use App\Admin\Actions\RecordStaffAudit;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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

test('recording an audit event permits an active Admin actor', function (): void {
    $actor = User::factory()->create(['role' => UserRole::Admin]);
    $event = new StaffAuditEvent('settings.updated', ['setting' => 'display_currency'], null);

    $audit = app(RecordStaffAudit::class)->execute($actor, null, $event);

    expect($audit->actor_user_id)->toBe($actor->id)
        ->and($audit->action)->toBe('settings.updated');
});

test('audit storage failures propagate the original database exception', function (): void {
    $actor = User::factory()->create(['role' => UserRole::Admin]);
    $event = new StaffAuditEvent('settings.updated', [], null);

    if (in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_staff_audit_log_insert
            BEFORE INSERT ON staff_audit_logs
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic staff audit write failure.'
            SQL);
    } else {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_staff_audit_log_insert
            BEFORE INSERT ON staff_audit_logs
            BEGIN
                SELECT RAISE(ABORT, 'Synthetic staff audit write failure.');
            END
            SQL);
    }

    try {
        expect(fn () => app(RecordStaffAudit::class)->execute($actor, null, $event))
            ->toThrow(QueryException::class, 'Synthetic staff audit write failure.');
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS fail_staff_audit_log_insert');
    }
});

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

test('audit events reject normalized secret-bearing metadata keys at nested levels', function (string $key): void {
    expect(fn () => new StaffAuditEvent('orders.status_changed', [
        'safe_context' => ['nested_context' => [$key => 'synthetic-secret']],
    ], null))->toThrow(InvalidArgumentException::class);
})->with([
    'uppercase password' => ['PASSWORD'],
    'Pascal recovery code' => ['RecoveryCode'],
    'camel provider metadata' => ['providerMetadata'],
    'uppercase snake encrypted payload' => ['ENCRYPTED_PAYLOAD'],
    'kebab recovery code' => ['recovery-code'],
    'acronym API token' => ['API_TOKEN'],
]);

test('audit events reject nested JSON-serializable objects before they can expose secret keys', function (): void {
    $serializedSecret = new class implements JsonSerializable
    {
        /** @return array<string, string> */
        public function jsonSerialize(): array
        {
            return ['apiToken' => 'synthetic-secret'];
        }
    };

    expect(fn () => new StaffAuditEvent('orders.status_changed', [
        'safe_context' => ['serialized' => $serializedSecret],
    ], null))->toThrow(InvalidArgumentException::class);
});

test('audit events reject non-finite numbers and resources before persistence', function (): void {
    $resource = fopen('php://temp', 'rb');

    try {
        expect(fn () => new StaffAuditEvent('orders.status_changed', ['nested' => ['amount' => INF]], null))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => new StaffAuditEvent('orders.status_changed', ['nested' => ['stream' => $resource]], null))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        fclose($resource);
    }
});
