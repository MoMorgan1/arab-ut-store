<?php

use App\Account\Presenters\ItemTracking;
use App\Actions\Checkout\PlaceOrder;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function actionTestOrder(User $user, OrderStatus $status = OrderStatus::InProgress): Order
{
    return Order::factory()->for($user)->create([
        'order_number' => 'UT-'.fake()->unique()->numerify('########'),
        'status' => $status,
        'placed_at' => now(),
    ]);
}

function actionTestItem(
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
function actionTestJob(OrderItem $item, array $attributes = []): FulfillmentJob
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
function actionTestSecret(OrderItem $item, array $payload): OrderItemSecret
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

/**
 * An item whose job offers the given actions, with a secret already on file.
 *
 * @param  list<SupplierAction>  $actions
 * @return array{0: User, 1: Order, 2: OrderItem, 3: FulfillmentJob}
 */
function actionableCoinsItem(array $actions): array
{
    $owner = User::factory()->create();
    $order = actionTestOrder($owner);
    $item = actionTestItem($order);
    $job = actionTestJob($item, [
        'delivery_phase' => DeliveryPhase::Coins,
        'allowed_actions' => array_map(fn (SupplierAction $a): string => $a->value, $actions),
    ]);

    actionTestSecret($item, [
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

test('a credential correction writes our record before the supplier call and survives a supplier outage', function (): void {
    $owner = User::factory()->create();
    $order = actionTestOrder($owner);
    $item = actionTestItem($order, ServiceType::Coins);

    $job = actionTestJob($item, [
        'allowed_actions' => ['edit_credentials'],
        'observed_at' => CarbonImmutable::parse('2026-09-12 10:00:00', 'UTC'),
        'last_viewed_at' => null,
        'next_poll_at' => null,
    ]);

    actionTestSecret($item, [
        'ea_email' => 'old@example.test',
        'ea_password' => 'Old password',
        'backup_codes' => ['11111111', '22222222', '33333333'],
    ]);

    Http::fake([
        'https://fft.example.test/correctCredentialsAPI' => Http::response('Upstream down', 503),
    ]);

    $response = $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/actions/edit-credentials", [
            'ea_email' => 'new@example.test',
            'ea_password' => 'New password',
            'backup_codes' => ['444444', '555555', '666666'],
        ])
        ->assertOk();

    Http::assertSentCount(1);

    $secret = OrderItemSecret::query()->where('order_item_id', $item->id)->sole();
    expect($secret->encrypted_payload)->toBe([
        'ea_email' => 'new@example.test',
        'ea_password' => 'New password',
        'backup_codes' => ['444444', '555555', '666666'],
    ])->and($secret->version)->toBe(2)
        ->and($response->json('status'))->toBe('saved_not_sent');

    // Nothing was sent, so the job must not claim a sent version or time.
    expect($job->fresh()->credential_version_sent)->toBeNull()
        ->and($job->fresh()->credentials_sent_at)->toBeNull();
});

test('the masked summary carries no password or full backup code', function (): void {
    $owner = User::factory()->create();
    $order = actionTestOrder($owner);
    $item = actionTestItem($order, ServiceType::Coins);

    actionTestJob($item, ['allowed_actions' => ['edit_credentials']]);
    actionTestSecret($item, [
        'ea_email' => 'old@example.test',
        'ea_password' => 'Old password',
        'backup_codes' => ['11111111', '22222222', '33333333'],
    ]);

    Http::fake([
        'https://fft.example.test/correctCredentialsAPI' => Http::response(['outcome' => 'success']),
    ]);

    $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/actions/edit-credentials", [
            'ea_email' => 'new@example.test',
            'ea_password' => 'Secret password',
            'backup_codes' => ['444444', '555555', '666666'],
        ])
        ->assertOk()
        ->assertJsonPath('status', 'accepted');

    $secret = OrderItemSecret::query()->where('order_item_id', $item->id)->sole();
    expect($secret->masked_summary)->toBe([
        'has_ea_password' => true,
        'backup_code_count' => 3,
    ]);

    $encoded = json_encode($secret->masked_summary);
    expect($encoded)->not->toContain('Secret password')
        ->not->toContain('444444')
        ->not->toContain('555555')
        ->not->toContain('666666');
});

