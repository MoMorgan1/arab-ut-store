<?php

use App\Actions\Checkout\RefundPaylinkOrder;
use App\Actions\Fulfillment\ApplySupplierObservation;
use App\Admin\Actions\TransitionAdminOrder;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\NotificationStatus;
use App\Enums\OrderHoldReason;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
use App\Enums\UserRole;
use App\Fulfillment\Notifications\CustomerNotificationCatalog;
use App\Models\FulfillmentJob;
use App\Models\IntegrationEvent;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Suppliers\RawSupplierObservation;
use App\Suppliers\Translation\SupplierStateTranslator;
use App\Suppliers\Translation\TranslatedState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{0: Order, 1: OrderItem, 2: FulfillmentJob}
 */
function notifyTestContext(
    OrderStatus $orderStatus = OrderStatus::InProgress,
    OrderItemStatus $itemStatus = OrderItemStatus::InProgress,
): array {
    $customer = User::factory()->create([
        'first_name' => 'Fahad',
        'last_name' => 'Al-Otaibi',
        'phone' => '+966512345678',
    ]);

    $order = Order::factory()->for($customer)->create([
        'order_number' => 'AUT-NOTIFY-'.strtoupper((string) Str::random(6)),
        'status' => $orderStatus,
        'locale' => 'ar',
        'channel' => 'store',
        'currency' => 'SAR',
        'paid_at' => now(),
        'subtotal_halalah' => 20_000,
        'payment_halalah' => 20_000,
        'total_halalah' => 20_000,
    ]);

    $item = OrderItem::factory()->for($order)->create([
        'status' => $itemStatus,
        'unit_price_halalah' => 20_000,
        'subtotal_halalah' => 20_000,
        'total_halalah' => 20_000,
    ]);

    $job = FulfillmentJob::factory()->create([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => Supplier::Fft,
        'delivery_phase' => DeliveryPhase::Coins,
    ]);

    return [$order, $item, $job];
}

function applyTestHold(FulfillmentJob $job, OrderHoldReason $reason): void
{
    app(ApplySupplierObservation::class)->execute(
        job: $job->fresh(),
        state: new TranslatedState(
            status: OrderStatus::WaitingForCustomer,
            holdReason: $reason,
            allowedActions: [SupplierAction::Resume],
            supported: true,
            observedState: 'test-hold',
        ),
        observedAt: CarbonImmutable::now(),
        rawPayload: ['status' => 'test-hold'],
    );
}

test('a supplier move into waiting writes exactly one message row and its outbox event', function (): void {
    [$order, $item, $job] = notifyTestContext();

    applyTestHold($job, OrderHoldReason::Credentials);

    $notifications = NotificationDelivery::query()->get();
    expect($notifications)->toHaveCount(1);

    $notification = $notifications->sole();
    expect($notification->order_id)->toBe($order->id)
        ->and($notification->order_item_id)->toBe($item->id)
        ->and($notification->channel)->toBe('whatsapp')
        ->and($notification->template_key)->toBe('credentials')
        ->and($notification->locale)->toBe('ar')
        ->and($notification->status)->toBe(NotificationStatus::Queued)
        ->and($notification->idempotency_key)->toStartWith("customer-notify:item:{$item->id}:credentials:")
        ->and($notification->recipient_masked)->not->toContain('966512345678')
        ->and($notification->recipient_masked)->toContain('5678');

    $stored = json_encode([$notification->payload, $notification->recipient_masked], JSON_THROW_ON_ERROR);
    expect($stored)->not->toContain('966512345678');

    $events = IntegrationEvent::query()->where('event_type', 'customer.notify')->get();
    expect($events)->toHaveCount(1)
        ->and($events->sole()->idempotency_key)->toBe($notification->idempotency_key)
        ->and($events->sole()->status)->toBe('pending');

    $eventStored = json_encode($events->sole()->payload, JSON_THROW_ON_ERROR);
    expect($eventStored)->not->toContain('966512345678');

    expect($notification->integration_event_id)->toBe($events->sole()->id);
});

test('replaying the same transition writes no second row', function (): void {
    [$order, $item, $job] = notifyTestContext();

    applyTestHold($job, OrderHoldReason::Credentials);
    applyTestHold($job, OrderHoldReason::Credentials);

    expect(NotificationDelivery::query()->count())->toBe(1)
        ->and(IntegrationEvent::query()->where('event_type', 'customer.notify')->count())->toBe(1);
});

