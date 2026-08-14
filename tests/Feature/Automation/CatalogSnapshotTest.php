<?php

use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Models\CatalogSource;
use App\Models\CatalogSyncRun;
use App\Models\Category;
use App\Models\IntegrationEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

function catalogSnapshotPayload(): array
{
    return [
        'schemaVersion' => 1,
        'eventId' => (string) Str::ulid(),
        'runId' => (string) Str::ulid(),
        'generatedAt' => now()->utc()->toISOString(),
        'completeSnapshot' => true,
        'categories' => [[
            'externalId' => 'sbc',
            'slug' => 'sbc',
            'name' => ['ar' => 'تحديات بناء التشكيلات', 'en' => 'SBCs'],
            'description' => ['ar' => 'خدمات SBC', 'en' => 'SBC services'],
            'sortOrder' => 10,
            'visible' => true,
        ]],
        'products' => [[
            'externalId' => 'sbc-icon-pick',
            'categoryExternalId' => 'sbc',
            'slug' => 'icon-player-pick',
            'serviceType' => 'sbc',
            'name' => ['ar' => 'اختيار لاعب أيقونة', 'en' => 'Icon Player Pick'],
            'description' => ['ar' => 'تنفيذ تحدي الأيقونة', 'en' => 'Complete the Icon SBC'],
            'sortOrder' => 20,
            'visible' => true,
            'variants' => [[
                'externalId' => 'sbc-icon-pick-playstation',
                'sku' => 'SBC_ICON_PICK_PS',
                'platform' => 'playstation',
                'market' => 'console',
                'currency' => 'SAR',
                'name' => ['ar' => 'بلايستيشن', 'en' => 'PlayStation'],
                'priceMinor' => 12_500,
                'salePriceMinor' => null,
                'priceVersion' => 3,
                'active' => true,
                'configuration' => [
                    'sbcCategory' => 'icons',
                    'completionPricing' => [
                        'version' => 1,
                        'repeatable' => false,
                        'maximum' => 1,
                        'tiers' => [[
                            'completions' => 1,
                            'multiplierBps' => 10_000,
                            'totalMinor' => 12_500,
                        ]],
                    ],
                ],
            ]],
            'media' => [],
        ]],
    ];
}

function signedCatalogSnapshot(
    array $payload,
    ?string $signature = null,
    ?string $timestamp = null,
    ?string $eventHeader = null,
    bool $configureCredentials = true,
    string $path = '/api/automation/v1/catalog/snapshots',
    string $credentialScope = 'catalog',
) {
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $signedAt = $timestamp ?? (string) now()->timestamp;
    $signedEvent = $eventHeader ?? $payload['eventId'];
    $secret = $credentialScope === 'sbc_catalog'
        ? 'sbc-catalog-test-secret'
        : 'catalog-test-secret';
    $key = $credentialScope === 'sbc_catalog'
        ? 'sbc-catalog-test-key'
        : 'catalog-test-key';

    if ($configureCredentials) {
        config()->set("services.n8n.{$credentialScope}_key", $key);
        config()->set("services.n8n.{$credentialScope}_secret", $secret);
    }

    $canonical = $credentialScope === 'sbc_catalog'
        ? $signedAt."\n".$signedEvent."\n".'n8n-sbc'."\n".$body
        : $signedAt."\n".$signedEvent."\n".$body;

    return test()->call(
        'POST',
        $path,
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ARABUT_KEY' => $key,
            'HTTP_X_ARABUT_TIMESTAMP' => $signedAt,
            'HTTP_X_ARABUT_EVENT' => $signedEvent,
            'HTTP_X_ARABUT_SIGNATURE' => $signature ?? hash_hmac(
                'sha256',
                $canonical,
                $secret,
            ),
        ],
        content: $body,
    );
}

it('commits one fresh signed complete catalog snapshot', function () {
    $payload = catalogSnapshotPayload();

    $response = signedCatalogSnapshot($payload)
        ->assertCreated()
        ->assertExactJson([
            'data' => [
                'runId' => $payload['runId'],
                'status' => 'completed',
                'applied' => 1,
                'archived' => 0,
            ],
        ]);

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(CatalogSyncRun::sole()->run_id)->toBe($payload['runId'])
        ->and(Product::sole()->external_id)->toBe('sbc-icon-pick')
        ->and(Product::sole()->source->key)->toBe('n8n-products')
        ->and(ProductVariant::sole()->price_halalah)->toBe(12_500);
});