test('the correction form accepts six and eight digit codes and refuses seven', function (array $codes): void {
    $owner = User::factory()->create();
    $order = actionTestOrder($owner);
    $item = actionTestItem($order, ServiceType::Coins);

    actionTestJob($item, ['allowed_actions' => ['edit_credentials']]);

    Http::fake([
        'https://fft.example.test/correctCredentialsAPI' => Http::response(['outcome' => 'success']),
    ]);

    $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/actions/edit-credentials", [
            'ea_email' => 'new@example.test',
            'ea_password' => 'New password',
            'backup_codes' => $codes,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'accepted');
})->with([
    'six-digit' => [['123456', '234567', '345678']],
    'eight-digit' => [['12345678', '23456789', '34567890']],
]);

test('the correction form refuses a seven-digit backup code', function (): void {
    $owner = User::factory()->create();
    $order = actionTestOrder($owner);
    $item = actionTestItem($order, ServiceType::Coins);

    actionTestJob($item, ['allowed_actions' => ['edit_credentials']]);

    Http::fake();

    $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/actions/edit-credentials", [
            'ea_email' => 'new@example.test',
            'ea_password' => 'New password',
            'backup_codes' => ['1234567', '2345678', '3456789'],
        ])
        ->assertUnprocessable();

    Http::assertNothingSent();
});

test('checkout accepts six and eight digit backup codes', function (array $codes): void {
    $state = actionCheckoutCart($codes);

    $result = app(PlaceOrder::class)->execute($state['user'], 'ar', 'checkout-codes-'.Str::random(8));

    expect($result->order)->toBeInstanceOf(Order::class);
})->with([
    'six-digit' => [['123456', '234567', '345678']],
    'eight-digit' => [['12345678', '23456789', '34567890']],
]);

test('checkout refuses a seven-digit backup code', function (): void {
    $state = actionCheckoutCart(['1234567', '2345678', '3456789']);

    expect(fn () => app(PlaceOrder::class)->execute($state['user'], 'ar', 'checkout-seven-'.Str::random(8)))
        ->toThrow(CheckoutUnavailable::class, 'EA account details are required.');
});

test('an action absent from allowedActions() is refused with 403', function (array $case): void {
    [$path, $body] = $case;

    $owner = User::factory()->create();
    $order = actionTestOrder($owner);
    $item = actionTestItem($order, ServiceType::Coins);

    actionTestJob($item, ['allowed_actions' => []]);

    Http::fake();

    $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}{$path}", $body)
        ->assertForbidden();

    Http::assertNothingSent();
})->with([
    'edit credentials' => [['/actions/edit-credentials', ['ea_email' => 'x@example.test', 'ea_password' => 'pw', 'backup_codes' => ['123456', '234567', '345678']]]],
    'resume' => [['/actions/resume', []]],
    'retry challenge' => [['/actions/retry-challenge', ['target' => 0]]],
]);

test('a terminal order refuses every action with 403', function (array $case): void {
    [$path, $body] = $case;

    $owner = User::factory()->create();
    $order = actionTestOrder($owner, OrderStatus::Completed);
    $item = actionTestItem($order, ServiceType::Coins, OrderItemStatus::Completed);

    actionTestJob($item, ['allowed_actions' => ['edit_credentials', 'resume', 'retry_challenge']]);

    Http::fake();

    $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}{$path}", $body)
        ->assertForbidden();

    Http::assertNothingSent();
})->with([
    'edit credentials' => [['/actions/edit-credentials', ['ea_email' => 'x@example.test', 'ea_password' => 'pw', 'backup_codes' => ['123456', '234567', '345678']]]],
    'resume' => [['/actions/resume', []]],
    'retry challenge' => [['/actions/retry-challenge', ['target' => 0]]],
]);

test('another customer acting on this order gets 404, not 403', function (): void {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();

    $orderA = actionTestOrder($ownerA);
    $itemA = actionTestItem($orderA, ServiceType::Coins);
    actionTestJob($itemA, ['allowed_actions' => ['edit_credentials', 'resume']]);

    Http::fake();

    $this->actingAs($ownerB)
        ->postJson("/orders/{$orderA->order_number}/items/{$itemA->public_id}/actions/resume", [])
        ->assertNotFound();

    Http::assertNothingSent();
});

