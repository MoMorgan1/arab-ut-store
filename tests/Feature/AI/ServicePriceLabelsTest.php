<?php

declare(strict_types=1);

use App\Actions\AI\BuildServicePriceLabels;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\ExchangeRate;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ServicePriceSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

function seedCoinsCatalog(): void
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Coins,
        'name_ar' => 'كوينز ألتيميت تيم',
        'name_en' => 'FC Coins',
        'is_visible' => true,
        'archived_at' => null,
    ]);

    foreach ([Platform::PlayStation, Platform::Pc] as $platform) {
        ProductVariant::factory()->for($product)->create([
            'service_type' => ServiceType::Coins,
            'platform' => $platform,
            'is_active' => true,
        ]);
    }

    foreach (['console_normal', 'console_fast', 'pc'] as $group) {
        $configuration = [
            'version' => 1,
            'group' => $group,
            'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
            'multipliers_basis_points' => ['50000' => 10_000],
            'service_fee_halalah' => 0,
            'discount_divisor_basis_points' => 10_000,
            'exact_overrides_halalah' => [],
        ];
        $configuration[$group === 'console_normal' ? 'flat_rate_halalah_per_million' : 'tier_rates_halalah_per_million'] =
            $group === 'console_normal' ? 5_000 : array_fill(0, 6, 5_000);

        PriceRule::create([
            'product_variant_id' => null,
            'name' => "Coins {$group}",
            'service_type' => ServiceType::Coins,
            'configuration' => $configuration,
            'is_active' => true,
        ]);
    }
}

function seedSbcProduct(int $priceHalalah = 4_500, ?int $salePriceHalalah = null): Product
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'name_ar' => 'تحدي SBC',
        'name_en' => 'SBC Challenge',
        'is_visible' => true,
        'archived_at' => null,
    ]);

    ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'price_halalah' => $priceHalalah,
        'sale_price_halalah' => $salePriceHalalah,
        'is_active' => true,
    ]);

    return $product;
}

test('every returned service entry has a positive amountMinor, valid currency, and correct unit', function () {
    seedCoinsCatalog();
    seedSbcProduct();

    $labels = app(BuildServicePriceLabels::class)->execute('SAR');

    expect($labels)->toHaveKeys(['coins', 'sbc', 'rivals', 'fut_champions'])
        ->and($labels['coins']['unit'])->toBe('per_100k')
        ->and($labels['coins']['amountMinor'])->toBeGreaterThan(0)
        ->and($labels['coins']['currency'])->toBe('SAR')
        ->and($labels['sbc']['unit'])->toBe('total')
        ->and($labels['sbc']['amountMinor'])->toBeGreaterThan(0)
        ->and($labels['sbc']['currency'])->toBe('SAR')
        ->and($labels['rivals']['unit'])->toBe('total')
        ->and($labels['rivals']['amountMinor'])->toBeGreaterThan(0)
        ->and($labels['rivals']['currency'])->toBe('SAR')
        ->and($labels['fut_champions']['unit'])->toBe('total')
        ->and($labels['fut_champions']['amountMinor'])->toBeGreaterThan(0)
        ->and($labels['fut_champions']['currency'])->toBe('SAR');
});