test('a hold that recovers on its own stays silent', function (OrderHoldReason $reason): void {
    [$order, $item, $job] = notifyTestContext();

    applyTestHold($job, $reason);

    expect($item->fresh()->status)->toBe(OrderItemStatus::WaitingForCustomer)
        ->and(NotificationDelivery::query()->count())->toBe(0)
        ->and(IntegrationEvent::query()->where('event_type', 'customer.notify')->count())->toBe(0);
})->with([
    [OrderHoldReason::EaServers],
    [OrderHoldReason::StoreStock],
    [OrderHoldReason::Connection],
    [OrderHoldReason::NoPlayer],
    [OrderHoldReason::Maintenance],
    [OrderHoldReason::Paused],
    [OrderHoldReason::BelowMinimum],
]);

test('every hold reason is either mapped to a template or explicitly silent', function (): void {
    $maps = new ReflectionClass(SupplierStateTranslator::class);
    /** @var list<OrderHoldReason> $automatic */
    $automatic = $maps->getConstant('AUTOMATIC_RECOVERY_REASONS');

    expect(CustomerNotificationCatalog::SILENT)
        ->toEqualCanonicalizing(array_map(fn (OrderHoldReason $reason): string => $reason->value, $automatic));

    foreach (OrderHoldReason::cases() as $reason) {
        $mapped = CustomerNotificationCatalog::templateFor($reason) !== null;
        $silent = CustomerNotificationCatalog::isSilent($reason);

        expect($mapped !== $silent)->toBeTrue("{$reason->value} must be mapped or silent, never both or neither");
    }

    $ar = include lang_path('ar/notifications.php');
    $en = include lang_path('en/notifications.php');

    expect(array_keys($ar))->toEqualCanonicalizing(array_keys($en));

    $templates = array_map(
        fn (array $entry): string => $entry['template'],
        CustomerNotificationCatalog::MAPPED,
    );
    $templates[] = CustomerNotificationCatalog::TEMPLATE_ORDER_CANCELLED;
    $templates[] = CustomerNotificationCatalog::TEMPLATE_ORDER_REFUNDED;

    foreach ($templates as $template) {
        expect($ar)->toHaveKey($template, "missing ar wording for {$template}")
            ->and($en)->toHaveKey($template, "missing en wording for {$template}");

        foreach (['ar' => $ar, 'en' => $en] as $locale => $catalogue) {
            expect($catalogue[$template])->toContain(':order_number', "{$template} ({$locale}) names no order")
                ->and($catalogue[$template])->toContain(':link', "{$template} ({$locale}) carries no link")
                ->and($catalogue[$template])->not->toContain('track.arab-ut.com', "{$template} ({$locale}) points at the retired tracker");
        }
    }
});

test('each template names only a button its reason actually renders', function (): void {
    $translator = new SupplierStateTranslator;
    $maps = new ReflectionClass(SupplierStateTranslator::class);
    /** @var array<string, OrderHoldReason> $accountChecks */
    $accountChecks = $maps->getConstant('ACCOUNT_CHECK_HOLDS');
    /** @var array<string, OrderHoldReason> $economyStates */
    $economyStates = $maps->getConstant('ECONOMY_STATE_HOLDS');

    /** @var array<string, list<string>> $codesByReason */
    $codesByReason = [];

    foreach (['accountCheck' => $accountChecks, 'economyState' => $economyStates] as $field => $map) {
        foreach ($map as $code => $reason) {
            $other = $field === 'accountCheck'
                ? ['status' => 'entered', 'accountCheck' => $code, 'economyState' => '']
                : ['status' => 'entered', 'accountCheck' => '', 'economyState' => $code];

            $state = $translator->translate(
                new RawSupplierObservation(
                    supplier: Supplier::Fft,
                    supplierOrderId: 'fft-catalogue-proof',
                    payload: $other,
                    fetchedAt: CarbonImmutable::now(),
                ),
                OrderStatus::InProgress,
                DeliveryPhase::Coins,
            );

            $codesByReason[$reason->value][] = $state->allowedActions;
        }
    }

    foreach (OrderHoldReason::cases() as $reason) {
        $action = CustomerNotificationCatalog::actionFor($reason);
        $renders = $codesByReason[$reason->value] ?? [];

        if ($action === null) {
            // Silent: nothing to prove about buttons.
            continue;
        }

        $rendersEdit = false;
        $rendersResume = false;

        foreach ($renders as $actions) {
            $rendersEdit = $rendersEdit || in_array(SupplierAction::EditCredentials, $actions, true);
            $rendersResume = $rendersResume || in_array(SupplierAction::Resume, $actions, true);
        }

        match ($action) {
            'edit' => expect($rendersEdit)->toBeTrue("{$reason->value} names the edit form it never renders")
                ->and($rendersResume)->toBeFalse("{$reason->value} never renders resume"),
            'resume' => expect($rendersResume)->toBeTrue("{$reason->value} names resume it never renders"),
            'none' => expect($rendersEdit)->toBeFalse("{$reason->value} renders edit")
                ->and($rendersResume)->toBeFalse("{$reason->value} renders resume"),
            default => expect(false)->toBeTrue("unknown catalogue action {$action}"),
        };
    }
});

