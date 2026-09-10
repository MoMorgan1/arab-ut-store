<?php

use App\Enums\ServiceType;

test('only FUT Champions and Rivals are manual services', function (): void {
    expect(ServiceType::FutChampions->isManual())->toBeTrue()
        ->and(ServiceType::Rivals->isManual())->toBeTrue()
        ->and(ServiceType::Coins->isManual())->toBeFalse()
        ->and(ServiceType::Sbc->isManual())->toBeFalse()
        ->and(ServiceType::Objectives->isManual())->toBeFalse()
        ->and(ServiceType::manual())->toBe([ServiceType::FutChampions, ServiceType::Rivals]);
});

test('the scheduled services are the manual ones plus coins', function (): void {
    expect(ServiceType::scheduled())->toBe([ServiceType::FutChampions, ServiceType::Rivals, ServiceType::Coins]);
});
