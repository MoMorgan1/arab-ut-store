<?php

use App\Actions\Pricing\BuildCoinsQuoteSchedule;
use App\Actions\Pricing\QuoteCoins;
use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\ExchangeRate;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function scheduleRuleConfiguration(string $group, array $changes = []): array
{
    $configuration = [
        'version' => 1,
        'group' => $group,
        'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 0,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
    ];
    $configuration[$group === 'console_normal'
        ? 'flat_rate_halalah_per_million'
        : 'tier_rates_halalah_per_million'] = $group === 'console_normal'
            ? 5_000
            : [5_000, 6_000, 7_000, 8_000, 9_000, 15_000];

    return array_replace($configuration, $changes);
}

/** @return array{product: Product, variants: array<string, ProductVariant>} */
function createScheduleCatalog(): array
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Coins,
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variants = [];

    foreach ([Platform::PlayStation, Platform::Pc] as $platform) {
        $variants[$platform->value] = ProductVariant::factory()->for($product)->create([
            'service_type' => ServiceType::Coins,
            'platform' => $platform,
            'price_version' => 7,
            'is_active' => true,
        ]);
    }

    foreach (['console_normal', 'pc'] as $group) {
        PriceRule::create([
            'name' => "Schedule {$group}",
            'service_type' => ServiceType::Coins,
            'configuration' => scheduleRuleConfiguration($group),
            'is_active' => true,
        ]);
    }

    PriceRule::create([
        'name' => 'Schedule console fast',
        'service_type' => ServiceType::Coins,
        'configuration' => scheduleRuleConfiguration('console_fast', [
            'exact_overrides_halalah' => ['50000' => 1_200, '5000000' => 90_000],
        ]),
        'is_active' => true,
    ]);

    return ['product' => $product, 'variants' => $variants];
}

test('it builds compact exact schedules at every legal quantity', function (
    Platform $platform,
    ?DeliveryMode $delivery,
    int $maximum,
    int $expectedEntries,
    int $expectedFirstHalalah,
    int $expectedLastHalalah,
) {
    $catalog = createScheduleCatalog();
    CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');

    $schedule = app(BuildCoinsQuoteSchedule::class)->execute($platform, $delivery, $maximum, 'SAR');

    expect($schedule)
        ->toMatchArray([
            'platform' => $platform->value,
            'delivery' => $delivery?->value,
            'market' => $platform->market()->value,
            'minimum' => 50_000,
            'maximum' => $maximum,
            'productId' => $catalog['product']->public_id,
            'variantId' => $catalog['variants'][$platform->value]->public_id,
            'priceVersion' => 7,
            'pricedAt' => '2026-08-10T12:00:00+00:00',
            'displayCurrency' => 'SAR',
        ])
        ->and($schedule['totalsHalalah'])->toHaveCount($expectedEntries)
        ->and($schedule['displayTotalsMinor'])->toHaveCount($expectedEntries)
        ->and($schedule['totalsHalalah'][0])->toBe($expectedFirstHalalah)
        ->and($schedule['totalsHalalah'][$expectedEntries - 1])->toBe($expectedLastHalalah)
        ->and($schedule['displayTotalsMinor'][0])->toBe($expectedFirstHalalah)
        ->and($schedule['displayTotalsMinor'][$expectedEntries - 1])->toBe($expectedLastHalalah)
        ->and(array_is_list($schedule['totalsHalalah']))->toBeTrue()
        ->and(array_is_list($schedule['displayTotalsMinor']))->toBeTrue();

    // The schedule now carries the quantity each entry prices, because the step
    // widens as the quantity climbs and position no longer implies quantity.
    expect($schedule['quantities'])->toHaveCount($expectedEntries)
        ->and($schedule['quantities'][0])->toBe($schedule['minimum'])
        ->and($schedule['quantities'][$expectedEntries - 1])->toBe($schedule['maximum']);

    foreach ($schedule['totalsHalalah'] as $index => $totalHalalah) {
        $quantity = $schedule['quantities'][$index];

        expect($quantity)->toBeLessThanOrEqual($schedule['maximum'])
            ->and($totalHalalah)->toBeInt()
            ->and($totalHalalah)->toBeGreaterThan(0)
            ->and($schedule['displayTotalsMinor'][$index])->toBeInt()
            ->and($schedule['displayTotalsMinor'][$index])->toBeGreaterThan(0);
    }
})->with([
    'PlayStation normal' => [Platform::PlayStation, DeliveryMode::Normal, 2_000_000, 76, 250, 10_000],
    'PlayStation fast' => [Platform::PlayStation, DeliveryMode::Fast, 20_000_000, 148, 1_200, 300_000],
    'PC' => [Platform::Pc, null, 20_000_000, 148, 250, 300_000],
]);