it('rejects non SBC products on the SBC scoped snapshot route', function () {
    $payload = catalogSnapshotPayload();
    $payload['products'][0]['serviceType'] = ServiceType::Objectives->value;

    signedCatalogSnapshot(
        $payload,
        path: '/api/automation/v1/catalog/sbc/snapshots',
        credentialScope: 'sbc_catalog',
    )->assertUnprocessable()->assertJsonValidationErrors(['products.0.serviceType']);

    expect(Product::count())->toBe(0)
        ->and(CatalogSource::count())->toBe(0)
        ->and(CatalogSyncRun::count())->toBe(0);
});

it('reconciles an SBC complete snapshot only inside the n8n SBC source', function () {
    $sharedSource = CatalogSource::factory()->create([
        'key' => 'n8n-products',
        'name' => 'n8n Products',
        'authority' => ProductAuthority::Automation,
    ]);
    $sharedCategory = Category::factory()->create([
        'source_id' => $sharedSource->id,
        'external_id' => 'sbc',
        'slug' => 'shared-category',
    ]);
    $sharedProduct = Product::factory()->create([
        'source_id' => $sharedSource->id,
        'external_id' => 'sbc-icon-pick',
        'category_id' => $sharedCategory->id,
        'slug' => 'shared-product',
        'authority' => ProductAuthority::Automation,
    ]);
    $sharedVariant = ProductVariant::factory()->create([
        'source_id' => $sharedSource->id,
        'external_id' => 'sbc-icon-pick-playstation',
        'product_id' => $sharedProduct->id,
        'sku' => 'SHARED_VARIANT',
        'authority' => ProductAuthority::Automation,
    ]);
    $sharedCategory->timestamps = false;
    $sharedCategory->forceFill(['updated_at' => now()->subDays(3)])->save();
    $sharedProduct->timestamps = false;
    $sharedProduct->forceFill(['updated_at' => now()->subDays(3)])->save();
    $sharedVariant->timestamps = false;
    $sharedVariant->forceFill(['updated_at' => now()->subDays(3)])->save();
    $sharedCategoryBefore = $sharedCategory->fresh()->getRawOriginal();
    $sharedProductBefore = $sharedProduct->fresh()->getRawOriginal();
    $sharedVariantBefore = $sharedVariant->fresh()->getRawOriginal();

    $sbcSource = CatalogSource::factory()->create([
        'key' => 'n8n-sbc',
        'name' => 'n8n SBC',
        'authority' => ProductAuthority::Automation,
    ]);
    $staleSbcCategory = Category::factory()->create([
        'source_id' => $sbcSource->id,
        'external_id' => 'stale-sbc-category',
        'slug' => 'stale-sbc-category',
    ]);
    $staleSbcProduct = Product::factory()->create([
        'source_id' => $sbcSource->id,
        'external_id' => 'stale-sbc-product',
        'category_id' => $staleSbcCategory->id,
        'slug' => 'stale-sbc-product',
        'authority' => ProductAuthority::Automation,
    ]);
    $staleSbcVariant = ProductVariant::factory()->create([
        'source_id' => $sbcSource->id,
        'external_id' => 'stale-sbc-variant',
        'product_id' => $staleSbcProduct->id,
        'sku' => 'STALE_SBC_VARIANT',
        'authority' => ProductAuthority::Automation,
    ]);

    signedCatalogSnapshot(
        catalogSnapshotPayload(),
        path: '/api/automation/v1/catalog/sbc/snapshots',
        credentialScope: 'sbc_catalog',
    )->assertCreated()->assertJsonPath('data.archived', 1);

    expect($sharedCategory->fresh()->getRawOriginal())->toBe($sharedCategoryBefore)
        ->and($sharedProduct->fresh()->getRawOriginal())->toBe($sharedProductBefore)
        ->and($sharedVariant->fresh()->getRawOriginal())->toBe($sharedVariantBefore)
        ->and($sharedCategory->fresh()->is_visible)->toBeTrue()
        ->and($sharedProduct->fresh()->archived_at)->toBeNull()
        ->and($sharedProduct->fresh()->is_visible)->toBeTrue()
        ->and($sharedVariant->fresh()->is_active)->toBeTrue()
        ->and($staleSbcCategory->fresh()->is_visible)->toBeFalse()
        ->and($staleSbcProduct->fresh()->archived_at)->not->toBeNull()
        ->and($staleSbcVariant->fresh()->is_active)->toBeFalse()
        ->and(Product::query()
            ->where('source_id', $sbcSource->id)
            ->where('external_id', 'sbc-icon-pick')
            ->sole()->source->key)->toBe('n8n-sbc');
});