test('a challenge retry with a position outside the placement challenge ids is refused with 403', function (): void {
    $owner = User::factory()->create();
    $order = actionTestOrder($owner);
    $item = actionTestItem($order, ServiceType::Sbc);

    $job = actionTestJob($item, ['allowed_actions' => ['retry_challenge']]);

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => ['aaaaaaaa-0000-0000-0000-000000000001'],
        'idempotency_key' => 'placement-action-'.Str::random(6),
        'placed_at' => now(),
    ]);

    Http::fake();

    $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/actions/retry-challenge", ['target' => 3])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('an accepted correction stamps the job and records the sent version', function (): void {
    $owner = User::factory()->create();
    $order = actionTestOrder($owner);
    $item = actionTestItem($order, ServiceType::Coins);

    $job = actionTestJob($item, [
        'allowed_actions' => ['edit_credentials'],
        'observed_at' => CarbonImmutable::parse('2026-09-12 10:00:00', 'UTC'),
        'last_viewed_at' => null,
        'next_poll_at' => null,
    ]);

    actionTestSecret($item, [
        'ea_email' => 'old@example.test',
        'ea_password' => 'Old password',
        'backup_codes' => ['11111111', '22222222', '33333333'],
    ]);

    Http::fake([
        'https://fft.example.test/correctCredentialsAPI' => Http::response(['outcome' => 'success']),
    ]);

    $response = $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/actions/edit-credentials", [
            'ea_email' => 'new@example.test',
            'ea_password' => 'New password',
            'backup_codes' => ['123456', '234567', '345678'],
        ])
        ->assertOk()
        ->assertJsonPath('status', 'accepted');

    $fresh = $job->fresh();
    expect($fresh->next_poll_at)->not->toBeNull()
        ->and($fresh->last_viewed_at)->not->toBeNull()
        ->and($fresh->credential_version_sent)->toBe(2)
        ->and($fresh->credentials_sent_at)->not->toBeNull();

    expect($response->json('tracking.credentialsPending'))->toBeTrue();

    // A newer observation lands after the correction, clearing the pending state.
    $fresh->forceFill(['observed_at' => CarbonImmutable::now()->addMinute()])->save();
    $item->load('fulfillmentJob');
    expect(ItemTracking::for($item, 'en')['credentialsPending'])->toBeFalse();
});

/**
 * @param  list<string>  $codes
 * @return array{user: User, cart: Cart, item: CartItem, variant: ProductVariant}
 */
function actionCheckoutCart(array $codes): array
{
    static $phoneSequence = 0;
    $phoneSequence++;
    $user = User::factory()->create([
        'phone' => '+9665'.str_pad((string) $phoneSequence, 8, '0', STR_PAD_LEFT),
        'phone_verified_at' => now(),
    ]);
    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'name_ar' => 'تحدي لاعب',
        'name_en' => 'Player challenge',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'name_ar' => 'بلايستيشن وإكس بوكس',
        'name_en' => 'PlayStation / Xbox',
        'price_halalah' => 1250,
        'sale_price_halalah' => null,
        'price_version' => 4,
        'is_active' => true,
    ]);
    $cart = Cart::create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    $item = $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => 1250,
        'total_halalah' => 1250,
        'configuration' => [
            'service_type' => 'sbc',
            'platform' => 'playstation',
            'market' => 'console',
            'completion_count' => 1,
            'quoted_at' => now()->utc()->toIso8601String(),
            'price_version' => 4,
        ],
    ]);
    $secret = new CartItemSecret([
        'cart_item_id' => $item->id,
        'masked_summary' => ['has_password' => true, 'backup_code_count' => 3],
        'retained_until' => null,
        'deleted_at' => null,
    ]);
    $secret->encrypted_payload = [
        'ea_email' => 'owner@example.test',
        'ea_password' => 'Opaque password',
        'backup_codes' => $codes,
    ];
    $secret->save();

    return compact('user', 'cart', 'item', 'variant');
}

