<?php

use App\Enums\AdminPermission;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentAlarmKind;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderHoldReason;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Enums\UserRole;
use App\Models\FulfillmentAlarm;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

function fulfillmentActor(UserRole $role = UserRole::Admin): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create(['role' => $role, 'password' => 'SecurePassword!12']);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

/**
 * A paid order carrying one automated item, with or without a job.
 *
 * @return array{0: Order, 1: OrderItem, 2: FulfillmentJob|null}
 */
function fulfillmentItem(
    array $orderAttributes = [],
    array $itemAttributes = [],
    ?array $jobAttributes = null,
): array {
    $order = Order::factory()->for(User::factory()->create())->create([
        'status' => OrderStatus::InProgress,
        'paid_at' => now()->subHours(2),
        ...$orderAttributes,
    ]);

    $item = OrderItem::factory()->for($order)->create([
        'service_type' => ServiceType::Coins,
        'status' => OrderItemStatus::Received,
        ...$itemAttributes,
    ]);

    if ($jobAttributes === null) {
        return [$order, $item, null];
    }

    $job = FulfillmentJob::factory()->create([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => '574339',
        'delivery_phase' => DeliveryPhase::Coins,
        'next_poll_at' => now()->addMinute(),
        'actual_cost_halalah' => 9_625,
        ...$jobAttributes,
    ]);

    FulfillmentPlacement::query()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => $job->delivery_phase ?? DeliveryPhase::Coins,
        'supplier' => $job->supplier ?? Supplier::Fft,
        'supplier_order_id' => (string) $job->supplier_order_id,
        'idempotency_key' => 'fulfillment-placement:'.$item->public_id.':'.($job->delivery_phase?->value ?? 'coins'),
        'placed_at' => now()->subHours(2),
    ]);

    return [$order, $item, $job];
}

function fulfillmentPage(User $actor, array $query = [])
{
    return test()->actingAs($actor)->get(route('admin.fulfillment', $query));
}

/**
 * The Inertia props the page rendered.
 *
 * Read off the response rather than through `assertInertia`, because these
 * assertions are about array SHAPE - notably that a Staff payload has no
 * `cost` key at all - and an absent key is easier to state honestly against a
 * plain array than through a fluent matcher.
 */
function fulfillmentProps($response, string $key): mixed
{
    /** @var array{props: array<string, mixed>} $page */
    $page = $response->viewData('page');

    return $page['props'][$key];
}

it('lists a paid item that has no fulfillment job at all', function (): void {
    [, $item] = fulfillmentItem();

    FulfillmentAlarm::query()->create([
        'order_item_id' => $item->id,
        'kind' => FulfillmentAlarmKind::Unplaced,
        'raised_at' => now()->subMinutes(20),
        'context' => ['order_number' => 'AUT-1042', 'service' => 'coins'],
    ]);

    $response = fulfillmentPage(fulfillmentActor());

    $response->assertOk();

    $rows = fulfillmentProps($response, 'items');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe((string) $item->public_id)
        // The row the slice exists for: no job, so no supplier, no reading and
        // no cost - and the alarm is what says why.
        ->and($rows[0]['job'])->toBeNull()
        ->and($rows[0]['placement'])->toBeNull()
        ->and($rows[0]['alarms'])->toHaveCount(1)
        ->and($rows[0]['alarms'][0]['kind'])->toBe('unplaced')
        ->and($rows[0]['actions'])->toBe(['send']);
});

it('reads the supplier from the placement rather than the job mirror', function (): void {
    [, $item, $job] = fulfillmentItem(jobAttributes: [
        // The job mirror still advertises the first (coins) placement, which
        // is exactly the trap: the item has moved on to its challenge phase at
        // a different reference.
        'supplier' => Supplier::Fft,
        'supplier_order_id' => '574339',
        'delivery_phase' => DeliveryPhase::Challenge,
    ]);

    FulfillmentPlacement::query()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => '574388',
        'supplier_challenge_ids' => ['0f8fad5b-d9cb-469f-a165-70867728950e'],
        'idempotency_key' => 'fulfillment-placement:'.$item->public_id.':challenge',
        'placed_at' => now()->subMinutes(22),
    ]);

    $rows = fulfillmentProps(fulfillmentPage(fulfillmentActor()), 'items');

    expect($rows[0]['placement']['reference'])->toBe('574388')
        ->and($rows[0]['placement']['phase'])->toBe('challenge')
        ->and($rows[0]['placement']['challengeCount'])->toBe(1);
});