test('it uses the real fast tier and exact override totals at their indexed quantities', function () {
    createScheduleCatalog();

    $schedule = app(BuildCoinsQuoteSchedule::class)->execute(
        Platform::PlayStation,
        DeliveryMode::Fast,
        20_000_000,
        'SAR',
    );

    // Look the quantity up rather than trusting its position: the step widens as
    // the quantity climbs, so index no longer maps to quantity arithmetically.
    $at = static fn (int $quantity): int => $schedule['totalsHalalah'][array_search($quantity, $schedule['quantities'], true)];

    expect($at(50_000))->toBe(1_200)
        ->and($at(100_000))->toBe(600)
        ->and($at(2_000_000))->toBe(16_000)
        ->and($at(5_000_000))->toBe(90_000)
        ->and($at(20_000_000))->toBe(300_000)
        ->and($schedule['totalsHalalah'][0])->toBe(
            app(QuoteCoins::class)->execute(Platform::PlayStation, DeliveryMode::Fast, 50_000)->total->halalah(),
        );
});

test('it converts schedules through fixed-point display money with a single schedule timestamp', function () {
    createScheduleCatalog();
    CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
    ExchangeRate::create([
        'base_currency' => 'SAR',
        'quote_currency' => 'USD',
        'rate' => '0.26666667',
        'source' => 'exchange-rate-api-open-access',
        'fetched_at' => now(),
    ]);

    $schedule = app(BuildCoinsQuoteSchedule::class)->execute(Platform::Pc, null, 2_000_000, 'USD');

    expect($schedule['pricedAt'])->toBe('2026-08-10T12:00:00+00:00')
        ->and($schedule['displayCurrency'])->toBe('USD')
        ->and($schedule['displayTotalsMinor'][0])->toBe(67)
        ->and($schedule['displayTotalsMinor'][array_search(2_000_000, $schedule['quantities'], true)])->toBe(4_267);
});

test('it fails closed when a foreign display rate is missing stale or malformed', function (string $failure) {
    createScheduleCatalog();
    CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');

    if ($failure === 'stale') {
        ExchangeRate::create([
            'base_currency' => 'SAR',
            'quote_currency' => 'EUR',
            'rate' => '0.25000000',
            'source' => 'exchange-rate-api-open-access',
            'fetched_at' => now()->subHours(30),
        ]);
    }

    if ($failure === 'malformed') {
        $createMalformedRate = fn () => ExchangeRate::create([
            'base_currency' => 'SAR',
            'quote_currency' => 'EUR',
            'rate' => 'invalid-rate',
            'source' => 'exchange-rate-api-open-access',
            'fetched_at' => now(),
        ]);

        if (in_array(DB::getDriverName(), ['mariadb', 'mysql'], true)) {
            expect($createMalformedRate)->toThrow(QueryException::class);

            return;
        }

        $createMalformedRate();
    }

    expect(fn () => app(BuildCoinsQuoteSchedule::class)->execute(Platform::Pc, null, 2_000_000, 'EUR'))
        ->toThrow(DomainException::class);
})->with(['missing', 'stale', 'malformed']);

test('it matches server quotes at every tier cap exact override and fast floor boundary', function (
    Platform $platform,
    ?DeliveryMode $delivery,
    int $maximum,
    array $quantities,
) {
    createScheduleCatalog();

    $schedule = app(BuildCoinsQuoteSchedule::class)->execute($platform, $delivery, $maximum, 'SAR');

    foreach ($quantities as $quantity) {
        $index = array_search($quantity, $schedule['quantities'], true);

        expect($index)->toBeInt("{$quantity} is not a quantity the schedule prices");
        $quote = app(QuoteCoins::class)->execute($platform, $delivery, $quantity);

        expect($schedule['totalsHalalah'][$index])->toBe($quote->total->halalah());
    }
})->with([
    'normal minimum maximum and tier caps' => [
        Platform::PlayStation,
        DeliveryMode::Normal,
        2_000_000,
        [50_000, 100_000, 500_000, 1_000_000, 2_000_000],
    ],
    'fast overrides tier caps and floor boundaries' => [
        Platform::PlayStation,
        DeliveryMode::Fast,
        20_000_000,
        [50_000, 100_000, 500_000, 1_000_000, 2_000_000, 5_000_000, 20_000_000],
    ],
    'PC minimum maximum and tier caps' => [
        Platform::Pc,
        null,
        2_000_000,
        [50_000, 100_000, 500_000, 1_000_000, 2_000_000],
    ],
]);

test('it builds all homepage schedules without per-entry catalog queries', function () {
    createScheduleCatalog();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $startedAt = hrtime(true);

    $builder = app(BuildCoinsQuoteSchedule::class);
    $builder->execute(Platform::PlayStation, DeliveryMode::Normal, 2_000_000, 'SAR');
    $builder->execute(Platform::PlayStation, DeliveryMode::Fast, 20_000_000, 'SAR');
    $builder->execute(Platform::Pc, null, 2_000_000, 'SAR');

    $durationMilliseconds = intdiv(hrtime(true) - $startedAt, 1_000_000);
    $queries = DB::getQueryLog();

    DB::disableQueryLog();

    expect($durationMilliseconds)->toBeLessThan(1_000)
        ->and(count($queries))->toBeLessThanOrEqual(10);
});
