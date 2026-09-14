<?php

use App\Admin\Actions\CreateManualOrder;
use App\Admin\ManualOrder\ManualOrderDraft;
use App\Admin\ManualOrder\ManualOrderItemDraft;
use App\Admin\ManualOrder\ManualOrderPayment;
use App\Admin\ManualOrder\ManualOrderPlacement;
use App\Enums\AdminPermission;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Enums\UserRole;
use App\Loyalty\Actions\AccrueOrderCashback;
use App\Loyalty\Support\EligibleOrderSpend;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\StaffAuditLog;
use App\Models\User;
use App\Models\WalletEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

function manualItem(array $overrides = []): ManualOrderItemDraft
{
    return new ManualOrderItemDraft(
        $overrides['service'] ?? ServiceType::Coins,
        $overrides['platform'] ?? Platform::PlayStation,
        null,
        $overrides['sku'] ?? 'MANUAL-COINS',
        'كوينز',
        'Coins',
        $overrides['price'] ?? 30000,
        $overrides['configuration'] ?? ['service_type' => 'coins', 'platform' => 'playstation', 'coins_quantity' => 1_250_000],
        $overrides['credentials'] ?? null,
        $overrides['placement'] ?? null,
    );
}

function manualCustomer(): User
{
    return User::factory()->create(['role' => UserRole::Customer]);
}

test('a bank transfer writes the order, the item and the payment that arrived', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);
    $customer = manualCustomer();

    $order = app(CreateManualOrder::class)->execute(
        $actor,
        $customer,
        'ar',
        new ManualOrderDraft(
            false,
            new ManualOrderPayment(30000, 'FT2609134471', CarbonImmutable::parse('2026-09-13')),
            [manualItem()],
        ),
        '203.0.113.9',
    );

    expect($order->channel)->toBe('manual')
        ->and($order->status)->toBe(OrderStatus::Received)
        ->and($order->total_halalah)->toBe(30000)
        ->and($order->subtotal_halalah)->toBe(30000)
        ->and($order->wallet_halalah)->toBe(0)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->user_id)->toBe($customer->id);

    $payment = $order->payments()->sole();

    expect($payment->provider)->toBe('bank_transfer')
        ->and($payment->status)->toBe(PaymentStatus::Paid)
        // Captured, not merely authorised: this is what EligibleOrderSpend sums,
        // so a transfer has to count towards loyalty like a card payment does.
        ->and($payment->captured_halalah)->toBe(30000)
        ->and($payment->provider_payment_id)->toBe('FT2609134471');

    expect($order->items()->sole()->total_halalah)->toBe(30000);
});

test('a gift is nothing more than a manual order with a zero total', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);

    $order = app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [manualItem(['price' => 30000])]),
    );

    // The item was priced 30000 in the draft and is recorded at zero, because a
    // gift's total is the only thing that marks it as one. A priced item with a
    // zero order total would be an order that disagrees with itself.
    expect($order->total_halalah)->toBe(0)
        ->and($order->subtotal_halalah)->toBe(0)
        ->and($order->payment_halalah)->toBe(0)
        ->and($order->items()->sole()->total_halalah)->toBe(0)
        ->and($order->payments()->count())->toBe(0)
        ->and($order->channel)->toBe('manual');
});

test('a gift earns no cashback, and needs no rule to say so', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);
    $customer = manualCustomer();

    $order = app(CreateManualOrder::class)->execute(
        $actor,
        $customer,
        'ar',
        new ManualOrderDraft(true, null, [manualItem()]),
    );

    $order->forceFill(['status' => OrderStatus::Completed, 'completed_at' => now()])->save();

    app(AccrueOrderCashback::class)->execute($order->fresh());

    // The owner's rule is "cashback on a transfer, none on a gift". This asserts
    // the arithmetic that satisfies it rather than a gift-specific branch: the
    // basis is the total minus the wallet amount, which is zero.
    expect(WalletEntry::query()->where('reference', 'cashback:'.$order->id)->count())->toBe(0);
});

test('a transfer keeps its place in loyalty spend', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);
    $customer = manualCustomer();

    $order = app(CreateManualOrder::class)->execute(
        $actor,
        $customer,
        'ar',
        new ManualOrderDraft(
            false,
            new ManualOrderPayment(30000, 'FT-1', CarbonImmutable::parse('2026-09-13')),
            [manualItem()],
        ),
    );

    // fullySettled is what gates cashback, and it sums captured payments against
    // the total. A manual order that failed this would look paid and reward
    // nothing, silently.
    expect(app(EligibleOrderSpend::class)->fullySettled($order->fresh()))->toBeTrue();
});

test('an item placed by hand gets a job that is ready to be read', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);

    $order = app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [
            manualItem(['placement' => new ManualOrderPlacement(Supplier::Fft, '84172', DeliveryPhase::Coins)]),
        ]),
    );

    $job = $order->items()->sole()->fulfillmentJob;

    expect($job)->not->toBeNull()
        ->and($job->supplier)->toBe(Supplier::Fft)
        ->and($job->supplier_order_id)->toBe('84172')
        ->and($job->delivery_phase)->toBe(DeliveryPhase::Coins)
        ->and($job->status)->toBe(FulfillmentStatus::InProgress)
        // Due now: the sweep has no observation to base a cadence on yet, so
        // making it wait would leave a placed item unread for a full cycle.
        ->and($job->next_poll_at)->not->toBeNull();
});

