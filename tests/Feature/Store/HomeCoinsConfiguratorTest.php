<?php

use App\Actions\Pricing\BuildCoinsQuoteSchedule;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\ExchangeRate;
use App\Models\PriceRule;
use App\Models\PriceRun;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\CoinsCatalogReader;
use App\Services\Pricing\CoinsPriceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function homeRuleConfiguration(string $group, array $changes = []): array
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
            : array_fill(0, 6, 5_000);

    return array_replace($configuration, $changes);
}

function createHomeCatalog(): Product
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Coins,
        'name_ar' => 'كوينز ألتيميت تيم',
        'name_en' => 'Ultimate Team Coins',
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
        PriceRule::create([
            'name' => "Coins {$group}",
            'service_type' => ServiceType::Coins,
            'configuration' => homeRuleConfiguration($group),
            'is_active' => true,
        ]);
    }

    return $product;
}

test('the Arabic and English homepages expose the exact localized Coins contract', function (
    string $path,
    string $quoteUrl,
    string $addUrl,
) {
    createHomeCatalog();

    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/home')
            ->where('status', 'available')
            ->missing('product')
            ->where('quoteUrl', $quoteUrl)
            ->has('quoteSchedules', 3)
            ->where('quoteSchedules.playstation:normal.minimum', 50_000)
            ->where('quoteSchedules.playstation:normal.maximum', 2_000_000)
            ->where('quoteSchedules.playstation:normal.priceVersion', 1)
            ->has('quoteSchedules.playstation:normal.quantities', 76)
            ->has('quoteSchedules.playstation:normal.totalsHalalah', 76)
            ->has('quoteSchedules.playstation:normal.displayTotalsMinor', 76)
            ->where('quoteSchedules.playstation:fast.maximum', 20_000_000)
            ->has('quoteSchedules.playstation:fast.totalsHalalah', 148)
            ->where('quoteSchedules.pc.delivery', null)
            ->has('quoteSchedules.pc.totalsHalalah', 148)
            ->where('coinsCart.addUrl', $addUrl)
            ->missing('coinsCart.resumeUrl')
            ->where('coinsCart.initialSelection', null)
            ->where('amount', [
                'minimum' => 50_000,
                'roundingUnit' => 5_000,
                'tiers' => [
                    ['upTo' => 500_000, 'step' => 10_000],
                    ['upTo' => 2_000_000, 'step' => 50_000],
                    ['upTo' => 20_000_000, 'step' => 250_000],
                ],
                'presets' => [50_000, 100_000, 500_000, 1_000_000, 5_000_000],
            ])
            ->where('platforms.0.value', 'playstation')
            ->where('platforms.0.iconUrls', [
                '/images/store/platforms/ps-logo-white-80.webp',
                '/images/store/platforms/xbox-logo-white-80.webp',
            ])
            ->where('platforms.0.maximum', 20_000_000)
            ->where('platforms.0.deliveries.0.value', 'normal')
            ->where('platforms.0.deliveries.0.minutesPerMillion', 150)
            ->where('platforms.0.deliveries.1.value', 'fast')
            ->where('platforms.0.deliveries.1.minutesPerMillion', 45)
            ->where('platforms.1.value', 'pc')
            ->where('platforms.1.iconUrls', ['/images/store/platforms/pc-logo.svg'])
            ->where('platforms.1.maximum', 20_000_000)
            ->where('platforms.1.deliveries', [])
            ->has('platforms', 2)
            ->has('store.seo_title')
            ->has('store.hero.stats', 4)
            ->has('store.hero.proof_label')
            ->missing('platforms.0.market')
            ->missing('platforms.1.market'));
})->with([
    'Arabic' => ['/', '/coins/quote', '/cart/items/coins'],
    'English' => ['/en', '/en/coins/quote', '/en/cart/items/coins'],
]);

test('an authenticated homepage rehydrates only a validated safe resume selection', function () {
    createHomeCatalog();
    $this->actingAs(User::factory()->create());

    $this->get('/en?step=credentials&platform=playstation&delivery=fast&quantity=100000')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('coinsCart.initialSelection', [
                'platform' => 'playstation',
                'delivery' => 'fast',
                'quantity' => 100_000,
            ])
            ->missing('coinsCart.initialSelection.credentials'));

    $this->get('/en?step=credentials&platform=pc&quantity=50001')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('coinsCart.initialSelection', null));

    $this->get('/en?step=credentials&platform=pc&quantity=50000&ea_password=must-not-enter-a-url')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('coinsCart.initialSelection', null));
});

