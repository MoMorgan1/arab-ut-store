<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Feeds\MetaCatalogFeed;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Reads the feed back the way Meta does: a header row naming the columns, then
 * one row per item, keyed by the id column.
 *
 * @return array<string, array<string, string>>
 */
function metaCatalogRows(): array
{
    $response = test()->get('/feeds/meta-catalog.csv');
    $response->assertOk();

    $lines = array_values(array_filter(
        explode("\n", str_replace("\r\n", "\n", (string) $response->getContent())),
        static fn (string $line): bool => trim($line) !== '',
    ));

    $header = str_getcsv(array_shift($lines) ?? '');
    expect($header)->toBe(MetaCatalogFeed::COLUMNS);

    $rows = [];

    foreach ($lines as $line) {
        $row = array_combine($header, str_getcsv($line));
        $rows[$row['id']] = $row;
    }

    return $rows;
}

function challengeProduct(array $product = [], array $variant = []): ProductVariant
{
    $created = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'slug' => 'sbc-feed-test',
        'name_ar' => 'ترقية الذهبيات',
        'description_ar' => "نحل التحدي\nبحسابك",
        ...$product,
    ]);

    return ProductVariant::factory()->create([
        'product_id' => $created->id,
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'sku' => 'SBC-FEED-TEST-PS',
        'name_ar' => 'سوني / إكس بوكس',
        'price_halalah' => 1_500,
        ...$variant,
    ]);
}

it('lists a challenge by its SKU, at the price of a single completion', function () {
    challengeProduct();

    $row = metaCatalogRows()['SBC-FEED-TEST-PS'] ?? null;

    expect($row)->not->toBeNull()
        ->and($row['title'])->toBe('ترقية الذهبيات - سوني / إكس بوكس')
        // The newline in the stored description would break the row.
        ->and($row['description'])->toBe('نحل التحدي بحسابك')
        ->and($row['price'])->toBe('15.00 SAR')
        ->and($row['availability'])->toBe('in stock')
        ->and($row['condition'])->toBe('new')
        ->and($row['brand'])->toBe('Arab UT')
        ->and($row['item_group_id'])->toBe('sbc-feed-test')
        ->and($row['link'])->toEndWith('/sbc/sbc-feed-test')
        ->and($row['image_link'])->toStartWith('http')
        ->and($row['image_link'])->toContain('/images/store/services/sbc.webp');
});

it('prefers a sale price, because that is what the page charges', function () {
    challengeProduct(variant: ['sale_price_halalah' => 900]);

    expect(metaCatalogRows()['SBC-FEED-TEST-PS']['price'])->toBe('9.00 SAR');
});

it('advertises each manual service at its cheapest rung', function () {
    $rows = metaCatalogRows();

    expect($rows['MANUAL_RIVALS_PLAYSTATION']['price'])->toBe('110.00 SAR')
        ->and($rows['MANUAL_RIVALS_PLAYSTATION']['link'])->toEndWith('/rivals')
        ->and($rows['MANUAL_FUT_CHAMPIONS_PLAYSTATION']['price'])->toBe('100.00 SAR')
        ->and($rows['MANUAL_FUT_CHAMPIONS_PLAYSTATION']['link'])->toEndWith('/fut-champions');
});

it('leaves coins out while no published rate can price the smallest order', function () {
    $coins = array_filter(
        array_keys(metaCatalogRows()),
        static fn (string $id): bool => str_starts_with($id, 'FC27-COINS'),
    );

    expect($coins)->toBe([]);
});

it('never advertises what a customer cannot buy', function () {
    $hidden = challengeProduct(
        ['slug' => 'sbc-hidden', 'is_visible' => false],
        ['sku' => 'SBC-HIDDEN'],
    );
    $archived = challengeProduct(
        ['slug' => 'sbc-archived', 'archived_at' => now()],
        ['sku' => 'SBC-ARCHIVED'],
    );
    $adminHidden = challengeProduct(
        ['slug' => 'sbc-admin-hidden', 'admin_hidden_at' => now()],
        ['sku' => 'SBC-ADMIN-HIDDEN'],
    );
    $inactive = challengeProduct(
        ['slug' => 'sbc-inactive'],
        ['sku' => 'SBC-INACTIVE', 'is_active' => false],
    );
    $free = challengeProduct(
        ['slug' => 'sbc-unpriced'],
        ['sku' => 'SBC-UNPRICED', 'price_halalah' => 0],
    );

    $ids = array_keys(metaCatalogRows());

    expect($ids)->not->toContain($hidden->sku)
        ->and($ids)->not->toContain($archived->sku)
        ->and($ids)->not->toContain($adminHidden->sku)
        ->and($ids)->not->toContain($inactive->sku)
        ->and($ids)->not->toContain($free->sku);
});

it('names on the product page the same id the feed lists', function () {
    challengeProduct();

    $this->get('/sbc/sbc-feed-test')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.product.variants.0.sku', 'SBC-FEED-TEST-PS')
            ->etc());

    expect(metaCatalogRows())->toHaveKey('SBC-FEED-TEST-PS');
});

it('is served as a CSV that crawlers are told to skip', function () {
    $response = $this->get('/feeds/meta-catalog.csv');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex');
});

it('keeps the feed out of robots.txt', function () {
    $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /feeds/');
});