it('sorts by time since paid, ascending, by default', function (): void {
    [, $recent] = fulfillmentItem(orderAttributes: ['paid_at' => now()->subMinutes(10)]);
    [, $oldest] = fulfillmentItem(orderAttributes: ['paid_at' => now()->subHours(5)]);

    $rows = fulfillmentProps(fulfillmentPage(fulfillmentActor()), 'items');

    expect(array_column($rows, 'id'))
        ->toBe([(string) $oldest->public_id, (string) $recent->public_id]);
});

it('leaves finished and unpaid work out of the queue', function (): void {
    fulfillmentItem(orderAttributes: ['paid_at' => null, 'status' => OrderStatus::PendingPayment]);
    fulfillmentItem(itemAttributes: ['status' => OrderItemStatus::Completed]);
    fulfillmentItem(orderAttributes: ['status' => OrderStatus::Refunded]);
    // A booster service: no supplier to owe it.
    fulfillmentItem(itemAttributes: ['service_type' => ServiceType::Rivals]);
    [, $live] = fulfillmentItem();

    $rows = fulfillmentProps(fulfillmentPage(fulfillmentActor()), 'items');

    expect(array_column($rows, 'id'))->toBe([(string) $live->public_id]);
});

it('filters on every open alarm kind, including stalled', function (): void {
    $kinds = [];

    foreach (FulfillmentAlarmKind::cases() as $kind) {
        [, $item] = fulfillmentItem(jobAttributes: []);
        FulfillmentAlarm::query()->create([
            'order_item_id' => $item->id,
            'kind' => $kind,
            'raised_at' => now()->subMinutes(20),
            'context' => ['order_number' => 'AUT-1000', 'service' => 'coins'],
        ]);
        $kinds[$kind->value] = (string) $item->public_id;
    }

    expect(FulfillmentAlarmKind::cases())->toHaveCount(3);

    foreach ($kinds as $kind => $publicId) {
        $rows = fulfillmentProps(fulfillmentPage(fulfillmentActor(), ['alarm' => $kind]), 'items');

        expect(array_column($rows, 'id'))->toBe([$publicId]);
    }

    $any = fulfillmentProps(fulfillmentPage(fulfillmentActor(), ['alarm' => 'any']), 'items');
    expect($any)->toHaveCount(3);

    $none = fulfillmentProps(fulfillmentPage(fulfillmentActor(), ['alarm' => 'none']), 'items');
    expect($none)->toHaveCount(0);
});

it('carries a stall context the way the alarm mail reads it', function (): void {
    [, $item] = fulfillmentItem(jobAttributes: ['observed_state' => 'interrupted']);

    FulfillmentAlarm::query()->create([
        'order_item_id' => $item->id,
        'kind' => FulfillmentAlarmKind::Stalled,
        'raised_at' => now()->subMinutes(30),
        'context' => [
            'order_number' => 'AUT-1033',
            'order_item_public_id' => (string) $item->public_id,
            'service' => 'coins',
            'supplier' => 'fft',
            'phase' => 'coins',
            'band' => 'background',
            'observed_state' => 'interrupted',
            'circuit_open' => true,
            'quiet_minutes' => 41,
        ],
    ]);

    $rows = fulfillmentProps(fulfillmentPage(fulfillmentActor()), 'items');
    $alarm = $rows[0]['alarms'][0];

    expect($alarm['kind'])->toBe('stalled')
        ->and($alarm['quietMinutes'])->toBe(41)
        ->and($alarm['phase'])->toBe('coins')
        // The flag that decides whether an operator chases this at all: a
        // supplier inside its cooldown is one we are not asking.
        ->and($alarm['circuitOpen'])->toBeTrue();
});