test('a second correction cannot land while the first is still in flight', function (): void {
    // Two tabs are the test. Both used to read the same version, both wrote, and
    // whichever supplier call landed last decided what the supplier worked on -
    // which could be the details the store had already replaced.
    [$owner, $order, $item, $job] = actionableCoinsItem([SupplierAction::EditCredentials]);

    Cache::lock("credential-correction:item:{$item->id}", 30)->get();

    Http::fake(['https://fft.example.test/*' => Http::response(['status' => 'ok'])]);

    $response = $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/actions/edit-credentials", [
            'ea_email' => 'second@example.test',
            'ea_password' => 'second-password',
            'backup_codes' => ['111111', '222222', '333333'],
        ])
        ->assertOk();

    expect($response->json('status'))->toBe('refused');
    Http::assertNothingSent();
});

test('a supplier that refuses the correction still reports it as saved', function (): void {
    // The record is written before the call, so "that did not go through" would be
    // false: the store holds the corrected details and the next placement uses them.
    [$owner, $order, $item, $job] = actionableCoinsItem([SupplierAction::EditCredentials]);

    Http::fake([
        'https://fft.example.test/*' => Http::response(['error' => 'WrongUserPass']),
    ]);

    $response = $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/actions/edit-credentials", [
            'ea_email' => 'fresh@example.test',
            'ea_password' => 'fresh-password',
            'backup_codes' => ['111111', '222222', '333333'],
        ])
        ->assertOk();

    expect($response->json('status'))->toBe('saved_not_accepted');

    $secret = OrderItemSecret::query()->where('order_item_id', $item->id)->firstOrFail();
    expect($secret->encrypted_payload['ea_email'])->toBe('fresh@example.test');
});

test('a password keeps the spaces the customer typed', function (): void {
    // trimStrings would otherwise store and forward a different password from the
    // one that works, and the customer would have no way to see why.
    [$owner, $order, $item, $job] = actionableCoinsItem([SupplierAction::EditCredentials]);

    Http::fake(['https://fft.example.test/*' => Http::response(['status' => 'ok'])]);

    $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/actions/edit-credentials", [
            'ea_email' => 'spaces@example.test',
            'ea_password' => '  keep me  ',
            'backup_codes' => ['111111', '222222', '333333'],
        ])
        ->assertOk();

    $secret = OrderItemSecret::query()->where('order_item_id', $item->id)->firstOrFail();
    expect($secret->encrypted_payload['ea_password'])->toBe('  keep me  ');
});

test('a fresh payload is not left carrying the old retention date', function (): void {
    // The supplier had working details that support was told had been purged.
    [$owner, $order, $item, $job] = actionableCoinsItem([SupplierAction::EditCredentials]);

    OrderItemSecret::query()->where('order_item_id', $item->id)->firstOrFail()->forceFill([
        'deleted_at' => CarbonImmutable::now()->subDay(),
        'retained_until' => CarbonImmutable::now()->subDay(),
    ])->save();

    Http::fake(['https://fft.example.test/*' => Http::response(['status' => 'ok'])]);

    $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/actions/edit-credentials", [
            'ea_email' => 'again@example.test',
            'ea_password' => 'again-password',
            'backup_codes' => ['111111', '222222', '333333'],
        ])
        ->assertOk();

    $secret = OrderItemSecret::query()->where('order_item_id', $item->id)->firstOrFail();
    expect($secret->deleted_at)->toBeNull()
        ->and($secret->retained_until)->toBeNull();
});

test('an unreadable observation does not answer a pending correction', function (): void {
    // observed_at advances even for a response we could not parse, so comparing
    // timestamps alone let an unreadable answer close the question and show the
    // customer the old failure as the verdict on their new details.
    [$owner, $order, $item, $job] = actionableCoinsItem([SupplierAction::EditCredentials]);

    $job->forceFill([
        'credentials_sent_at' => CarbonImmutable::parse('2026-09-13 10:00:00'),
        'credential_version_sent' => 2,
        'observed_at' => CarbonImmutable::parse('2026-09-13 10:05:00'),
        'observation_supported' => false,
    ])->save();

    expect(ItemTracking::for($item->fresh(), 'en')['credentialsPending'])->toBeTrue();

    $job->forceFill(['observation_supported' => true])->save();

    expect(ItemTracking::for($item->fresh(), 'en')['credentialsPending'])->toBeFalse();
});