it('rejects a generic catalog signature at the SBC snapshot route', function () {
    config()->set('services.n8n.sbc_catalog_key', 'sbc-catalog-test-key');
    config()->set('services.n8n.sbc_catalog_secret', 'sbc-catalog-test-secret');

    signedCatalogSnapshot(
        catalogSnapshotPayload(),
        path: '/api/automation/v1/catalog/sbc/snapshots',
    )->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');

    expect(Product::count())->toBe(0)
        ->and(CatalogSyncRun::count())->toBe(0);
});

it('rejects an SBC catalog signature at the generic snapshot route', function () {
    config()->set('services.n8n.catalog_key', 'catalog-test-key');
    config()->set('services.n8n.catalog_secret', 'catalog-test-secret');

    signedCatalogSnapshot(
        catalogSnapshotPayload(),
        credentialScope: 'sbc_catalog',
    )->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');

    expect(Product::count())->toBe(0)
        ->and(CatalogSyncRun::count())->toBe(0);
});

it('domain separates the SBC signature even when both routes are misconfigured with the same credentials', function () {
    config()->set('services.n8n.sbc_catalog_key', 'catalog-test-key');
    config()->set('services.n8n.sbc_catalog_secret', 'catalog-test-secret');

    signedCatalogSnapshot(
        catalogSnapshotPayload(),
        path: '/api/automation/v1/catalog/sbc/snapshots',
    )->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');

    expect(Product::count())->toBe(0);
});

it('fails closed when SBC catalog signing credentials are not configured', function () {
    config()->set('services.n8n.sbc_catalog_key');
    config()->set('services.n8n.sbc_catalog_secret');

    signedCatalogSnapshot(
        catalogSnapshotPayload(),
        configureCredentials: false,
        path: '/api/automation/v1/catalog/sbc/snapshots',
        credentialScope: 'sbc_catalog',
    )->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');

    expect(Product::count())->toBe(0);
});

it('rejects replay on the SBC route without applying a second snapshot', function () {
    $payload = catalogSnapshotPayload();

    signedCatalogSnapshot(
        $payload,
        path: '/api/automation/v1/catalog/sbc/snapshots',
        credentialScope: 'sbc_catalog',
    )->assertCreated();

    signedCatalogSnapshot(
        $payload,
        path: '/api/automation/v1/catalog/sbc/snapshots',
        credentialScope: 'sbc_catalog',
    )->assertConflict()->assertJsonPath('error.code', 'catalog_snapshot_replayed');

    expect(CatalogSyncRun::count())->toBe(1)
        ->and(IntegrationEvent::sole()->aggregate_id)->toBe('n8n-sbc');
});

it('rejects a committed generic event when it is replayed with valid SBC credentials', function () {
    $payload = catalogSnapshotPayload();

    signedCatalogSnapshot($payload)->assertCreated();

    signedCatalogSnapshot(
        $payload,
        path: '/api/automation/v1/catalog/sbc/snapshots',
        credentialScope: 'sbc_catalog',
    )->assertConflict()->assertJsonPath('error.code', 'catalog_snapshot_replayed');

    expect(CatalogSyncRun::count())->toBe(1)
        ->and(IntegrationEvent::sole()->aggregate_id)->toBe('n8n-products')
        ->and(CatalogSource::query()->where('key', 'n8n-sbc')->exists())->toBeFalse();
});

it('rejects a committed SBC event when it is replayed with valid generic credentials', function () {
    $payload = catalogSnapshotPayload();

    signedCatalogSnapshot(
        $payload,
        path: '/api/automation/v1/catalog/sbc/snapshots',
        credentialScope: 'sbc_catalog',
    )->assertCreated();

    signedCatalogSnapshot($payload)
        ->assertConflict()->assertJsonPath('error.code', 'catalog_snapshot_replayed');

    expect(CatalogSyncRun::count())->toBe(1)
        ->and(IntegrationEvent::sole()->aggregate_id)->toBe('n8n-sbc')
        ->and(CatalogSource::query()->where('key', 'n8n-products')->exists())->toBeFalse();
});

