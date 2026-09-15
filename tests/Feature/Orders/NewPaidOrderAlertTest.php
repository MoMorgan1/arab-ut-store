<?php

use App\Actions\Orders\AlertOwnerOfPaidOrder;
use App\Enums\PaymentStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Notifications\NewPaidOrderAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function paidOrderForAlert(): Order
{
    $customer = User::factory()->create(['first_name' => 'سعود', 'last_name' => 'العتيبي']);

    $order = Order::factory()->for($customer)->create([
        'order_number' => 'AUT-2041',
        'locale' => 'ar',
        'currency' => 'SAR',
        'paid_at' => now(),
        'subtotal_halalah' => 12_550,
        'payment_halalah' => 12_550,
        'total_halalah' => 12_550,
    ]);

    OrderItem::factory()->for($order)->create([
        'name_ar' => 'كوينز 210K',
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'quantity' => 1,
        'unit_price_halalah' => 12_550,
        'subtotal_halalah' => 12_550,
        'total_halalah' => 12_550,
    ]);

    $order->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => 'inv-2041',
        'status' => PaymentStatus::Paid,
        'currency' => 'SAR',
        'amount_halalah' => 12_550,
        'captured_halalah' => 12_550,
        'refunded_halalah' => 0,
        'idempotency_key' => 'paylink:alert-test',
        'provider_metadata' => ['payment_method' => 'mada'],
        'paid_at' => now(),
    ]);

    return $order;
}

test('the owner is mailed at the configured address when an order is paid', function (): void {
    Notification::fake();
    config()->set('store.order_alerts.email', 'owner@example.com');

    $order = paidOrderForAlert();

    app(AlertOwnerOfPaidOrder::class)->execute($order);

    Notification::assertSentOnDemand(
        NewPaidOrderAlert::class,
        fn (NewPaidOrderAlert $alert, array $channels, AnonymousNotifiable $notifiable): bool => $alert->order->is($order)
            && $channels === ['mail']
            && $notifiable->routes['mail'] === 'owner@example.com',
    );
    Notification::assertSentTimes(NewPaidOrderAlert::class, 1);
});

test('no address configured means no alert and no error', function (?string $address): void {
    Notification::fake();
    config()->set('store.order_alerts.email', $address);

    app(AlertOwnerOfPaidOrder::class)->execute(paidOrderForAlert());

    Notification::assertNothingSent();
})->with([
    'unset' => [null],
    'empty' => [''],
    'not an address' => ['owner at example dot com'],
]);

test('the alert names the order, the customer, what was bought, the total, the method and links to the admin order', function (): void {
    config()->set('app.url', 'https://store.example.test');

    $mail = (new NewPaidOrderAlert(paidOrderForAlert()))->toMail(new AnonymousNotifiable);
    $body = implode("\n", array_map(fn ($line): string => (string) $line, [...$mail->introLines, ...$mail->outroLines]));

    expect($mail->subject)->toContain('AUT-2041')->toContain('125.50 ر.س')
        ->and($body)->toContain('سعود العتيبي')
        ->toContain('كوينز 210K (playstation)')
        ->toContain('125.50 ر.س')
        ->toContain('مدى')
        ->and($mail->actionUrl)->toBe('https://store.example.test/admin/orders/AUT-2041');
});