test('configured Coins media URLs resolve to public assets', function () {
    $urls = [
        ...collect(config('coins.platforms'))->flatMap(
            fn (array $platform): array => $platform['icon_urls'],
        )->all(),
    ];

    foreach ($urls as $url) {
        expect($url)->toBeString();
        expect(public_path(ltrim($url, '/')))->toBeFile();
    }
});

test('the homepage exposes the balance requirement only when the admin toggle is on', function () {
    createHomeCatalog();

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('coinsRequiresBalance', false));

    enableCoinsCurrentBalanceRequirement();

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('coinsRequiresBalance', true));
});

test('the homepage remains honest when local catalog or pricing is unavailable', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'unavailable')
            ->missing('product')
            ->where('quoteUrl', '/coins/quote')
            ->has('platforms', 2)
            ->has('store.availability.title'));
});

test('the homepage fails closed instead of serializing a partial foreign-currency schedule', function () {
    createHomeCatalog();

    $this->withSession(['display_currency' => 'EUR'])
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'unavailable')
            ->missing('quoteSchedules'));
});

test('the foreign-currency homepage builds every schedule from one pricing and rate snapshot', function () {
    createHomeCatalog();
    CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
    ExchangeRate::create([
        'base_currency' => 'SAR',
        'quote_currency' => 'USD',
        'rate' => '0.26666667',
        'source' => 'exchange-rate-api-open-access',
        'fetched_at' => now(),
    ]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $startedAt = hrtime(true);

    $response = $this->withSession(['display_currency' => 'USD'])->get('/');

    $durationMilliseconds = intdiv(hrtime(true) - $startedAt, 1_000_000);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $queriesFor = function (string $table) use ($queries): int {
        $wrappedTable = DB::connection()->getQueryGrammar()->wrapTable($table);

        return collect($queries)
            ->filter(fn (array $query): bool => str_contains($query['query'], "from {$wrappedTable}"))
            ->count();
    };

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'available')
            ->where('quoteSchedules.playstation:normal.displayCurrency', 'USD')
            ->where('quoteSchedules.playstation:fast.displayCurrency', 'USD')
            ->where('quoteSchedules.pc.displayCurrency', 'USD'));

    // The budget rose from 10 when the shared Inertia props began reading the
    // Coins balance toggle live on every page — one indexed schedule read, so
    // chat offers stored in history can never disagree with the cart endpoint.
    // It rose again to 12 when the FAQ moved from the language files to the
    // faq_entries table (one ordered read of a handful of rows), and to 13
    // when the hero proof started counting completed orders and served
    // customers from the orders table (one aggregate, cached for 15 minutes),
    // and to 14 when the configurator started asking the last pricing run what
    // a supplier could actually deliver - asserted below as exactly one read of
    // price_runs, because every platform and delivery asks and the answer is
    // memoised, so it must not grow with the catalogue.
    //
    // 15 covers the second read of price_rules that money-anchored quantities
    // need. The reader memoises it, but the schedule builder and the controller
    // each hold their own reader, so the request pays for it twice. Two
    // constant reads, not a per-item one - collapsing them means sharing the
    // reader for the request, which is a change to make deliberately and not as
    // a side effect of a pricing feature.
    expect($durationMilliseconds)->toBeLessThan(1_000)
        ->and(count($queries))->toBeLessThanOrEqual(15)
        ->and($queriesFor('price_rules'))->toBeLessThanOrEqual(2)
        ->and($queriesFor('price_runs'))->toBe(1)
        ->and($queriesFor('exchange_rates'))->toBe(1);
});

test('the homepage fails closed when an active Coins pricing rule has an unknown group', function () {
    createHomeCatalog();
    PriceRule::create([
        'name' => 'Unknown Coins pricing',
        'service_type' => ServiceType::Coins,
        'configuration' => ['group' => 'legacy_console'],
        'is_active' => true,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'unavailable')
            ->missing('product'));
});

test('an optional Xbox Coins variant is not exposed as a separate homepage choice', function () {
    $product = createHomeCatalog();
    ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Coins,
        'platform' => Platform::Xbox,
        'is_active' => true,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'available')
            ->has('platforms', 2)
            ->where('platforms.0.value', 'playstation')
            ->where('platforms.1.value', 'pc'));
});