it('keeps price versions server authoritative across catalog routes', function (
    string $path,
    string $credentialScope,
    int $initialProducerVersion,
    int $nextProducerVersion,
    int $nextPrice,
    ?int $nextSalePrice,
    int $expectedVersion,
) {
    $initial = catalogSnapshotPayload();
    $initial['products'][0]['variants'][0]['priceVersion'] = $initialProducerVersion;

    signedCatalogSnapshot(
        $initial,
        path: $path,
        credentialScope: $credentialScope,
    )->assertCreated();

    expect(ProductVariant::sole()->price_version)->toBe(1);

    $next = catalogSnapshotPayload();
    $next['products'][0]['variants'][0]['priceVersion'] = $nextProducerVersion;
    $next['products'][0]['variants'][0]['priceMinor'] = $nextPrice;
    $next['products'][0]['variants'][0]['salePriceMinor'] = $nextSalePrice;
    $next['products'][0]['variants'][0]['configuration']['completionPricing']['tiers'][0]['totalMinor'] = $nextSalePrice ?? $nextPrice;
    $next['products'][0]['variants'][0]['name']['en'] = 'Updated name';

    signedCatalogSnapshot(
        $next,
        path: $path,
        credentialScope: $credentialScope,
    )->assertCreated();

    $variant = ProductVariant::sole();

    expect($variant->price_version)->toBe($expectedVersion)
        ->and($variant->name_en)->toBe('Updated name');
})->with([
    'generic unchanged producer jump' => ['/api/automation/v1/catalog/snapshots', 'catalog', 900, 9_999, 12_500, null, 1],
    'generic price change producer regression' => ['/api/automation/v1/catalog/snapshots', 'catalog', 900, 1, 13_000, null, 2],
    'generic sale change producer jump' => ['/api/automation/v1/catalog/snapshots', 'catalog', 1, 9_999, 12_500, 11_500, 2],
    'SBC unchanged producer regression' => ['/api/automation/v1/catalog/sbc/snapshots', 'sbc_catalog', 900, 1, 12_500, null, 1],
    'SBC price change producer jump' => ['/api/automation/v1/catalog/sbc/snapshots', 'sbc_catalog', 1, 9_999, 13_000, null, 2],
    'SBC sale change producer regression' => ['/api/automation/v1/catalog/sbc/snapshots', 'sbc_catalog', 900, 1, 12_500, 11_500, 2],
    'SBC regular and sale change increment only once' => ['/api/automation/v1/catalog/sbc/snapshots', 'sbc_catalog', 1, 9_999, 13_000, 11_500, 2],
]);

it('increments an SBC price version when a non-default completion tier changes', function () {
    $initial = catalogSnapshotPayload();
    $initial['products'][0]['variants'][0]['priceMinor'] = 57_000;
    $initial['products'][0]['variants'][0]['configuration']['completionPricing'] = [
        'version' => 1,
        'repeatable' => true,
        'maximum' => 10,
        'tiers' => [
            ['completions' => 5, 'multiplierBps' => 10_000, 'totalMinor' => 57_000],
            ['completions' => 10, 'multiplierBps' => 9_500, 'totalMinor' => 107_900],
        ],
    ];

    signedCatalogSnapshot(
        $initial,
        path: '/api/automation/v1/catalog/sbc/snapshots',
        credentialScope: 'sbc_catalog',
    )->assertCreated();

    $metadataOnly = catalogSnapshotPayload();
    $metadataOnly['products'][0]['variants'][0]['priceMinor'] = 57_000;
    $metadataOnly['products'][0]['variants'][0]['configuration'] = [
        ...$initial['products'][0]['variants'][0]['configuration'],
        'expiresAt' => '2026-08-20T00:00:00Z',
    ];
    signedCatalogSnapshot(
        $metadataOnly,
        path: '/api/automation/v1/catalog/sbc/snapshots',
        credentialScope: 'sbc_catalog',
    )->assertCreated();
    expect(ProductVariant::sole()->price_version)->toBe(1);

    $changedTier = catalogSnapshotPayload();
    $changedTier['products'][0]['variants'][0]['priceMinor'] = 57_000;
    $changedTier['products'][0]['variants'][0]['configuration']['completionPricing'] = [
        ...$initial['products'][0]['variants'][0]['configuration']['completionPricing'],
        'tiers' => [
            ['completions' => 5, 'multiplierBps' => 10_000, 'totalMinor' => 57_000],
            ['completions' => 10, 'multiplierBps' => 9_500, 'totalMinor' => 108_000],
        ],
    ];
    signedCatalogSnapshot(
        $changedTier,
        path: '/api/automation/v1/catalog/sbc/snapshots',
        credentialScope: 'sbc_catalog',
    )->assertCreated();

    expect(ProductVariant::sole()->price_version)->toBe(2);
});