test('an admin pause queues one row per item that stopped, and nothing for a note without a reason', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    [$order, $item, $job] = notifyTestContext();
    $second = OrderItem::factory()->for($order)->create([
        'status' => OrderItemStatus::InProgress,
        'unit_price_halalah' => 20_000,
        'subtotal_halalah' => 20_000,
        'total_halalah' => 20_000,
    ]);

    app(TransitionAdminOrder::class)->execute(
        $admin,
        (string) $order->public_id,
        OrderStatus::WaitingForCustomer,
        OrderStatus::InProgress,
        OrderHoldReason::BackupCodes,
    );

    $templates = NotificationDelivery::query()->pluck('template_key')->all();
    expect($templates)->toEqualCanonicalizing(['backup_codes', 'backup_codes']);

    // A pause the admin writes as a bare note has no template to send.
    [$plainOrder, $plainItem, $plainJob] = notifyTestContext();

    app(TransitionAdminOrder::class)->execute(
        $admin,
        (string) $plainOrder->public_id,
        OrderStatus::WaitingForCustomer,
        OrderStatus::InProgress,
        null,
        'Waiting on the supplier, no customer wording.',
    );

    expect(NotificationDelivery::query()->where('order_id', $plainOrder->id)->count())->toBe(0);
});

test('an admin cancellation queues one order-level cancelled message', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    [$order, $item, $job] = notifyTestContext();

    app(TransitionAdminOrder::class)->execute(
        $admin,
        (string) $order->public_id,
        OrderStatus::Cancelled,
        OrderStatus::InProgress,
    );

    $notification = NotificationDelivery::query()->where('order_id', $order->id)->sole();
    expect($notification->template_key)->toBe('order_cancelled')
        ->and($notification->order_item_id)->toBeNull();
});

test('a completed refund queues the refunded message, worded apart from cancellation', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $order = Order::factory()->for(User::factory()->create([
        'first_name' => 'Fahad',
        'last_name' => 'Al-Otaibi',
        'phone' => '+966512345678',
    ]))->create([
        'order_number' => 'AUT-REFUND-1',
        'status' => OrderStatus::Received,
        'currency' => 'SAR',
        'subtotal_halalah' => 1250,
        'payment_halalah' => 1250,
        'total_halalah' => 1250,
        'paid_at' => now(),
    ]);
    $order->items()->create([
        'product_variant_id' => null,
        'name_ar' => 'خدمة رقمية',
        'name_en' => 'Digital service',
        'sku' => 'SBC_REFUND_NOTIFY',
        'service_type' => 'sbc',
        'platform' => 'playstation',
        'status' => OrderItemStatus::Received,
        'quantity' => 1,
        'unit_price_halalah' => 1250,
        'subtotal_halalah' => 1250,
        'discount_halalah' => 0,
        'total_halalah' => 1250,
        'configuration' => [],
    ]);
    $order->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => '1710000000099',
        'status' => PaymentStatus::Paid,
        'currency' => 'SAR',
        'amount_halalah' => 1250,
        'captured_halalah' => 1250,
        'refunded_halalah' => 0,
        'idempotency_key' => 'paylink-refund-notify-fixture',
        'paid_at' => now(),
    ]);

    config()->set('services.paylink', [
        'environment' => 'test',
        'api_id' => 'merchant-id',
        'secret_key' => 'merchant-secret',
        'webhook_token' => 'webhook-secret',
        'partner_profile_no' => 'profile-no',
        'partner_api_key' => 'partner-api-key',
        'merchant_lookup_key' => 'accountNo',
        'merchant_lookup_value' => '123456',
    ]);
    Http::fake([
        'https://restpilot.paylink.sa/api/partner/auth' => Http::response(['id_token' => 'partner-token']),
        'https://restpilot.paylink.sa/rest/partner/v2/merchant/accountNo/123456/refund' => Http::response([
            'id' => 237,
            'orderNumber' => 'AUT-REFUND-1',
            'amount' => 12.50,
            'currency' => 'SAR',
            'refundReason' => 'Customer request.',
            'createDatetime' => 1716194603030,
        ]),
    ]);

    app(RefundPaylinkOrder::class)->execute($order, 'Customer request.', $admin);

    $notification = NotificationDelivery::query()->where('order_id', $order->id)->sole();
    expect($notification->template_key)->toBe('order_refunded');

    $cancelledAr = trans('notifications.order_cancelled', ['name' => 'فهد', 'order_number' => 'AUT-1', 'link' => 'https://example.test'], 'ar');
    $refundedAr = trans('notifications.order_refunded', ['name' => 'فهد', 'order_number' => 'AUT-1', 'link' => 'https://example.test'], 'ar');

    expect($refundedAr)->not->toBe($cancelledAr)
        ->and($refundedAr)->toContain('استرجاع');
});
