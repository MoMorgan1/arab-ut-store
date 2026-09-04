<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\FaqEntry;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Extract the server-rendered JSON-LD block the same way a crawler would:
 * from the raw HTML, never from React.
 *
 * @return array<string, mixed>
 */
function structuredDataFor(string $html): array
{
    preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

    expect($matches[1] ?? null)->not->toBeNull('missing JSON-LD block');

    return json_decode($matches[1], associative: true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed>|null */
function graphNode(array $schema, string $type): ?array
{
    foreach ($schema['@graph'] ?? [] as $node) {
        if (($node['@type'] ?? null) === $type) {
            return $node;
        }
    }

    return null;
}

function createStructuredDataReview(
    string $name,
    int $rating,
    ?string $bodyAr = 'خدمة ممتازة',
    ?ServiceType $service = null,
): Review {
    return Review::create([
        'reviewer_name' => $name,
        'rating' => $rating,
        'body_ar' => $bodyAr,
        'body_en' => $bodyAr,
        'source' => 'customer',
        'source_key' => 'customer',
        'external_id' => 'structured-'.str()->random(8),
        'content_hash' => hash('sha256', $name),
        'is_visible' => true,
        'service_type' => $service?->value,
        'published_at' => now(),
    ]);
}

function createStructuredDataProduct(): Product
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'slug' => 'structured-pack',
        'name_ar' => 'حزمة منظمة',
        'name_en' => 'Structured Pack',
        'description_ar' => 'وصف عربي',
        'description_en' => 'English description',
        'is_visible' => true,
        'archived_at' => null,
    ]);

    ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'price_halalah' => 10_000,
        'configuration' => [],
        'is_active' => true,
    ]);

    return $product;
}

it('emits WebSite, rated OnlineStore, and FAQPage on the home page when reviews and FAQ exist', function () {
    createStructuredDataReview('Reviewer one', 5);
    createStructuredDataReview('Reviewer two', 4);

    $schema = structuredDataFor($this->get('/')->assertOk()->getContent());

    expect($schema['@context'] ?? null)->toBe('https://schema.org')
        ->and($schema)->toHaveKey('@graph');

    $website = graphNode($schema, 'WebSite');

    expect($website)->not->toBeNull()
        ->and($website['inLanguage'])->toBe('ar');

    $store = graphNode($schema, 'OnlineStore');

    expect($store)->not->toBeNull()
        ->and($store['aggregateRating'])->toMatchArray([
            'ratingValue' => 4.5,
            'reviewCount' => 2,
            'bestRating' => 5,
            'worstRating' => 1,
        ])
        ->and($store['review'] ?? [])->toHaveCount(2);

    $faq = graphNode($schema, 'FAQPage');

    expect($faq)->not->toBeNull()
        ->and($faq['mainEntity'] ?? [])->not->toBeEmpty();

    foreach ($faq['mainEntity'] as $question) {
        expect($question['@type'])->toBe('Question')
            ->and($question['acceptedAnswer']['@type'])->toBe('Answer')
            ->and($question['acceptedAnswer']['text'])->not->toContain('<');
    }
});

it('omits the rating and FAQ nodes on the home page when the tables are empty', function () {
    FaqEntry::query()->delete();

    $schema = structuredDataFor($this->get('/')->assertOk()->getContent());

    expect(graphNode($schema, 'WebSite'))->not->toBeNull()
        ->and(graphNode($schema, 'OnlineStore'))->not->toBeNull()
        ->and(graphNode($schema, 'OnlineStore')['aggregateRating'] ?? null)->toBeNull()
        ->and(graphNode($schema, 'FAQPage'))->toBeNull();
});

it('keeps service reviews on a Service node next to the Product on SBC product pages', function () {
    createStructuredDataProduct();

    $ratingOnly = (string) trans('store.reviews.rating_without_comment', [], 'ar');

    foreach (range(1, 6) as $index) {
        createStructuredDataReview("Commented {$index}", 5, "تعليق ممتاز {$index}", ServiceType::Sbc);
    }
    createStructuredDataReview('Silent one', 5, $ratingOnly, ServiceType::Sbc);
    createStructuredDataReview('Silent two', 4, $ratingOnly, ServiceType::Sbc);

    $schema = structuredDataFor($this->get('/sbc/structured-pack')->assertOk()->getContent());

    $product = graphNode($schema, 'Product');
    $service = graphNode($schema, 'Service');
    $store = graphNode($schema, 'OnlineStore');

    // Reviews are collected per service, so they must never be presented as
    // reviews of one product, and the site-wide store node stays unrated.
    expect($product)->not->toBeNull()
        ->and($product['offers']['price'] ?? null)->not->toBeNull()
        ->and($product)->not->toHaveKey('aggregateRating')
        ->and($store)->not->toHaveKey('aggregateRating')
        ->and($service)->not->toBeNull()
        ->and($service['name'])->toBe(trans('store.reviews.service_names.sbc'))
        ->and($service['aggregateRating']['reviewCount'])->toBe(8)
        ->and($service['review'] ?? [])->toHaveCount(5);

    foreach ($service['review'] as $review) {
        expect($review['@type'])->toBe('Review')
            ->and($review['author']['@type'])->toBe('Person')
            ->and($review['reviewBody'])->not->toBe($ratingOnly)
            ->and($review['reviewRating']['ratingValue'])->toBeGreaterThanOrEqual(4);
    }

    $breadcrumbs = graphNode($schema, 'BreadcrumbList');

    expect($breadcrumbs)->not->toBeNull()
        ->and(array_column($breadcrumbs['itemListElement'], 'name'))->toBe([
            trans('ui.home_title'),
            trans('store.services.sbc.title'),
            'حزمة منظمة',
        ]);

    foreach ($breadcrumbs['itemListElement'] as $position => $crumb) {
        expect($crumb['position'])->toBe($position + 1)
            ->and($crumb['item'])->toStartWith((string) config('app.url'));
    }
});

