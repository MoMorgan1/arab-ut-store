<?php

use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Enums\UserRole;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

function resendActor(UserRole $role = UserRole::Admin): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create(['role' => $role, 'password' => 'SecurePassword!12']);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

/** @return array{0: Order, 1: OrderItem, 2: ?FulfillmentJob} */
function resendItem(bool $withJob = false, array $jobAttributes = [], array $itemAttributes = []): array
{
    $order = Order::factory()->for(User::factory()->create())->create([
        'status' => OrderStatus::InProgress,
        'paid_at' => now()->subHour(),
    ]);

    $item = OrderItem::factory()->for($order)->create([
        'service_type' => ServiceType::Coins,
        'status' => OrderItemStatus::Received,
        ...$itemAttributes,
    ]);

    $job = null;

    if ($withJob || $jobAttributes !== []) {
        $job = FulfillmentJob::factory()->create([
            'order_item_id' => $item->id,
            'status' => FulfillmentStatus::InProgress,
            'supplier' => Supplier::Fft,
            'supplier_order_id' => '574339',
            'delivery_phase' => DeliveryPhase::Coins,
            ...$jobAttributes,
        ]);
    }

    return [$order, $item, $job];
}

function outboxRow(Order $order, string $status, ?string $lastError = null): IntegrationEvent
{
    return IntegrationEvent::query()->create([
        'event_id' => (string) str()->ulid(),
        'event_type' => 'order.paid',
        'aggregate_type' => 'order',
        'aggregate_id' => (string) $order->public_id,
        'schema_version' => 2,
        'payload' => ['order_number' => $order->order_number],
        'status' => $status,
        'idempotency_key' => 'order-paid:'.$order->id,
        'attempts' => 7,
        'last_error' => $lastError,
        'processed_at' => $status === 'processed' ? now()->subMinutes(30) : null,
    ]);
}

function resend(User $actor, OrderItem $item, array $payload = [])
{
    return test()->actingAs($actor)->postJson(
        route('admin.fulfillment.resend', ['item' => $item->public_id]),
        ['action' => 'send', 'reason_code' => 'callback_lost', ...$payload],
    );
}

beforeEach(function (): void {
    RateLimiter::clear('staff-fulfillment-action-user:1');
    Cache::flush();
});

it('re-opens a processed outbox row and says queued, not placed', function (): void {
    [$order, $item] = resendItem();
    $event = outboxRow($order, 'processed');

    $response = resend(resendActor(), $item);

    $response->assertOk()->assertJsonPath('data.outcome', 'queued');

    $event->refresh();

    // The widening this action performs: the console command guards on
    // `failed`, and an unplaced alarm's own case is a row n8n acknowledged, so
    // it reads `processed`. Re-delivery is safe because
    // `ComposePlacementRequest` re-reads AwaitingPlacement at send time.
    expect($event->status)->toBe('pending')
        ->and($event->attempts)->toBe(0)
        ->and($event->last_error)->toBeNull()
        ->and($event->processed_at)->toBeNull();
});

it('re-opens a failed outbox row too', function (): void {
    [$order, $item] = resendItem();
    $event = outboxRow($order, 'failed', 'max_attempts_exceeded');

    resend(resendActor(), $item)->assertOk()->assertJsonPath('data.outcome', 'queued');

    expect($event->refresh()->status)->toBe('pending');
});

it('refuses to touch a row the publisher is mid-flight on', function (): void {
    [$order, $item] = resendItem();
    $event = outboxRow($order, 'processing');

    // 409, and the row is untouched: re-opening a claimed row would hand one
    // event to two senders.
    resend(resendActor(), $item)
        ->assertStatus(409)
        ->assertJsonPath('data.outcome', 'in_flight');

    expect($event->refresh()->status)->toBe('processing');
});

it('writes the placement request when the order never had one', function (): void {
    [$order, $item] = resendItem();

    resend(resendActor(), $item)->assertOk()->assertJsonPath('data.outcome', 'queued');

    $event = IntegrationEvent::query()
        ->where('event_type', 'order.paid')
        ->where('aggregate_id', (string) $order->public_id)
        ->sole();

    expect($event->status)->toBe('pending')
        ->and($event->idempotency_key)->toBe('order-paid:'.$order->id);
});

it('never sends an item that already sits at a supplier', function (): void {
    [$order, $item] = resendItem(withJob: true);
    $event = outboxRow($order, 'processed');

    // The control does not offer this, and this is the gate behind the
    // control: a second placement spends the money twice.
    resend(resendActor(), $item)
        ->assertStatus(409)
        ->assertJsonPath('data.outcome', 'not_actionable');

    expect($event->refresh()->status)->toBe('processed');
});

it('refuses resume when the job does not allow it', function (): void {
    [, $item] = resendItem(withJob: true, jobAttributes: ['allowed_actions' => []]);

    resend(resendActor(), $item, ['action' => 'resume'])
        ->assertStatus(409)
        ->assertJsonPath('data.outcome', 'not_actionable');
});

it('refuses a second press while the first is in flight', function (): void {
    [$order, $item] = resendItem();
    outboxRow($order, 'processed');

    // The lock a send takes, held by something else. Keyed on the ORDER,
    // because the row a send re-opens is the order's single `order.paid`
    // outbox row - two items of one order pressed together are one write, and
    // a per-item lock would let both through. Refused rather than queued
    // (AGENTS.md Failures rule 5): a press that waited its turn and then sent
    // again is the double placement.
    $lock = Cache::lock("fulfillment-resend:order:{$order->id}", 75);
    expect($lock->get())->toBeTrue();

    resend(resendActor(), $item)
        ->assertStatus(409)
        ->assertJsonPath('data.outcome', 'busy');

    $lock->release();
});

