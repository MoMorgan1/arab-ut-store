<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Store\StoreProofReader;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Cache::forget(StoreProofReader::CACHE_KEY);
});

it('counts served customers and completed orders from the orders table', function () {
    $repeat = User::factory()->create();
    $once = User::factory()->create();
    $unfinished = User::factory()->create();

    Order::factory()->for($repeat)->count(2)->create(['status' => OrderStatus::Completed]);
    Order::factory()->for($once)->create(['status' => OrderStatus::Completed, 'channel' => 'salla_import']);
    Order::factory()->for($unfinished)->create(['status' => OrderStatus::InProgress]);
    Order::factory()->for($repeat)->create(['status' => OrderStatus::Cancelled]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/home')
            ->where('store.hero.stats.0', ['value' => '+2', 'unit' => '', 'label' => 'عميل خدمناهم'])
            ->where('store.hero.stats.1', ['value' => '+3', 'unit' => '', 'label' => 'طلب مكتمل'])
            ->where('store.hero.stats.2.value', '+30')
            ->where('store.hero.stats.3.value', '99.9%'));
});

it('formats large counts with thousands separators in English', function () {
    Cache::put(StoreProofReader::CACHE_KEY, ['customers_served' => 9012, 'completed_orders' => 31456], 60);

    $this->get('/en')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('store.hero.stats.0', ['value' => '+9,012', 'unit' => '', 'label' => 'Customers served'])
            ->where('store.hero.stats.1', ['value' => '+31,456', 'unit' => '', 'label' => 'Completed orders']));
});

it('falls back to the audited export figures until the Salla history import has landed', function () {
    Order::factory()->count(3)->create(['status' => OrderStatus::Completed]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('store.hero.stats.0.value', '+8,877')
            ->where('store.hero.stats.1.value', '+29,161'));
});

it('caches the counts so the homepage does not recount on every visit', function () {
    Order::factory()->create(['status' => OrderStatus::Completed, 'channel' => 'salla_import']);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('store.hero.stats.1.value', '+1'));

    Order::factory()->create(['status' => OrderStatus::Completed]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('store.hero.stats.1.value', '+1'));

    Cache::forget(StoreProofReader::CACHE_KEY);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('store.hero.stats.1.value', '+2'));
});
