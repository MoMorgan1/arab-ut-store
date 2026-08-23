<?php

use App\Models\Order;
use App\Models\User;

test('the support URL redirects to the profile page support section forwarding the order query', function (
    string $path,
    string $target,
): void {
    $owner = User::factory()->create();
    $order = Order::factory()->for($owner)->create(['order_number' => 'UT-00000091']);

    $this->actingAs($owner)
        ->get($path.'?order='.$order->public_id)
        ->assertRedirect($target.'?order='.$order->public_id.'#support');
})->with([
    'Arabic support' => ['/my-account/support', '/my-account/profile'],
    'English support' => ['/en/my-account/support', '/en/my-account/profile'],
]);

test('the profile page exposes configured support contact links and an owned public order number', function (
    string $path,
    string $locale,
): void {
    config()->set('store.support.whatsapp_url', 'https://wa.me/966537998099');
    config()->set('store.support.email', 'support@example.test');
    $owner = User::factory()->create(['email' => 'owner-private@example.test']);
    $order = Order::factory()->for($owner)->create(['order_number' => 'UT-00000091']);

    $response = $this->actingAs($owner)
        ->get($path.'?order='.$order->public_id)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($page) => $page
            ->component('account/profile')
            ->where('locale', $locale)
            ->where('support.whatsappUrl', 'https://wa.me/966537998099')
            ->where('support.emailUrl', 'mailto:support@example.test')
            ->where('support.orderNumber', 'UT-00000091')
            ->where('support.available', true)
            ->where('accountNavigation', fn ($items): bool => collect($items)->pluck('key')->all() === [
                'overview', 'orders', 'wallet', 'profile',
            ]));

    $payload = json_encode($response->inertiaPage()['props']['support'], JSON_THROW_ON_ERROR);
    expect($payload)
        ->not->toContain('owner-private@example.test')
        ->and($response->inertiaPage()['props']['support']['whatsappUrl'])->not->toContain('UT-00000091')
        ->and($response->inertiaPage()['props']['support']['emailUrl'])->not->toContain('UT-00000091');
})->with([
    'Arabic profile support' => ['/my-account/profile', 'ar'],
    'English profile support' => ['/en/my-account/profile', 'en'],
]);

test('support does not expose another customers order and has a controlled unavailable state', function (): void {
    config()->set('store.support.whatsapp_url', null);
    config()->set('store.support.email', 'not-an-email');
    $customer = User::factory()->create();
    $otherOrder = Order::factory()->create(['order_number' => 'UT-PRIVATE-ORDER']);

    $response = $this->actingAs($customer)
        ->get('/my-account/profile?order='.$otherOrder->public_id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('account/profile')
            ->where('support.whatsappUrl', null)
            ->where('support.emailUrl', null)
            ->where('support.orderNumber', null)
            ->where('support.available', false));

    expect(json_encode($response->inertiaPage(), JSON_THROW_ON_ERROR))
        ->not->toContain('UT-PRIVATE-ORDER');
});