test('coins price picks the cheaper 100k rate between console and PC', function () {
    $product = Product::factory()->create([
        'service_type' => ServiceType::Coins,
        'name_ar' => 'كوينز',
        'name_en' => 'Coins',
        'is_visible' => true,
        'archived_at' => null,
    ]);

    foreach ([Platform::PlayStation, Platform::Pc] as $platform) {
        ProductVariant::factory()->for($product)->create([
            'service_type' => ServiceType::Coins,
            'platform' => $platform,
            'is_active' => true,
        ]);
    }

    // Console normal rate: 6,000 halalah per million (100k = 600 halalah)
    // PC rate: 4,000 halalah per million (100k = 400 halalah)
    foreach (['console_normal', 'console_fast', 'pc'] as $group) {
        $configuration = [
            'version' => 1,
            'group' => $group,
            'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
            'multipliers_basis_points' => ['50000' => 10_000],
            'service_fee_halalah' => 0,
            'discount_divisor_basis_points' => 10_000,
            'exact_overrides_halalah' => [],
        ];
        $configuration[$group === 'console_normal' ? 'flat_rate_halalah_per_million' : 'tier_rates_halalah_per_million'] =
            $group === 'pc' ? array_fill(0, 6, 4_000) : ($group === 'console_normal' ? 6_000 : array_fill(0, 6, 7_000));

        PriceRule::create([
            'product_variant_id' => null,
            'name' => "Coins {$group}",
            'service_type' => ServiceType::Coins,
            'configuration' => $configuration,
            'is_active' => true,
        ]);
    }

    $labels = app(BuildServicePriceLabels::class)->execute('SAR');

    expect($labels['coins']['amountMinor'])->toBe(400);
});

test('rivals price reports the cheapest single ladder step', function () {
    $labels = app(BuildServicePriceLabels::class)->execute('SAR');

    // Default approved schedule step prices start at 11,000 halalah (7:6)
    expect($labels['rivals']['amountMinor'])->toBe(11_000);
});

test('fut champions price reports the cheapest non-urgent rank', function () {
    $labels = app(BuildServicePriceLabels::class)->execute('SAR');

    // Default approved schedule rank prices: rank 6 = 10,000 halalah
    expect($labels['fut_champions']['amountMinor'])->toBe(10_000);
});

test('sbc price reports the lowest active product effective price', function () {
    seedSbcProduct(priceHalalah: 8_000, salePriceHalalah: 3_500);
    seedSbcProduct(priceHalalah: 5_000, salePriceHalalah: null);

    $labels = app(BuildServicePriceLabels::class)->execute('SAR');

    expect($labels['sbc']['amountMinor'])->toBe(3_500);
});

test('a service whose pricing is missing or inactive is omitted while others still return', function () {
    // No coins or SBC seeded; deactivate Rivals
    ServicePriceSchedule::query()
        ->where('service_type', ServiceType::Rivals)
        ->update(['is_active' => false]);

    $labels = app(BuildServicePriceLabels::class)->execute('SAR');

    expect($labels)->not->toHaveKey('coins')
        ->and($labels)->not->toHaveKey('sbc')
        ->and($labels)->not->toHaveKey('rivals')
        ->and($labels)->toHaveKey('fut_champions')
        ->and($labels['fut_champions']['amountMinor'])->toBe(10_000);
});

test('converts prices to the requested display currency', function () {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 UTC');
    seedSbcProduct(priceHalalah: 10_000);

    ExchangeRate::create([
        'base_currency' => 'SAR',
        'quote_currency' => 'EUR',
        'rate' => '0.25000000',
        'source' => 'test',
        'fetched_at' => now(),
    ]);

    $labels = app(BuildServicePriceLabels::class)->execute('EUR');

    expect($labels['sbc']['currency'])->toBe('EUR')
        ->and($labels['sbc']['amountMinor'])->toBe(2_500);
});

test('caches computation per currency for 60 seconds', function () {
    seedSbcProduct(priceHalalah: 5_000);

    $first = app(BuildServicePriceLabels::class)->execute('SAR');
    expect($first['sbc']['amountMinor'])->toBe(5_000);

    // Update SBC price in DB — cached value should remain unchanged
    ProductVariant::query()->update(['price_halalah' => 1_000]);

    $second = app(BuildServicePriceLabels::class)->execute('SAR');
    expect($second['sbc']['amountMinor'])->toBe(5_000);

    Cache::forget('chat.service-prices.SAR');
    $third = app(BuildServicePriceLabels::class)->execute('SAR');
    expect($third['sbc']['amountMinor'])->toBe(1_000);
});