test('the same supplier reference cannot be attached to two orders', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);
    $placement = fn (): ManualOrderPlacement => new ManualOrderPlacement(Supplier::Fft, '84172', DeliveryPhase::Coins);

    app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [manualItem(['placement' => $placement()])]),
    );

    // The refusal comes from RecordSupplierPlacement, whose unique index lives
    // on `fulfillment_placements`: `2026_09_12_000003:34` dropped the one on the
    // job, because a job mirrors only its first placement. The first version of
    // this action wrote the job by hand and trusted that dropped index, which is
    // how this test found the bug.
    expect(fn () => app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [manualItem(['placement' => $placement()])]),
    ))->toThrow(RuntimeException::class, 'supplier_reference_conflict');

    expect(Order::query()->where('channel', 'manual')->count())->toBe(1);
});

test('an item may be stored with an email and no password', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);

    $order = app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [
            manualItem(['credentials' => ['ea_email' => 'faisal@example.com']]),
        ]),
    );

    $secret = $order->items()->sole()->secret;

    expect($secret)->not->toBeNull()
        // The summary has to say the password is absent, because the screens
        // that offer a credential fix read it to decide whether one is possible.
        ->and($secret->masked_summary)->toBe(['has_password' => false, 'backup_code_count' => 0])
        ->and($secret->encrypted_payload)->toBe(['ea_email' => 'faisal@example.com', 'backup_codes' => []]);
});

test('the configuration is filtered through the same allowlist checkout uses', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);

    $order = app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [
            manualItem(['configuration' => [
                'service_type' => 'coins',
                'platform' => 'playstation',
                'coins_quantity' => 1_250_000,
                // Not on the Coins allowlist, and must not survive.
                'internal_note' => 'whatever staff typed',
                'ea_password' => 'never',
            ]]),
        ]),
    );

    expect($order->items()->sole()->configuration)
        ->toBe(['service_type' => 'coins', 'platform' => 'playstation', 'coins_quantity' => 1_250_000]);
});

test('creating an order is recorded against the person who did it', function (): void {
    $actor = createStaffTestActor(UserRole::Staff);

    $order = app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [manualItem()]),
        '203.0.113.9',
    );

    $log = StaffAuditLog::query()->where('action', 'orders.manual_created')->sole();

    expect($log->actor_user_id)->toBe($actor->id)
        ->and($log->auditable_id)->toBe($order->id)
        ->and($log->metadata['is_gift'])->toBeTrue()
        ->and($log->metadata['order_number'])->toBe($order->order_number)
        // The audit metadata guard forbids credential-shaped keys; this asserts
        // the payload we actually send has none, rather than trusting it.
        ->and($log->metadata)->not->toHaveKey('credentials');
});

test('support staff may create a manual order, gifts included', function (): void {
    // Owner decision, 2026-09-13, against my recommendation of admin-only. The
    // control is the audit row above, so the permission is asserted here to
    // catch a future STAFF-list edit that silently revokes it.
    $staff = createStaffTestActor(UserRole::Staff);

    expect($staff->can(AdminPermission::OrdersCreate->value))->toBeTrue();

    $order = app(CreateManualOrder::class)->execute($staff, manualCustomer(), 'ar', new ManualOrderDraft(true, null, [manualItem()]));

    expect($order->exists)->toBeTrue();
});

test('a customer cannot create an order for anyone', function (): void {
    $customer = manualCustomer();

    expect(fn () => app(CreateManualOrder::class)->execute(
        $customer,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [manualItem()]),
    ))->toThrow(AuthorizationException::class);
});

test('an order cannot be created for a staff account', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);
    $subject = createStaffTestActor(UserRole::Staff);

    // A manual order lands in somebody's order history, loyalty spend and
    // cashback. A staff account has none of those.
    expect(fn () => app(CreateManualOrder::class)->execute(
        $actor,
        $subject,
        'ar',
        new ManualOrderDraft(true, null, [manualItem()]),
    ))->toThrow(AuthorizationException::class);
});

test('a payment that does not cover the items is refused', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);

    expect(fn () => app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(
            false,
            new ManualOrderPayment(1000, 'FT-SHORT', CarbonImmutable::parse('2026-09-13')),
            [manualItem(['price' => 30000])],
        ),
    ))->toThrow(RuntimeException::class);

    // Nothing half-written: the refusal happens before the transaction opens.
    expect(Order::query()->where('channel', 'manual')->count())->toBe(0);
});

test('a gift with a payment, and a transfer without one, are both refused', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);
    $payment = new ManualOrderPayment(30000, 'FT-X', CarbonImmutable::parse('2026-09-13'));

    expect(fn () => app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, $payment, [manualItem()]),
    ))->toThrow(RuntimeException::class);

    expect(fn () => app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(false, null, [manualItem()]),
    ))->toThrow(RuntimeException::class);
});

