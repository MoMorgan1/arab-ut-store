<?php

use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\ServiceType;
use App\Models\ServicePriceSchedule;
use App\ValueObjects\Pricing\FutChampionsPricing;
use App\ValueObjects\Pricing\RivalsPricing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function manualServicePricingMigration(): object
{
    return require database_path('migrations/2026_08_16_000001_create_service_price_schedules.php');
}

function approvedManualFutSchedule(): array
{
    return [
        'ranks' => [1 => 22_000, 2 => 19_000, 3 => 17_000, 4 => 15_000, 5 => 13_000, 6 => 10_000],
        'urgent_surcharge_halalah' => 4_000,
    ];
}

function approvedManualRivalsSchedule(): array
{
    return [
        'steps' => [
            '7:6' => 11_000,
            '6:5' => 12_000,
            '5:4' => 13_000,
            '4:3' => 14_000,
            '3:2' => 15_000,
            '2:1' => 16_000,
            '1:elite' => 17_000,
        ],
    ];
}

it('installs the exact approved manual-service schedules idempotently', function () {
    expect(Schema::hasColumns('service_price_schedules', [
        'id', 'public_id', 'service_type', 'version', 'configuration', 'is_active', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $fut = ServicePriceSchedule::query()->where('service_type', ServiceType::FutChampions)->sole();
    $rivals = ServicePriceSchedule::query()->where('service_type', ServiceType::Rivals)->sole();

    expect($fut->service_type)->toBe(ServiceType::FutChampions)
        ->and($fut->version)->toBe(1)
        ->and($fut->is_active)->toBeTrue()
        ->and($fut->configuration)->toBe(approvedManualFutSchedule())
        ->and($rivals->service_type)->toBe(ServiceType::Rivals)
        ->and($rivals->version)->toBe(1)
        ->and($rivals->is_active)->toBeTrue()
        ->and($rivals->configuration)->toBe(approvedManualRivalsSchedule());

    manualServicePricingMigration()->up();

    // Count the two this migration owns, not every schedule — the table also
    // carries the Coins quantity bands the admin edits.
    expect(ServicePriceSchedule::query()
        ->whereIn('service_type', [ServiceType::FutChampions, ServiceType::Rivals])
        ->count())->toBe(2);
});

it('enforces unique services and positive schedule versions', function () {
    expect(fn () => DB::table('service_price_schedules')->insert([
        'public_id' => (string) Str::ulid(),
        'service_type' => ServiceType::FutChampions->value,
        'version' => 2,
        'configuration' => json_encode(approvedManualFutSchedule(), JSON_THROW_ON_ERROR),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class)
        ->and(fn () => ServicePriceSchedule::query()
            ->where('service_type', ServiceType::Rivals)
            ->update(['version' => 0]))->toThrow(QueryException::class);
});

it('reads parsed FUT and Rivals schedules with optional row locking', function () {
    $reader = app(ReadManualServicePricing::class);

    $fut = $reader->futChampions();
    $lockedRivals = $reader->rivals(lock: true);

    expect($fut['schedule'])->toBeInstanceOf(ServicePriceSchedule::class)
        ->and($fut['pricing'])->toBeInstanceOf(FutChampionsPricing::class)
        ->and($fut['pricing']->priceForRank(3, true))->toBe(21_000)
        ->and($lockedRivals['schedule'])->toBeInstanceOf(ServicePriceSchedule::class)
        ->and($lockedRivals['pricing'])->toBeInstanceOf(RivalsPricing::class)
        ->and($lockedRivals['pricing']->priceForRoute('5', 'elite'))->toBe(75_000);
});

it('fails closed when a manual-service schedule is inactive', function (ServiceType $service, string $method) {
    ServicePriceSchedule::query()->where('service_type', $service)->update(['is_active' => false]);

    expect(fn () => app(ReadManualServicePricing::class)->{$method}())
        ->toThrow(DomainException::class, 'unavailable');
})->with([
    'FUT Champions' => [ServiceType::FutChampions, 'futChampions'],
    'Rivals' => [ServiceType::Rivals, 'rivals'],
]);

it('rolls the manual-service schedule migration down and back up cleanly', function () {
    $migration = manualServicePricingMigration();

    $migration->down();
    expect(Schema::hasTable('service_price_schedules'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('service_price_schedules'))->toBeTrue()
        ->and(ServicePriceSchedule::query()->count())->toBe(2);
});
