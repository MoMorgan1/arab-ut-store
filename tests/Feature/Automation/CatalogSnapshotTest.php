<?php

use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Models\CatalogSource;
use App\Models\CatalogSyncRun;
use App\Models\Category;
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
                'configuration' => ['sbcCategory' => 'icons'],
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
) {
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $signedAt = $timestamp ?? (string) now()->timestamp;
    $signedEvent = $eventHeader ?? $payload['eventId'];
    $secret = 'catalog-test-secret';

    if ($configureCredentials) {
        config()->set('services.n8n.catalog_key', 'catalog-test-key');
        config()->set('services.n8n.catalog_secret', $secret);
    }

    return test()->call(
        'POST',
        $path,
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ARABUT_KEY' => 'catalog-test-key',
            'HTTP_X_ARABUT_TIMESTAMP' => $signedAt,
            'HTTP_X_ARABUT_EVENT' => $signedEvent,
            'HTTP_X_ARABUT_SIGNATURE' => $signature ?? hash_hmac(
                'sha256',
                $signedAt."\n".$signedEvent."\n".$body,
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
        'external_id' => 'shared-category',
        'slug' => 'shared-category',
    ]);
    $sharedProduct = Product::factory()->create([
        'source_id' => $sharedSource->id,
        'external_id' => 'shared-product',
        'category_id' => $sharedCategory->id,
        'slug' => 'shared-product',
        'authority' => ProductAuthority::Automation,
    ]);
    $sharedVariant = ProductVariant::factory()->create([
        'source_id' => $sharedSource->id,
        'external_id' => 'shared-variant',
        'product_id' => $sharedProduct->id,
        'sku' => 'SHARED_VARIANT',
        'authority' => ProductAuthority::Automation,
    ]);

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
    )->assertCreated()->assertJsonPath('data.archived', 1);

    expect($sharedCategory->fresh()->is_visible)->toBeTrue()
        ->and($sharedProduct->fresh()->archived_at)->toBeNull()
        ->and($sharedProduct->fresh()->is_visible)->toBeTrue()
        ->and($sharedVariant->fresh()->is_active)->toBeTrue()
        ->and($staleSbcCategory->fresh()->is_visible)->toBeFalse()
        ->and($staleSbcProduct->fresh()->archived_at)->not->toBeNull()
        ->and($staleSbcVariant->fresh()->is_active)->toBeFalse()
        ->and(Product::query()->where('external_id', 'sbc-icon-pick')->sole()->source->key)->toBe('n8n-sbc');
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
