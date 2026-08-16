<?php

use App\Enums\OrderItemStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\FulfillmentAttachment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\SecretAccessLog;
use App\Models\User;
use App\ValueObjects\Cart\ManualServiceCredentials;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @return array{owner: User, order: Order, item: OrderItem, secret: OrderItemSecret, attachment: FulfillmentAttachment}
 */
function ownerManualOrder(): array
{
    $owner = User::factory()->create();
    $order = Order::factory()->for($owner)->create([
        'locale' => 'en',
        'placed_at' => now(),
    ]);
    $item = OrderItem::factory()->for($order)->create([
        'service_type' => ServiceType::FutChampions,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::PendingPayment,
        'name_en' => 'FUT Champions service',
        'configuration' => [
            'service_type' => 'fut_champions',
            'platform' => 'playstation',
            'market' => 'console',
            'pc_store' => null,
            'rank' => 3,
            'urgent' => true,
            'matches_played' => 4,
            'quoted_at' => now()->utc()->toIso8601String(),
            'price_version' => 1,
            'schedule_version' => 1,
        ],
    ]);
    $credentials = ManualServiceCredentials::fromValidated([
        'platform' => 'playstation',
        'playstation_email' => 'reveal@example.test',
        'playstation_password' => 'Reveal PS secret',
        'ea_backup_codes' => ['12345678', '23456789', '34567890'],
        'playstation_backup_codes' => ['A1B2C3', 'D4E5F6', 'Z9Y8X7'],
    ]);
    $secret = new OrderItemSecret([
        'order_item_id' => $item->id,
        'masked_summary' => $credentials->maskedSummary(),
        'deleted_at' => null,
    ]);
    $secret->encrypted_payload = $credentials->payload();
    $secret->save();
    $path = 'fulfillment/squad-images/owner-order.png';
    Storage::disk('local')->put($path, 'owner private squad image');
    $attachment = FulfillmentAttachment::create([
        'cart_item_id' => null,
        'order_item_id' => $item->id,
        'kind' => 'squad_image',
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'image/png',
        'bytes' => strlen('owner private squad image'),
        'sha256' => hash('sha256', 'owner private squad image'),
    ]);

    return compact('owner', 'order', 'item', 'secret', 'attachment');
}

it('reveals normalized credentials only to the order owner and records the access', function () {
    $state = ownerManualOrder();
    $url = "/en/my-account/orders/{$state['order']->public_id}/items/{$state['item']->public_id}/credentials";

    $response = $this->actingAs($state['owner'])->getJson($url, ['X-Forwarded-For' => '203.0.113.9']);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['data' => [
            'platform' => 'playstation',
            'playstationEmail' => 'reveal@example.test',
            'playstationPassword' => 'Reveal PS secret',
            'eaBackupCodes' => ['12345678', '23456789', '34567890'],
            'playstationBackupCodes' => ['A1B2C3', 'D4E5F6', 'Z9Y8X7'],
        ]]);

    $log = SecretAccessLog::query()->sole();
    expect($log->order_item_secret_id)->toBe($state['secret']->id)
        ->and($log->user_id)->toBe($state['owner']->id)
        ->and($log->purpose)->toBe('customer_order_reveal')
        ->and($log->ip_address)->not->toBeNull()
        ->and($log->accessed_at)->not->toBeNull();
});

it('streams the private squad image only to the order owner without exposing its path', function () {
    $state = ownerManualOrder();
    $url = "/my-account/orders/{$state['order']->public_id}/items/{$state['item']->public_id}/squad-image";

    $response = $this->actingAs($state['owner'])->get($url);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Type', 'image/png');
    expect($response->streamedContent())->toBe('owner private squad image')
        ->and($response->headers->get('Content-Disposition'))->not->toContain('owner-order.png');
});

it('returns not found for other users, item-order mismatches, and deleted fulfillment', function () {
    $state = ownerManualOrder();
    $other = User::factory()->create();
    $otherItem = OrderItem::factory()->create(['service_type' => ServiceType::FutChampions]);
    $base = "/my-account/orders/{$state['order']->public_id}/items";

    $this->actingAs($other)
        ->getJson("{$base}/{$state['item']->public_id}/credentials")
        ->assertNotFound();
    $this->actingAs($state['owner'])
        ->getJson("{$base}/{$otherItem->public_id}/credentials")
        ->assertNotFound();

    $state['secret']->update(['deleted_at' => now()]);
    $state['attachment']->delete();
    $this->getJson("{$base}/{$state['item']->public_id}/credentials")->assertNotFound();
    $this->get("{$base}/{$state['item']->public_id}/squad-image")->assertNotFound();
    expect(SecretAccessLog::query()->count())->toBe(0);
});

it('requires authentication and exposes no post-order credential update route', function () {
    $state = ownerManualOrder();
    $url = "/my-account/orders/{$state['order']->public_id}/items/{$state['item']->public_id}/credentials";

    $this->getJson($url)->assertUnauthorized();
    $this->actingAs($state['owner'])->patchJson($url, [])->assertMethodNotAllowed();
});

it('keeps ordinary order props secret-free and provides owner-scoped reveal URLs only', function () {
    $state = ownerManualOrder();

    $response = $this->actingAs($state['owner'])
        ->get("/en/my-account/orders/{$state['order']->public_id}")
        ->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.manualFulfillment.credentialsUrl',
            "/en/my-account/orders/{$state['order']->public_id}/items/{$state['item']->public_id}/credentials")
        ->where('order.items.0.manualFulfillment.squadImageUrl',
            "/en/my-account/orders/{$state['order']->public_id}/items/{$state['item']->public_id}/squad-image")
        ->where('order.items.0.manualFulfillment.targetRank', 3)
        ->where('order.items.0.manualFulfillment.urgent', true)
        ->missing('order.items.0.credentials')
        ->missing('order.items.0.path'));

    expect(strtolower($response->getContent()))->not->toContain(
        'reveal@example.test',
        'reveal ps secret',
        '12345678',
        'a1b2c3',
        'fulfillment/squad-images',
    );
});
