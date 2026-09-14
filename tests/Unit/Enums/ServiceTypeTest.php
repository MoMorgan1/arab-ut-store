<?php

use App\Enums\ServiceType;

test('FUT Champions, Rivals, and Objectives are manual services', function (): void {
    expect(ServiceType::FutChampions->isManual())->toBeTrue()
        ->and(ServiceType::Rivals->isManual())->toBeTrue()
        ->and(ServiceType::Objectives->isManual())->toBeTrue()
        ->and(ServiceType::Coins->isManual())->toBeFalse()
        ->and(ServiceType::Sbc->isManual())->toBeFalse()
        ->and(ServiceType::manual())->toBe([ServiceType::Objectives, ServiceType::Rivals, ServiceType::FutChampions]);
});

test('only FUT Champions and Rivals are booster-configured services', function (): void {
    expect(ServiceType::FutChampions->isBoosterConfigured())->toBeTrue()
        ->and(ServiceType::Rivals->isBoosterConfigured())->toBeTrue()
        ->and(ServiceType::Objectives->isBoosterConfigured())->toBeFalse()
        ->and(ServiceType::Coins->isBoosterConfigured())->toBeFalse()
        ->and(ServiceType::Sbc->isBoosterConfigured())->toBeFalse()
        ->and(ServiceType::boosterConfigured())->toBe([ServiceType::Rivals, ServiceType::FutChampions]);
});

test('the scheduled services are FUT Champions, Rivals, and Coins', function (): void {
    expect(ServiceType::scheduled())->toBe([ServiceType::FutChampions, ServiceType::Rivals, ServiceType::Coins]);
});