it('keeps Staff out of the action entirely', function (): void {
    [, $item] = resendItem();

    resend(resendActor(UserRole::Staff), $item)->assertStatus(403);
});

it('keeps a customer out of the action', function (): void {
    [, $item] = resendItem();

    test()->actingAs(User::factory()->create(['role' => UserRole::Customer]))
        ->postJson(route('admin.fulfillment.resend', ['item' => $item->public_id]), [
            'action' => 'send',
            'reason_code' => 'callback_lost',
        ])
        ->assertStatus(403);
});

it('refuses a reason code that is not on the allowlist', function (): void {
    [, $item] = resendItem();

    resend(resendActor(), $item, ['reason_code' => 'because I felt like it'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason_code');
});

it('refuses an unknown action', function (): void {
    [, $item] = resendItem();

    resend(resendActor(), $item, ['action' => 'place_again'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('action');
});

it('caps a jammed button at two presses per item per minute', function (): void {
    [$order, $item] = resendItem();
    outboxRow($order, 'processed');
    $actor = resendActor();

    resend($actor, $item)->assertOk();
    // The second is a no-op outcome, not a second placement - the row is
    // already pending - but it still spends the budget.
    resend($actor, $item);

    resend($actor, $item)->assertStatus(429);
});

it('lets the same operator work a different item after that cap', function (): void {
    $actor = resendActor();
    [$firstOrder, $first] = resendItem();
    outboxRow($firstOrder, 'processed');
    [$secondOrder, $second] = resendItem();
    outboxRow($secondOrder, 'processed');

    resend($actor, $first)->assertOk();
    resend($actor, $first);
    resend($actor, $first)->assertStatus(429);

    // The per-item bucket is exhausted; the per-operator one is not.
    resend($actor, $second)->assertOk();
});

it('writes a reservation row and a truthful result row', function (): void {
    [$order, $item] = resendItem();
    outboxRow($order, 'processed');
    $actor = resendActor();

    resend($actor, $item)->assertOk();

    $rows = StaffAuditLog::query()
        ->whereIn('action', [
            'fulfillment.resend_requested',
            'fulfillment.resend_dispatched',
            'fulfillment.resend_refused',
        ])
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->action)->toBe('fulfillment.resend_requested')
        ->and($rows[1]->action)->toBe('fulfillment.resend_dispatched')
        ->and($rows[1]->actor_user_id)->toBe($actor->id)
        ->and($rows[1]->metadata['order_number'])->toBe($order->order_number)
        ->and($rows[1]->metadata['order_item_public_id'])->toBe((string) $item->public_id)
        ->and($rows[1]->metadata['action'])->toBe('send')
        ->and($rows[1]->metadata['reason_code'])->toBe('callback_lost')
        ->and($rows[1]->metadata['outcome'])->toBe('queued')
        // Where the row was, so the `processed -> pending` widening is visible
        // afterwards rather than erased by it.
        ->and($rows[1]->metadata['previous_event_status'])->toBe('processed');
});

it('records a refusal as a refusal', function (): void {
    [$order, $item] = resendItem(withJob: true);
    outboxRow($order, 'processed');

    resend(resendActor(), $item)->assertStatus(409);

    $result = StaffAuditLog::query()
        ->whereIn('action', ['fulfillment.resend_dispatched', 'fulfillment.resend_refused'])
        ->sole();

    expect($result->action)->toBe('fulfillment.resend_refused')
        ->and($result->metadata['outcome'])->toBe('not_actionable');
});

it('keeps every secret out of the audit metadata', function (): void {
    [$order, $item] = resendItem();
    outboxRow($order, 'processed');

    resend(resendActor(), $item)->assertOk();

    foreach (StaffAuditLog::query()->get() as $row) {
        $encoded = json_encode($row->metadata);

        foreach (['password', 'credential', 'secret', 'token', 'encrypted'] as $forbidden) {
            expect($encoded)->not->toContain($forbidden);
        }
    }
});

it('refuses a field nobody meant to send', function (): void {
    [, $item] = resendItem();

    resend(resendActor(), $item, ['supplier_order_id' => '574339'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['unexpected_fields']);
});

it('names the supplier actually holding the item in the audit trail', function (): void {
    [, $item, $job] = resendItem(jobAttributes: [
        // The mirror still says UTT funded the coins; the challenge is at FFT,
        // and only the placement row knows it.
        'supplier' => Supplier::Utt,
        'supplier_order_id' => '881204',
        'delivery_phase' => DeliveryPhase::Challenge,
    ]);

    FulfillmentPlacement::query()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => '574402',
        'supplier_challenge_ids' => ['0f8fad5b-d9cb-469f-a165-70867728950e'],
        'idempotency_key' => 'fulfillment-placement:'.$item->public_id.':challenge',
        'placed_at' => now()->subMinutes(30),
    ]);

    $job->forceFill(['allowed_actions' => ['resume']])->save();

    resend(resendActor(), $item, ['action' => 'resume']);

    $requested = StaffAuditLog::query()
        ->where('action', 'fulfillment.resend_requested')
        ->latest('id')
        ->firstOrFail();

    expect($requested->metadata['supplier'] ?? null)->toBe('fft');
});

it('writes the requested row even for a press the row refuses', function (): void {
    [, $item] = resendItem(itemAttributes: ['status' => OrderItemStatus::Cancelled]);

    resend(resendActor(), $item, ['action' => 'send'])->assertStatus(409);

    // The pair is the promise: a refusal with no request beside it reads, in
    // the log, like the store refused something nobody asked for.
    expect(StaffAuditLog::query()->where('action', 'fulfillment.resend_requested')->count())->toBe(1)
        ->and(StaffAuditLog::query()->where('action', 'fulfillment.resend_refused')->count())->toBe(1);
});
