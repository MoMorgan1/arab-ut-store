<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\Product;
use App\Models\ProductVariant;

it('serves well-formed XML at /sitemap.xml', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $xml = simplexml_load_string($response->getContent());

    expect($xml)->not->toBeFalse()
        ->and($xml->getName())->toBe('urlset');
});

it('lists every static storefront page', function () {
    $content = $this->get('/sitemap.xml')->assertOk()->getContent();

    foreach (['/sbc', '/objectives', '/fut-champions', '/rivals', '/reviews', '/sitemap', '/privacy', '/terms'] as $path) {
        expect($content)->toContain('<loc>'.url($path).'</loc>');
    }

    expect($content)->toContain('<loc>'.url('/').'</loc>');
});

it('pairs each entry with both locale alternates', function () {
    $content = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($content)
        ->toContain('hreflang="ar" href="'.url('/sbc').'"')
        ->toContain('hreflang="en" href="'.url('/en/sbc').'"')
        ->toContain('hreflang="x-default"');
});

it('omits private and transactional routes', function () {
    $content = $this->get('/sitemap.xml')->assertOk()->getContent();

    foreach (['/cart', '/login', '/admin', '/account'] as $path) {
        expect($content)->not->toContain('<loc>'.url($path).'</loc>');
    }
});

it('includes a visible catalog product and excludes a hidden one', function () {
    $visible = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'slug' => 'visible-pack',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    ProductVariant::factory()->create(['product_id' => $visible->id, 'is_active' => true]);

    $hidden = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'slug' => 'hidden-pack',
        'is_visible' => false,
        'archived_at' => null,
    ]);
    ProductVariant::factory()->create(['product_id' => $hidden->id, 'is_active' => true]);

    $content = $this->get('/sitemap.xml')->assertOk()->getContent();

    // The sitemap must never advertise a product a visitor cannot open.
    expect($content)->toContain('visible-pack')
        ->and($content)->not->toContain('hidden-pack');
});

it('advertises the sitemap and protects private paths in robots.txt', function () {
    config(['app.url' => 'https://example.test']);

    $response = $this->get('/robots.txt');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    $robots = $response->getContent();

    expect($robots)
        ->toContain('Sitemap: https://example.test/sitemap.xml')
        ->toContain('Disallow: /admin')
        ->toContain('Disallow: /account')
        // The /ar prefix duplicates the canonical unprefixed Arabic pages.
        ->toContain('Disallow: /ar/');
});
