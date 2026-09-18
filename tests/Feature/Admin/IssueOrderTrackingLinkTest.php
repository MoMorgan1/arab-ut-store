<?php

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderTrackingLink;
use App\Models\StaffAuditLog;
use App\Models\User;
use Laravel\Fortify\Fortify;

function trackingLinkActor(UserRole $role = UserRole::Admin): User
{
    $actor = User::factory()->create(['role' => $role, 'is_active' => true]);

    // The admin area sits behind EnsureAdminMfa: an actor without a confirmed
    // second factor is redirected before any of this runs.
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('TRACKINGLINKTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}

function trackingLinkOrder(): Order
{
    return Order::factory()->create([
        'status' => OrderStatus::InProgress,
        'locale' => 'ar',
        'paid_at' => now(),
    ]);
}

test('an admin is handed the order tracking link, and the ask is audited', function (): void {
    $actor = trackingLinkActor();
    $order = trackingLinkOrder();

    $response = $this->actingAs($actor)
        ->postJson("/admin/api/orders/{$order->order_number}/tracking-link");

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.order_number', $order->order_number);

    $url = $response->json('data.url');

    expect($url)->toContain('/orders/track/');

    // The token in the link is the one the store stored, so the link opens.
    $link = OrderTrackingLink::query()->where('order_id', $order->id)->sole();
    expect($url)->toContain((string) $link->token_encrypted);

    $audit = StaffAuditLog::query()->where('action', 'orders.tracking_link_issued')->sole();
    expect($audit->actor_user_id)->toBe($actor->id);
});

test('asking twice hands back the same link rather than cutting the first one', function (): void {
    $actor = trackingLinkActor();
    $order = trackingLinkOrder();

    $first = $this->actingAs($actor)
        ->postJson("/admin/api/orders/{$order->order_number}/tracking-link")
        ->json('data.url');

    $second = $this->actingAs($actor)
        ->postJson("/admin/api/orders/{$order->order_number}/tracking-link")
        ->json('data.url');

    // A second token would silently break a link already sent to a customer.
    expect($second)->toBe($first)
        ->and(OrderTrackingLink::query()->where('order_id', $order->id)->count())->toBe(1);
});

test('an English order gets its English link', function (): void {
    $actor = trackingLinkActor();
    $order = trackingLinkOrder();
    $order->forceFill(['locale' => 'en'])->save();

    $url = $this->actingAs($actor)
        ->postJson("/admin/api/orders/{$order->order_number}/tracking-link")
        ->json('data.url');

    expect($url)->toContain('/en/');
});

test('staff without orders.view are refused, and so is a guest', function (): void {
    $order = trackingLinkOrder();

    $this->postJson("/admin/api/orders/{$order->order_number}/tracking-link")
        ->assertUnauthorized();

    $customer = User::factory()->create(['role' => UserRole::Customer]);

    $this->actingAs($customer)
        ->postJson("/admin/api/orders/{$order->order_number}/tracking-link")
        ->assertForbidden();

    expect(OrderTrackingLink::query()->count())->toBe(0)
        ->and(StaffAuditLog::query()->where('action', 'orders.tracking_link_issued')->count())->toBe(0);
});

test('the order detail page carries the endpoint', function (): void {
    $actor = trackingLinkActor();
    $order = trackingLinkOrder();

    $this->actingAs($actor)
        ->get("/admin/orders/{$order->order_number}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('trackingLinkUrl', "/admin/api/orders/{$order->order_number}/tracking-link"));
});
