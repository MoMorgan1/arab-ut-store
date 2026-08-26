<?php

declare(strict_types=1);

use App\Support\Seo\StorePageSeo;

it('builds a Product schema carrying an offer with a plain decimal price', function () {
    $seo = StorePageSeo::fromCatalogProduct([
        'name' => 'SBC Pack',
        'description' => 'A pack.',
        'image' => ['url' => '/images/store/catalog/pack.webp'],
        'price' => ['amountMinor' => 12550, 'currency' => 'SAR'],
        'variants' => [],
    ])->toArray();

    expect($seo['type'])->toBe('product')
        ->and($seo['image'])->toBe(config('app.url').'/images/store/catalog/pack.webp')
        ->and($seo['schema']['@type'])->toBe('Product')
        ->and($seo['schema']['offers'])->toMatchArray([
            '@type' => 'Offer',
            'price' => '125.50',
            'priceCurrency' => 'SAR',
            'availability' => 'https://schema.org/InStock',
        ]);
});

it('advertises the cheapest variant when a product has no headline price', function () {
    $seo = StorePageSeo::fromCatalogProduct([
        'name' => 'Tiered Pack',
        'description' => null,
        'image' => null,
        'price' => null,
        'variants' => [
            ['price' => ['amountMinor' => 9000, 'currency' => 'SAR']],
            ['price' => ['amountMinor' => 4500, 'currency' => 'SAR']],
            ['price' => null],
        ],
    ])->toArray();

    expect($seo['schema']['offers']['price'])->toBe('45.00');
});

it('renders prices search engines can parse rather than localised currency', function () {
    app()->setLocale('ar');

    $price = StorePageSeo::fromCatalogProduct([
        'name' => 'حزمة',
        'description' => null,
        'image' => null,
        'price' => ['amountMinor' => 900, 'currency' => 'SAR'],
        'variants' => [],
    ])->toArray()['schema']['offers']['price'];

    // Arabic-Indic digits or an embedded currency code would invalidate the offer.
    expect($price)->toBe('9.00')->toMatch('/^\d+\.\d{2}$/');
});

it('degrades to the store schema when a product has no price at all', function () {
    // A Product node without `offers` is rejected by Google, so it must never
    // be emitted — describing the store instead is the safe degradation.
    $seo = StorePageSeo::fromCatalogProduct([
        'name' => 'Unpriced',
        'description' => null,
        'image' => null,
        'price' => null,
        'variants' => [],
    ])->toArray();

    expect($seo['schema']['@type'])->toBe('OnlineStore')
        ->and($seo['schema'])->not->toHaveKey('offers')
        ->and($seo['type'])->toBe('website');
});

it('falls back to the brand logo when a product has no image', function () {
    $seo = StorePageSeo::fromCatalogProduct([
        'name' => 'Unpictured',
        'description' => null,
        'image' => null,
        'price' => ['amountMinor' => 100, 'currency' => 'SAR'],
        'variants' => [],
    ])->toArray();

    expect($seo['image'])->toBe(config('app.url').config('store.seo.share_image'));
});

it('falls back to a share image large enough for a rich preview', function () {
    // A 96x68 logo is below every scraper's minimum and renders as a tiny
    // icon instead of a card, which is what this guards against.
    $path = public_path((string) config('store.seo.share_image'));

    expect(file_exists($path))->toBeTrue();

    [$width, $height] = getimagesize($path);

    expect($width)->toBeGreaterThanOrEqual(300)
        ->and($height)->toBeGreaterThanOrEqual(200);
});

it('leaves an already absolute image URL untouched', function () {
    $seo = StorePageSeo::fromCatalogProduct([
        'name' => 'Remote',
        'description' => null,
        'image' => ['url' => 'https://cdn.example.com/pack.webp'],
        'price' => ['amountMinor' => 100, 'currency' => 'SAR'],
        'variants' => [],
    ])->toArray();

    expect($seo['image'])->toBe('https://cdn.example.com/pack.webp');
});