test('the homepage fails closed when either localized Coins product name is blank', function (
    string $field,
    string $value,
) {
    $product = createHomeCatalog();
    $product->update([$field => $value]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'unavailable')
            ->missing('product'));
})->with([
    'empty Arabic name' => ['name_ar', ''],
    'empty English name' => ['name_en', ''],
    'whitespace-only name' => ['name_ar', " \t\n"],
]);

test('the homepage fails closed when pricing does not cover the minimum legal quantity', function () {
    createHomeCatalog();

    PriceRule::query()->each(function (PriceRule $rule): void {
        $configuration = $rule->getAttribute('configuration');
        expect($configuration)->toBeArray();
        $configuration['multipliers_basis_points'] = ['100000' => 10_000];
        $rule->update(['configuration' => $configuration]);
    });

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'unavailable')
            ->missing('product'));
});

test('the homepage fails closed when a later legal quantity would overflow pricing', function () {
    createHomeCatalog();

    $fast = PriceRule::query()
        ->get()
        ->first(function (PriceRule $rule): bool {
            $configuration = $rule->getAttribute('configuration');

            return is_array($configuration) && ($configuration['group'] ?? null) === 'console_fast';
        });

    if (! $fast instanceof PriceRule) {
        throw new LogicException('The test catalog must contain a fast pricing rule.');
    }

    $fast->update([
        'configuration' => homeRuleConfiguration('console_fast', [
            'tier_rates_halalah_per_million' => array_fill(0, 6, 1_000_000_000),
        ]),
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'unavailable')
            ->missing('product'));
});