it('hangs the rating on a Service node on the Rivals page', function () {
    foreach (range(1, 3) as $index) {
        createStructuredDataReview("Rivals fan {$index}", 5, "رايفلز رائع {$index}", ServiceType::Rivals);
    }

    $schema = structuredDataFor($this->get('/rivals')->assertOk()->getContent());

    $service = graphNode($schema, 'Service');

    expect($service)->not->toBeNull()
        ->and($service['areaServed'])->toBe('SA')
        ->and($service['provider']['name'])->toBe(trans('store.seo_brand'))
        ->and($service['aggregateRating']['reviewCount'])->toBe(3)
        ->and(graphNode($schema, 'Product'))->toBeNull();

    $breadcrumbs = graphNode($schema, 'BreadcrumbList');

    expect($breadcrumbs)->not->toBeNull()
        ->and($breadcrumbs['itemListElement'])->toHaveCount(2);
});

it('moves a service-filtered /reviews rating onto a Service node that matches the page', function () {
    createStructuredDataReview('Rivals fan', 5, 'رايفلز رائع', ServiceType::Rivals);
    createStructuredDataReview('SBC fan', 3, 'جيد', ServiceType::Sbc);
    createStructuredDataReview('SBC fan two', 3, 'جيد جدا', ServiceType::Sbc);

    $schema = structuredDataFor($this->get('/reviews?service=rivals')->assertOk()->getContent());

    $service = graphNode($schema, 'Service');

    expect(graphNode($schema, 'OnlineStore'))->not->toHaveKey('aggregateRating')
        ->and($service['aggregateRating']['reviewCount'])->toBe(1)
        ->and($service['review'])->toHaveCount(1)
        ->and($service['review'][0]['reviewBody'])->toBe('رايفلز رائع');
});

it('reports the unfiltered totals on /reviews even when a rating filter is active', function () {
    createStructuredDataReview('Five a', 5);
    createStructuredDataReview('Five b', 5);
    createStructuredDataReview('Four a', 4);

    $schema = structuredDataFor($this->get('/reviews?rating=5')->assertOk()->getContent());
    $store = graphNode($schema, 'OnlineStore');

    expect($store['aggregateRating'])->toMatchArray([
        'ratingValue' => 4.7,
        'reviewCount' => 3,
    ]);
});

it('lists the WhatsApp line in sameAs and keeps @context exactly once', function () {
    $html = $this->get('/')->assertOk()->getContent();
    $schema = structuredDataFor($html);

    preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

    // Inertia's data-page payload repeats the schema for the client; the
    // crawler-facing script block itself must carry @context exactly once.
    expect(substr_count($matches[1], '@context'))->toBe(1)
        ->and($schema)->toHaveKey('@graph')
        ->and(graphNode($schema, 'OnlineStore')['sameAs'])->toContain((string) config('store.support.whatsapp_url'));
});

it('serves canonical and hreflang on the sitemap page alongside its groups', function () {
    createStructuredDataProduct();

    $this->get('/sitemap')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/sitemap', false)
            ->where('sitemapPage.title', 'خريطة الموقع')
            ->where('sitemapPage.eyebrow', 'كل روابط المتجر')
            ->where('sitemapPage.groups.0.heading', 'الخدمات')
            ->where('sitemapPage.groups.1.heading', 'المتجر')
            ->where('sitemapPage.groups.2.heading', 'السياسات')
            ->where('sitemapPage.groups.0.links.5.href', '/sbc/structured-pack')
            ->where('sitemapPage.groups.0.links.5.label', 'حزمة منظمة'));

    $html = $this->get('/sitemap')->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="canonical" href="'.url('/sitemap').'"')
        ->toContain('hreflang="en"')
        ->toContain('hreflang="x-default"');

    $english = $this->get('/en/sitemap')->assertOk()->getContent();

    expect($english)
        ->toContain('<link rel="canonical" href="'.url('/en/sitemap').'"')
        ->toContain('hreflang="ar"');
});