test('several items on one order each keep their own reference', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);

    $order = app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(
            false,
            new ManualOrderPayment(42000, 'FT-TWO', CarbonImmutable::parse('2026-09-13')),
            [
                manualItem(['price' => 30000, 'placement' => new ManualOrderPlacement(Supplier::Fft, '84172', DeliveryPhase::Coins)]),
                manualItem([
                    'service' => ServiceType::Sbc,
                    'sku' => 'MANUAL-SBC',
                    'price' => 12000,
                    'configuration' => ['service_type' => 'sbc', 'platform' => 'playstation', 'completion_count' => 3],
                    'placement' => new ManualOrderPlacement(Supplier::Fft, '84173', DeliveryPhase::Challenge, ['3f2a1c64-8d4e-4b71-9a2f-5c6d7e8f9a01', '3f2a1c64-8d4e-4b71-9a2f-5c6d7e8f9a02']),
                ]),
            ],
        ),
    );

    // One reference per item, never one per order (owner decision,
    // 2026-09-13): each item is its own order at the supplier.
    $references = $order->items()->with('fulfillmentJob')->get()
        ->map(fn ($item) => $item->fulfillmentJob?->supplier_order_id)
        ->all();

    expect($order->total_halalah)->toBe(42000)
        ->and($references)->toBe(['84172', '84173']);
});

test('a manual service takes no supplier reference', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);

    $order = app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [
            manualItem([
                'service' => ServiceType::Rivals,
                'sku' => 'MANUAL-RIVALS',
                'configuration' => [
                    'service_type' => 'rivals',
                    'platform' => 'playstation',
                    'mode' => 'wins',
                    'current_division' => 5,
                    'target_division' => 3,
                ],
            ]),
        ]),
    );

    // Rivals is delivered by a person, so there is nothing to poll and no job
    // to create. A job here would be one the sweep can never read.
    expect($order->items()->sole()->fulfillmentJob)->toBeNull();
});

test('a challenge placed by hand is refused without its challenge ids', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);

    // RecordSupplierPlacement's own rule, reached through this action: a
    // challenge job carrying no ids is untrackable the moment it lands, so the
    // whole order rolls back rather than being created half-tracked. The form
    // asks for them, and this is the guard behind the form.
    expect(fn () => app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [
            manualItem([
                'service' => ServiceType::Sbc,
                'sku' => 'MANUAL-SBC',
                'configuration' => ['service_type' => 'sbc', 'platform' => 'playstation', 'completion_count' => 3],
                'placement' => new ManualOrderPlacement(Supplier::Fft, '84180', DeliveryPhase::Challenge),
            ]),
        ]),
    ))->toThrow(RuntimeException::class, 'challenge_ids_required');

    expect(Order::query()->where('channel', 'manual')->count())->toBe(0);
});

test('UTT cannot be named for a challenge placed by hand', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);

    // UTT delivers coins only. The request form says so too, but the action is
    // the authority, and this asserts the authority rather than the form.
    expect(fn () => app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [
            manualItem([
                'service' => ServiceType::Sbc,
                'sku' => 'MANUAL-SBC',
                'configuration' => ['service_type' => 'sbc', 'platform' => 'playstation', 'completion_count' => 1],
                'placement' => new ManualOrderPlacement(Supplier::Utt, '84181', DeliveryPhase::Challenge, ['3f2a1c64-8d4e-4b71-9a2f-5c6d7e8f9a09']),
            ]),
        ]),
    ))->toThrow(RuntimeException::class, 'supplier_cannot_solve_challenges');
});

test('an automated item without a pasted reference is queued for n8n, and one with a reference is not', function (): void {
    $actor = createStaffTestActor(UserRole::Admin);

    $dispatched = app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [
            manualItem(['credentials' => ['ea_email' => 'fahad@example.test', 'ea_password' => 'safe password', 'backup_codes' => ['11111111', '22222222', '33333333']]]),
        ]),
    );

    // The reference means "already placed", so there is nothing for n8n to do.
    $placed = app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [
            manualItem(['placement' => new ManualOrderPlacement(Supplier::Fft, '84190', DeliveryPhase::Coins)]),
        ]),
    );

    // Nor for a booster service, which a person delivers.
    $booster = app(CreateManualOrder::class)->execute(
        $actor,
        manualCustomer(),
        'ar',
        new ManualOrderDraft(true, null, [
            manualItem([
                'service' => ServiceType::Rivals,
                'sku' => 'MANUAL-RIVALS',
                'configuration' => ['service_type' => 'rivals', 'platform' => 'playstation', 'mode' => 'wins', 'current_division' => 5, 'target_division' => 3],
            ]),
        ]),
    );

    $events = IntegrationEvent::query()->where('event_type', 'order.paid')->get();

    expect($events)->toHaveCount(1)
        ->and($events->sole()->aggregate_id)->toBe($dispatched->public_id)
        ->and($events->sole()->payload['channel'])->toBe('manual')
        ->and($events->sole()->idempotency_key)->toBe('order-paid:'.$dispatched->id)
        ->and($placed->id)->not->toBe($dispatched->id)
        ->and($booster->id)->not->toBe($dispatched->id);
});
