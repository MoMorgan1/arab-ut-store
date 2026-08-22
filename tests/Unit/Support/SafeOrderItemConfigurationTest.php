<?php

use App\Actions\Checkout\PlaceOrder;
use App\Enums\ServiceType;
use App\Support\SafeOrderItemConfiguration;

test('order item configuration projection keeps only the service allowlist', function (
    ServiceType $service,
    array $configuration,
    array $expected,
): void {
    expect(SafeOrderItemConfiguration::project($configuration, $service))->toBe($expected);
})->with([
    'base keys for catalog services' => [
        ServiceType::Objectives,
        ['service_type' => 'objectives', 'platform' => 'xbox', 'market' => 'console', 'quoted_at' => '2026-08-22T00:00:00Z', 'price_version' => 3, 'secret_note' => 'drop'],
        ['service_type' => 'objectives', 'platform' => 'xbox', 'market' => 'console', 'quoted_at' => '2026-08-22T00:00:00Z', 'price_version' => 3],
    ],
    'coins adds delivery and quantity and preserves null delivery' => [
        ServiceType::Coins,
        ['service_type' => 'coins', 'platform' => 'playstation', 'market' => 'console', 'delivery' => null, 'coins_quantity' => 1500000, 'quoted_at' => 'q', 'price_version' => 1, 'coins_amount' => 'invented'],
        ['service_type' => 'coins', 'platform' => 'playstation', 'market' => 'console', 'delivery' => null, 'coins_quantity' => 1500000, 'quoted_at' => 'q', 'price_version' => 1],
    ],
    'sbc adds completion count' => [
        ServiceType::Sbc,
        ['service_type' => 'sbc', 'platform' => 'pc', 'market' => 'pc', 'completion_count' => 3, 'quoted_at' => 'q', 'price_version' => 2],
        ['service_type' => 'sbc', 'platform' => 'pc', 'market' => 'pc', 'completion_count' => 3, 'quoted_at' => 'q', 'price_version' => 2],
    ],
    'fut champions adds its five keys' => [
        ServiceType::FutChampions,
        ['service_type' => 'fut_champions', 'platform' => 'xbox', 'market' => 'console', 'pc_store' => true, 'schedule_version' => 7, 'rank' => 21, 'urgent' => false, 'matches_played' => 4, 'matches_count' => 'invented'],
        ['service_type' => 'fut_champions', 'platform' => 'xbox', 'market' => 'console', 'pc_store' => true, 'schedule_version' => 7, 'rank' => 21, 'urgent' => false, 'matches_played' => 4],
    ],
    'rivals adds division keys' => [
        ServiceType::Rivals,
        ['service_type' => 'rivals', 'platform' => 'playstation', 'market' => 'console', 'pc_store' => null, 'schedule_version' => 9, 'current_division' => 7, 'target_division' => 10],
        ['service_type' => 'rivals', 'platform' => 'playstation', 'market' => 'console', 'pc_store' => null, 'schedule_version' => 9, 'current_division' => 7, 'target_division' => 10],
    ],
]);

test('the checkout writer and the admin presenter share one key matrix', function (): void {
    $reflection = new ReflectionMethod(PlaceOrder::class, 'safeConfiguration');
    $placeOrder = app(PlaceOrder::class);
    $method = $reflection->getClosure($placeOrder);

    foreach (ServiceType::cases() as $service) {
        $sample = array_fill_keys(SafeOrderItemConfiguration::keys($service), 1);
        $sample['intruder'] = 'must-not-survive';

        expect($method($sample, $service))->toBe(SafeOrderItemConfiguration::project($sample, $service))
            ->and(array_key_exists('intruder', SafeOrderItemConfiguration::project($sample, $service)))->toBeFalse();
    }
});