it('rejects malformed SBC completion pricing before writing any snapshot rows', function () {
    $payload = catalogSnapshotPayload();
    $payload['products'][0]['variants'][0]['configuration']['completionPricing']['tiers'][0]['unexpected'] = true;

    signedCatalogSnapshot(
        $payload,
        path: '/api/automation/v1/catalog/sbc/snapshots',
        credentialScope: 'sbc_catalog',
    )->assertUnprocessable()->assertJsonValidationErrors([
        'products.0.variants.0.configuration.completionPricing',
    ]);

    expect(Product::count())->toBe(0)
        ->and(ProductVariant::count())->toBe(0)
        ->and(CatalogSyncRun::count())->toBe(0)
        ->and(IntegrationEvent::count())->toBe(0);
});

it('rejects invalid catalog signatures without writing catalog rows', function () {
    $response = signedCatalogSnapshot(catalogSnapshotPayload(), str_repeat('0', 64))
        ->assertUnauthorized();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Product::count())->toBe(0)
        ->and(CatalogSyncRun::count())->toBe(0);
});

it('fails closed when catalog signing credentials are not configured', function () {
    config()->set('services.n8n.catalog_key');
    config()->set('services.n8n.catalog_secret');

    $response = signedCatalogSnapshot(
        catalogSnapshotPayload(),
        configureCredentials: false,
    );

    $response->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');

    expect(Product::count())->toBe(0);
});

it('rejects a correctly signed snapshot outside the five minute freshness window', function () {
    $response = signedCatalogSnapshot(
        catalogSnapshotPayload(),
        timestamp: (string) now()->subMinutes(6)->timestamp,
    );

    $response->assertConflict()->assertJsonPath('error.code', 'stale_snapshot');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Product::count())->toBe(0);
});

it('rejects a signed header event that differs from the body event', function () {
    $response = signedCatalogSnapshot(
        catalogSnapshotPayload(),
        eventHeader: (string) Str::ulid(),
    );

    $response->assertUnprocessable()->assertJsonValidationErrors(['eventId']);

    expect(Product::count())->toBe(0);
});

it('rejects replay of an already committed event and run', function () {
    $payload = catalogSnapshotPayload();

    signedCatalogSnapshot($payload)->assertCreated();
    $response = signedCatalogSnapshot($payload);

    $response->assertConflict()->assertJsonPath('error.code', 'catalog_snapshot_replayed');

    expect(Product::count())->toBe(1)
        ->and(CatalogSyncRun::count())->toBe(1);
});

it('rejects partial and undeclared snapshot fields before writing', function (string $case) {
    $payload = catalogSnapshotPayload();

    if ($case === 'partial') {
        $payload['completeSnapshot'] = false;
    } else {
        $payload['unexpected'] = 'not-allowed';
    }

    signedCatalogSnapshot($payload)->assertUnprocessable();

    expect(Product::count())->toBe(0)
        ->and(CatalogSyncRun::count())->toBe(0);
})->with(['partial', 'undeclared-field']);

it('rejects semantically inconsistent complete snapshot fields', function (string $case) {
    $payload = catalogSnapshotPayload();

    match ($case) {
        'non-boolean-complete' => $payload['completeSnapshot'] = 1,
        'stale-body-time' => $payload['generatedAt'] = now()->subMinutes(6)->utc()->toISOString(),
        'unknown-category' => $payload['products'][0]['categoryExternalId'] = 'missing-category',
        'wrong-market' => $payload['products'][0]['variants'][0]['market'] = 'pc',
    };

    signedCatalogSnapshot($payload)->assertUnprocessable();

    expect(Product::count())->toBe(0)
        ->and(CatalogSyncRun::count())->toBe(0);
})->with([
    'non-boolean-complete',
    'stale-body-time',
    'unknown-category',
    'wrong-market',
]);