it('reads the unplaced reason from PlacementBlockers rather than inventing one', function (): void {
    [$order, $item] = fulfillmentItem();

    DB::table('integration_events')->insert([
        'public_id' => (string) str()->ulid(),
        'event_id' => (string) str()->ulid(),
        'event_type' => 'order.paid',
        'aggregate_type' => 'order',
        'aggregate_id' => (string) $order->public_id,
        'schema_version' => 2,
        'payload' => json_encode(['order_number' => $order->order_number]),
        'status' => 'pending',
        'idempotency_key' => 'order-paid:'.$order->id,
        'attempts' => 3,
        'last_error' => 'budget_unavailable',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rows = fulfillmentProps(fulfillmentPage(fulfillmentActor()), 'items');

    expect($rows[0]['blocker']['reason'])->toBe('budget_unavailable')
        // `budget_unavailable` is not in CLEARS_ITSELF, so waiting will not fix it.
        ->and($rows[0]['blocker']['blocks'])->toBeTrue()
        ->and($rows[0]['id'])->toBe((string) $item->public_id);
});

it('offers resume and retry only when the job allows them', function (): void {
    [, , $job] = fulfillmentItem(jobAttributes: [
        'allowed_actions' => ['resume', 'edit_credentials'],
        'hold_reason' => OrderHoldReason::EaServers,
    ]);

    $rows = fulfillmentProps(fulfillmentPage(fulfillmentActor()), 'items');

    // edit_credentials is the customer's own action and never ours.
    expect($rows[0]['actions'])->toBe(['resume'])
        ->and($job->fresh()->allowedActions())->toHaveCount(2);
});

it('never offers send for an item that already has a placement', function (): void {
    fulfillmentItem(jobAttributes: ['allowed_actions' => []]);

    $rows = fulfillmentProps(fulfillmentPage(fulfillmentActor()), 'items');

    expect($rows[0]['actions'])->toBe([]);
});

it('omits the failed status from the filter allowlist', function (): void {
    $statuses = array_column(
        fulfillmentProps(fulfillmentPage(fulfillmentActor()), 'filterOptions')['statuses'],
        'value',
    );

    expect($statuses)->not->toContain(FulfillmentStatus::Failed->value);

    fulfillmentPage(fulfillmentActor(), ['status' => 'failed'])->assertStatus(302);
});

it('refuses an unknown query parameter', function (): void {
    fulfillmentPage(fulfillmentActor(), ['sort_by' => 'cost'])->assertStatus(302);
});

describe('the cost is Admin-only', function (): void {
    it('gives an Admin the cost and the action column', function (): void {
        fulfillmentItem(jobAttributes: []);

        $response = fulfillmentPage(fulfillmentActor());

        expect(fulfillmentProps($response, 'canSeeCost'))->toBeTrue()
            ->and(fulfillmentProps($response, 'canAct'))->toBeTrue()
            ->and(fulfillmentProps($response, 'items')[0])->toHaveKey('cost')
            ->and(fulfillmentProps($response, 'items')[0]['cost']['amountMinor'])->toBe('9625');
    });

    it('never puts the cost key in a Staff payload', function (): void {
        fulfillmentItem(jobAttributes: []);

        $staff = fulfillmentActor(UserRole::Staff);
        $response = fulfillmentPage($staff);

        $response->assertOk();

        expect($staff->can(AdminPermission::FulfillmentView->value))->toBeTrue()
            ->and($staff->can(AdminPermission::FulfillmentViewCost->value))->toBeFalse()
            ->and($staff->can(AdminPermission::FulfillmentAct->value))->toBeFalse()
            ->and(fulfillmentProps($response, 'canSeeCost'))->toBeFalse()
            ->and(fulfillmentProps($response, 'canAct'))->toBeFalse()
            // Absent, not null: a Staff payload read in a network tab carries
            // no trace of what a shipment cost us.
            ->and(fulfillmentProps($response, 'items')[0])->not->toHaveKey('cost');
    });

    it('422s a Staff request that names the cost sort', function (): void {
        fulfillmentItem(jobAttributes: []);

        // A silent fallback would be an oracle: it would tell the caller the
        // column exists and only the ordering was dropped.
        test()->actingAs(fulfillmentActor(UserRole::Staff))
            ->getJson(route('admin.fulfillment', ['sort' => 'actual_cost']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    });

    it('lets an Admin sort by cost', function (): void {
        test()->actingAs(fulfillmentActor())
            ->get(route('admin.fulfillment', ['sort' => 'actual_cost', 'direction' => 'desc']))
            ->assertOk();
    });

    it('never offers Staff the action column', function (): void {
        fulfillmentItem();

        $response = fulfillmentPage(fulfillmentActor(UserRole::Staff));

        expect(fulfillmentProps($response, 'items')[0]['actions'])->toBe([]);
    });
});

it('keeps a customer out of the screen entirely', function (): void {
    test()->actingAs(User::factory()->create(['role' => UserRole::Customer]))
        ->get(route('admin.fulfillment'))
        ->assertStatus(403);
});

it('puts Fulfillment in the navigation for both Admin and Staff', function (): void {
    foreach ([UserRole::Admin, UserRole::Staff] as $role) {
        $navigation = fulfillmentProps(fulfillmentPage(fulfillmentActor($role)), 'adminNavigation');

        expect(array_column($navigation, 'key'))->toContain('fulfillment');
    }
});