test('homepage props omit supplier market, policy proof, and credential values', function () {
    createHomeCatalog();

    $props = $this->get('/')->inertiaPage()['props'];
    $serialized = json_encode($props, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($serialized)
        ->not->toContain('"supplier_market"')
        ->not->toContain('current balance')
        ->not->toContain('"eaEmail"')
        ->not->toContain('"eaPassword"')
        ->not->toContain('"encrypted_payload"')
        ->not->toContain('"masked_summary"')
        ->not->toContain('checkout currency');
});

test('the quote schedule payload carries exactly the keys the storefront validates', function (): void {
    // resources/js/lib/coins-quote-schedule.ts checks this payload with an
    // EXACT key set and drops every schedule when it does not match, which the
    // storefront shows as "prices unavailable" across the whole page. Adding a
    // field server-side without adding it there once did precisely that, and
    // no test noticed because the frontend fixtures are written from the
    // frontend list. This is the pin that makes the two sides move together.
    $expected = [
        'delivery',
        'displayCurrency',
        'displayTotalsMinor',
        'market',
        'maximum',
        'minimum',
        'platform',
        'priceVersion',
        'pricedAt',
        'productId',
        'quantities',
        'totalsHalalah',
        'variantId',
    ];

    createHomeCatalog();

    $schedules = app(BuildCoinsQuoteSchedule::class)->executeHomepage('SAR');

    expect($schedules)->toHaveCount(3);

    foreach ($schedules as $group => $schedule) {
        $keys = array_keys($schedule);
        sort($keys);

        expect($keys)->toBe($expected, "the {$group} schedule no longer matches the storefront's key set");
    }
});

test('the storefront offers only what the last pricing run could source', function () {
    // FC27 opened with the whole PlayStation pool under a million coins while
    // the configured ceiling still said twenty. The ceiling is the store's own
    // limit and does not move; what a supplier can deliver is the run's to say.
    createHomeCatalog();

    PriceRun::query()->create([
        'run_id' => (string) Str::ulid(),
        'event_id' => (string) Str::ulid(),
        'status' => 'applied',
        'mode' => 'apply',
        'pricing_version' => 1,
        'payload' => [
            'observations' => [
                'availableCoins' => [
                    'console_normal' => 300_000,
                    'console_fast' => 500_000,
                    // Above the configured PC ceiling on purpose: a provider
                    // claiming fifty million does not widen what this store sells.
                    'pc' => 50_000_000,
                ],
            ],
        ],
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    $this->get('/en')->assertInertia(function (Assert $page): void {
        $platforms = collect($page->toArray()['props']['platforms']);
        $console = $platforms->firstWhere('value', 'playstation');
        $pc = $platforms->firstWhere('value', 'pc');
        $deliveries = collect($console['deliveries']);

        expect($console['maximum'])->toBe(20_000_000)
            ->and($console['available'])->toBe(500_000)
            ->and($deliveries->firstWhere('value', 'normal')['available'])->toBe(300_000)
            ->and($deliveries->firstWhere('value', 'fast')['available'])->toBe(500_000)
            // Clamped down to the configured ceiling, never up from it.
            ->and($pc['available'])->toBe($pc['maximum']);
    });
});

test('a run that reports no availability leaves the configured ceilings alone', function () {
    createHomeCatalog();

    $this->get('/en')->assertInertia(function (Assert $page): void {
        foreach ($page->toArray()['props']['platforms'] as $platform) {
            expect($platform['available'])->toBe($platform['maximum']);

            foreach ($platform['deliveries'] as $delivery) {
                expect($delivery['available'])->toBe($delivery['maximum']);
            }
        }
    });
});

test('a platform with no market at all comes off sale', function () {
    // Twice nothing is nothing. PC on 2026-09-17: FFT's pool empty, UTT's lots
    // empty, so the run published a ceiling of zero for it.
    createHomeCatalog();

    PriceRun::query()->create([
        'run_id' => (string) Str::ulid(),
        'event_id' => (string) Str::ulid(),
        'status' => 'applied',
        'mode' => 'apply',
        'pricing_version' => 1,
        'payload' => ['observations' => ['availableCoins' => [
            'console_normal' => 45_000,
            'console_fast' => 45_000,
            'pc' => 0,
        ]]],
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    $this->get('/en')->assertInertia(function (Assert $page): void {
        $platforms = collect($page->toArray()['props']['platforms']);

        expect($platforms->firstWhere('value', 'pc')['available'])->toBe(0)
            // Console keeps selling on what the run published for it.
            ->and($platforms->firstWhere('value', 'playstation')['available'])->toBe(45_000);
    });
});

test('the smallest order and the quick amounts are solved from money, not stored as coins', function () {
    // "Start at fifty thousand coins" was a third of a riyal last season and
    // forty riyals the morning after the season turned. The decision is about
    // money, so the money is what is stored and the coins are solved for.
    createHomeCatalog();

    PriceRule::query()->where('service_type', ServiceType::Coins->value)->delete();

    foreach (['console_normal', 'console_fast', 'pc'] as $group) {
        $configuration = homeRuleConfiguration($group);
        unset($configuration['multipliers_basis_points']);
        $configuration['multiplier_anchors_basis_points'] = [
            '5000' => 13_000,
            '50000' => 11_000,
            '1000000' => 10_000,
            '20000000' => 10_500,
        ];

        PriceRule::query()->create([
            'name' => "anchored {$group}",
            'service_type' => ServiceType::Coins,
            'platform' => null,
            'configuration' => $configuration,
            'is_active' => true,
        ]);
    }

    $this->get('/en')->assertInertia(function (Assert $page): void {
        $amount = $page->toArray()['props']['amount'];
        $catalog = app(CoinsCatalogReader::class);
        $rule = $catalog->pricingRules(['console_normal'])['console_normal'];
        $calculator = app(CoinsPriceCalculator::class);
        $unit = $amount['roundingUnit'];
        $costOf = fn (int $quantity): int => $calculator->calculate($rule, $quantity)->halalah();

        // The floor is the cheapest order still worth five riyals, and the
        // grain below it is not.
        expect($costOf($amount['minimum']))->toBeGreaterThanOrEqual(500);

        if ($unit <= $amount['minimum'] - $unit) {
            expect($costOf($amount['minimum'] - $unit))->toBeLessThan(500);
        }

        // Quick amounts climb, never repeat, and never sit below the floor.
        expect($amount['presets'])->not->toBeEmpty()
            ->and($amount['presets'])->toBe(array_values(array_unique($amount['presets'])))
            ->and($amount['presets'])->toBe(collect($amount['presets'])->sort()->values()->all())
            ->and(min($amount['presets']))->toBeGreaterThanOrEqual($amount['minimum']);

        // And the top one is the thousand-riyal rung the owner asked for.
        expect($costOf(max($amount['presets'])))->toBeGreaterThanOrEqual(100_000);
    });
});

test('a rule that cannot price an arbitrary quantity keeps the configured sizes', function () {
    // A threshold map refuses anything below its first entry, which is exactly
    // what "the smallest order worth five riyals" asks it. It must fall back,
    // not 500.
    createHomeCatalog();

    $this->get('/en')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where(
            'amount.minimum',
            app(CoinsCatalogReader::class)->quantityRules()->minimum(),
        ));
});
