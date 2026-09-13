<?php

use App\Account\Queries\ReadTrackedOrder;
use App\Actions\Orders\IssueOrderTrackingLink;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\SecretAccessLog;
use App\Models\User;
use App\Support\PublicHandle\TrackingLinkHandle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

function trackedActionOrder(?User $user = null, OrderStatus $status = OrderStatus::InProgress): Order
{
    $factory = Order::factory();

    if ($user instanceof User) {
        $factory = $factory->for($user);
    }

    return $factory->create([
        'order_number' => 'UT-'.fake()->unique()->numerify('########'),
        'status' => $status,
        'placed_at' => now(),
    ]);
}

function trackedActionItem(
    Order $order,
    ServiceType $serviceType = ServiceType::Coins,
    OrderItemStatus $status = OrderItemStatus::InProgress,
): OrderItem {
    return OrderItem::factory()->for($order)->create([
        'name_ar' => 'خدمة كوينز',
        'name_en' => 'Coins Service',
        'service_type' => $serviceType,
        'platform' => Platform::PlayStation,
        'status' => $status,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function trackedActionJob(OrderItem $item, array $attributes = []): FulfillmentJob
{
    return FulfillmentJob::factory()->create([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'fft-action-'.fake()->unique()->numerify('#####'),
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 */
function trackedActionSecret(OrderItem $item, array $payload): OrderItemSecret
{
    $secret = new OrderItemSecret([
        'order_item_id' => $item->id,
        'version' => 1,
        'masked_summary' => ['has_ea_password' => true, 'backup_code_count' => 3],
        'deleted_at' => null,
    ]);
    $secret->encrypted_payload = $payload;
    $secret->save();

    return $secret;
}

function trackedActionToken(Order $order): string
{
    return (string) basename((string) parse_url(
        app(IssueOrderTrackingLink::class)->execute($order),
        PHP_URL_PATH,
    ));
}

/**
 * A signed-in owner and an item whose job offers the given actions, with a
 * secret already on file - the account-route twin of the link helpers above.
 *
 * @param  list<SupplierAction>  $actions
 * @return array{0: User, 1: Order, 2: OrderItem, 3: FulfillmentJob}
 */
function trackedActionActionableItem(array $actions): array
{
    $owner = User::factory()->create();
    $order = trackedActionOrder($owner);
    $item = trackedActionItem($order);
    $job = trackedActionJob($item, [
        'delivery_phase' => DeliveryPhase::Coins,
        'allowed_actions' => array_map(fn (SupplierAction $a): string => $a->value, $actions),
    ]);

    trackedActionSecret($item, [
        'ea_email' => 'original@example.test',
        'ea_password' => 'original-password',
        'backup_codes' => ['12345678', '23456789', '34567890'],
    ]);

    return [$owner, $order, $item, $job];
}

beforeEach(function (): void {
    config()->set('services.suppliers.fft.base_url', 'https://fft.example.test');
    config()->set('services.suppliers.fft.api_user', 'store-fft');
    config()->set('services.suppliers.fft.api_key', 'fft-key');
    Http::preventStrayRequests();
});

test('an item belonging to a different order 404s and runs no action', function (): void {
    $orderA = trackedActionOrder();
    $itemA = trackedActionItem($orderA);
    trackedActionJob($itemA, ['allowed_actions' => ['edit_credentials']]);

    $orderB = trackedActionOrder();
    $itemB = trackedActionItem($orderB);
    trackedActionJob($itemB, ['allowed_actions' => ['edit_credentials']]);

    $token = trackedActionToken($orderA);

    Http::fake();

    $this->postJson("/orders/track/{$token}/items/{$itemB->public_id}/actions/edit-credentials", [
        'ea_email' => 'new@example.test',
        'ea_password' => 'New password',
        'backup_codes' => ['444444', '555555', '666666'],
    ])->assertNotFound();

    Http::assertNothingSent();
    expect(OrderItemSecret::query()->where('order_item_id', $itemB->id)->exists())->toBeFalse();
});

test('a valid token acting on its own item succeeds and answers the outcome vocabulary', function (): void {
    $order = trackedActionOrder();
    $item = trackedActionItem($order, ServiceType::Coins);
    trackedActionJob($item, ['allowed_actions' => ['resume']]);
    $token = trackedActionToken($order);

    Http::fake(['https://fft.example.test/*' => Http::response(['outcome' => 'success'])]);

    $this->postJson("/orders/track/{$token}/items/{$item->public_id}/actions/resume", [])
        ->assertOk()
        ->assertJsonStructure(['tracking', 'status'])
        ->assertJsonPath('status', 'accepted');

    Http::assertSentCount(1);
});

test('an unknown token 404s', function (): void {
    $this->postJson('/orders/track/'.Str::random(48).'/items/'.Str::ulid().'/actions/resume', [])
        ->assertNotFound();
});

test('a revoked token 404s indistinguishably from an unknown token', function (): void {
    $order = trackedActionOrder();
    trackedActionItem($order);
    $action = app(IssueOrderTrackingLink::class);
    $token = trackedActionToken($order);

    $action->revoke($order);

    $revoked = $this->postJson("/orders/track/{$token}/items/".Str::ulid().'/actions/resume', []);
    $unknown = $this->postJson('/orders/track/'.Str::random(48).'/items/'.Str::ulid().'/actions/resume', []);

    $revoked->assertNotFound();
    $unknown->assertNotFound();

    expect($revoked->getStatusCode())->toBe($unknown->getStatusCode());
});

test('a malformed token never reaches the resolver', function (string $token): void {
    $this->postJson("/orders/track/{$token}/items/".Str::ulid().'/actions/resume', [])
        ->assertNotFound();
})->with([
    'too short' => [str_repeat('A', 47)],
    'too long' => [str_repeat('A', 49)],
    'with a symbol' => [str_repeat('A', 47).'!'],
]);

test('a terminal order refuses every action with 403', function (array $case): void {
    [$path, $body] = $case;

    $order = trackedActionOrder(null, OrderStatus::Completed);
    $item = trackedActionItem($order, ServiceType::Coins, OrderItemStatus::Completed);
    trackedActionJob($item, ['allowed_actions' => ['edit_credentials', 'resume', 'retry_challenge']]);
    $token = trackedActionToken($order);

    Http::fake();

    $this->postJson("/orders/track/{$token}/items/{$item->public_id}{$path}", $body)
        ->assertForbidden();

    Http::assertNothingSent();
})->with([
    'edit credentials' => [['/actions/edit-credentials', ['ea_email' => 'x@example.test', 'ea_password' => 'pw', 'backup_codes' => ['123456', '234567', '345678']]]],
    'resume' => [['/actions/resume', []]],
    'retry challenge' => [['/actions/retry-challenge', ['target' => 0]]],
]);

test('an action absent from allowedActions() is refused with 403', function (array $case): void {
    [$path, $body] = $case;

    $order = trackedActionOrder();
    $item = trackedActionItem($order, ServiceType::Coins);
    trackedActionJob($item, ['allowed_actions' => []]);
    $token = trackedActionToken($order);

    Http::fake();

    $this->postJson("/orders/track/{$token}/items/{$item->public_id}{$path}", $body)
        ->assertForbidden();

    Http::assertNothingSent();
})->with([
    'edit credentials' => [['/actions/edit-credentials', ['ea_email' => 'x@example.test', 'ea_password' => 'pw', 'backup_codes' => ['123456', '234567', '345678']]]],
    'resume' => [['/actions/resume', []]],
    'retry challenge' => [['/actions/retry-challenge', ['target' => 0]]],
]);

test('a validation failure answers JSON with 422 and flashes nothing', function (): void {
    $order = trackedActionOrder();
    $item = trackedActionItem($order, ServiceType::Coins);
    trackedActionJob($item, ['allowed_actions' => ['edit_credentials']]);
    $token = trackedActionToken($order);

    Http::fake();

    $response = $this->postJson("/orders/track/{$token}/items/{$item->public_id}/actions/edit-credentials", [
        'ea_email' => 'new@example.test',
        'ea_password' => 'New password',
        'backup_codes' => ['1234567', '2345678', '3456789'],
    ]);

    $response->assertUnprocessable();
    expect($response->headers->get('content-type'))->toContain('application/json');
    $response->assertSessionMissing('ea_password');
    $response->assertSessionMissing('backup_codes');

    Http::assertNothingSent();
});

test('a password keeps the spaces the customer typed over the link', function (): void {
    $order = trackedActionOrder();
    $item = trackedActionItem($order, ServiceType::Coins);
    trackedActionJob($item, ['allowed_actions' => ['edit_credentials']]);
    $token = trackedActionToken($order);

    Http::fake(['https://fft.example.test/*' => Http::response(['outcome' => 'success'])]);

    $this->postJson("/orders/track/{$token}/items/{$item->public_id}/actions/edit-credentials", [
        'ea_email' => 'spaces@example.test',
        'ea_password' => '  keep me  ',
        'backup_codes' => ['111111', '222222', '333333'],
    ])->assertOk();

    $secret = OrderItemSecret::query()->where('order_item_id', $item->id)->firstOrFail();
    expect($secret->encrypted_payload['ea_password'])->toBe('  keep me  ');
});

test('a correction over the link records a null user and the link purpose', function (): void {
    $order = trackedActionOrder();
    $item = trackedActionItem($order, ServiceType::Coins);
    trackedActionJob($item, ['allowed_actions' => ['edit_credentials']]);
    trackedActionSecret($item, [
        'ea_email' => 'old@example.test',
        'ea_password' => 'old-password',
        'backup_codes' => ['11111111', '22222222', '33333333'],
    ]);
    $token = trackedActionToken($order);

    Http::fake(['https://fft.example.test/correctCredentialsAPI' => Http::response(['outcome' => 'success'])]);

    $this->postJson("/orders/track/{$token}/items/{$item->public_id}/actions/edit-credentials", [
        'ea_email' => 'new@example.test',
        'ea_password' => 'New password',
        'backup_codes' => ['444444', '555555', '666666'],
    ])->assertOk();

    $log = SecretAccessLog::query()->sole();
    expect($log->user_id)->toBeNull()
        ->and($log->purpose)->toBe('tracking_link_credential_correction');
});

test('the signed-in correction still records its user and the customer purpose', function (): void {
    [$owner, $order, $item] = trackedActionActionableItem([SupplierAction::EditCredentials]);

    Http::fake(['https://fft.example.test/correctCredentialsAPI' => Http::response(['outcome' => 'success'])]);

    $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/actions/edit-credentials", [
            'ea_email' => 'new@example.test',
            'ea_password' => 'New password',
            'backup_codes' => ['444444', '555555', '666666'],
        ])
        ->assertOk();

    $log = SecretAccessLog::query()->sole();
    expect($log->user_id)->toBe($owner->id)
        ->and($log->purpose)->toBe('customer_credential_correction');
});

test('a signed-in customer acting over the link is recorded by the link, not by their session', function (): void {
    // The case the purpose enum exists for. A customer is signed in and taps the
    // link in their WhatsApp message, so a user IS present - and deriving the
    // purpose from that presence logged a capability-token correction as an
    // ordinary account change. Both facts are recorded now: who did it, and how
    // they were let through.
    $owner = User::factory()->create();
    $order = trackedActionOrder($owner);
    $item = trackedActionItem($order, ServiceType::Coins);
    trackedActionJob($item, ['allowed_actions' => ['edit_credentials']]);
    trackedActionSecret($item, [
        'ea_email' => 'old@example.test',
        'ea_password' => 'old-password',
        'backup_codes' => ['11111111', '22222222', '33333333'],
    ]);
    $token = trackedActionToken($order);

    Http::fake(['https://fft.example.test/correctCredentialsAPI' => Http::response(['outcome' => 'success'])]);

    $this->actingAs($owner)
        ->postJson("/orders/track/{$token}/items/{$item->public_id}/actions/edit-credentials", [
            'ea_email' => 'new@example.test',
            'ea_password' => 'New password',
            'backup_codes' => ['444444', '555555', '666666'],
        ])
        ->assertOk();

    $log = SecretAccessLog::query()->sole();
    expect($log->user_id)->toBe($owner->id)
        ->and($log->purpose)->toBe('tracking_link_credential_correction');
});

test('a challenge retry with a position outside the placement challenge ids is refused with 403', function (): void {
    $order = trackedActionOrder();
    $item = trackedActionItem($order, ServiceType::Sbc);
    $job = trackedActionJob($item, ['allowed_actions' => ['retry_challenge']]);

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => ['aaaaaaaa-0000-0000-0000-000000000001'],
        'idempotency_key' => 'placement-action-'.Str::random(6),
        'placed_at' => now(),
    ]);

    $token = trackedActionToken($order);

    Http::fake();

    $this->postJson("/orders/track/{$token}/items/{$item->public_id}/actions/retry-challenge", ['target' => 3])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('all three action routes carry the tracking-link-action throttle', function (): void {
    expect(RateLimiter::limiter('order-tracking-link-action'))->not->toBeNull();

    foreach ([
        'store.orders.track.actions.edit-credentials',
        'store.orders.track.actions.resume',
        'store.orders.track.actions.retry-challenge',
    ] as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull()
            ->and($route->middleware())->toContain('throttle:order-tracking-link-action');
    }
});

test('the read payloads actionUrls carry the token for an actionable item', function (): void {
    $order = trackedActionOrder();
    $item = trackedActionItem($order, ServiceType::Coins);
    trackedActionJob($item, ['allowed_actions' => ['resume']]);
    $token = trackedActionToken($order);

    $resolved = TrackingLinkHandle::resolve($token);

    expect($resolved)->toBeInstanceOf(Order::class);

    $payload = app(ReadTrackedOrder::class)->execute($resolved, $token, 'ar');

    $first = $payload['items'][0];

    expect($first['actionUrls'])->toHaveKeys(['editCredentials', 'resume', 'retryChallenge'])
        ->and($first['actionUrls']['resume'])->toContain($token)
        ->and($first['tracking']['actions'])->toContain('resume');
});
