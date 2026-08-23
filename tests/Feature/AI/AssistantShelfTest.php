<?php

declare(strict_types=1);

use App\Actions\AI\BuildAssistantShelf;
use App\Actions\AI\BuildSbcSuggestions;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // The shelf is cached per locale, so each case starts from a clean read.
    Cache::flush();
});

function shelfSbcProduct(string $slug, int $priceHalalah = 900): Product
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'slug' => $slug,
        'name_ar' => "تحدي {$slug}",
        'name_en' => "Challenge {$slug}",
        'is_visible' => true,
        'archived_at' => null,
    ]);

    // The shelf is a visual chooser, so a challenge with no artwork is left
    // off it rather than shown as a blank tile.
    ProductMedia::query()->create([
        'product_id' => $product->getKey(),
        'disk' => 'public',
        'path' => "catalog/{$slug}.png",
        'alt_ar' => '',
        'alt_en' => '',
        'sort_order' => 0,
    ]);

    ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'price_halalah' => $priceHalalah,
        'sale_price_halalah' => null,
        'is_active' => true,
    ]);

    return $product;
}

test('an SBC question earns a shelf of real challenges', function () {
    shelfSbcProduct('one');
    shelfSbcProduct('two');

    $shelf = app(BuildAssistantShelf::class)->execute('كم سعر التحديات', 'ar');

    expect($shelf)->toHaveCount(2)
        ->and(array_column($shelf, 'id'))->toContain('one', 'two')
        ->and($shelf[0]['title'])->not->toBe('')
        ->and($shelf[0]['url'])->toContain('/sbc/');
});

test('the shelf stops at five so it stays swipeable', function () {
    foreach (range(1, 8) as $i) {
        shelfSbcProduct("sbc-{$i}");
    }

    expect(app(BuildSbcSuggestions::class)->execute('ar'))->toHaveCount(5);
});

test('every shelved card links and loads from this storefront only', function () {
    // The shelf renders an image and a link, so anything that is not our own
    // asset would become an off-site request from the customer's browser.
    shelfSbcProduct('one');

    foreach (app(BuildSbcSuggestions::class)->execute('ar') as $item) {
        expect($item['url'])->toStartWith('/')
            ->and($item['url'])->not->toStartWith('//')
            ->and($item['image'])->toStartWith('/')
            ->and($item['image'])->not->toStartWith('//');
    }
});

test('the shelf never carries a price', function () {
    // Chat history is permanent; a price frozen into it becomes a lie the
    // moment the catalogue moves. Prices are looked up when the shelf renders.
    shelfSbcProduct('one');

    foreach (app(BuildSbcSuggestions::class)->execute('ar') as $item) {
        expect(array_keys($item))->toBe(['id', 'title', 'url', 'image']);
    }
});

test('services with a real price table get no shelf', function () {
    // Coins, Rivals and Champions can be quoted outright, so a shelf would be
    // clutter rather than an answer.
    shelfSbcProduct('one');

    expect(app(BuildAssistantShelf::class)->execute('كم سعر الرايفلز', 'ar'))->toBe([])
        ->and(app(BuildAssistantShelf::class)->execute('مليون كوينز', 'ar'))->toBe([]);
});

test('an empty catalogue yields no shelf rather than an empty rail', function () {
    expect(app(BuildAssistantShelf::class)->execute('كم سعر التحديات', 'ar'))->toBe([]);
});

test('an order question is never answered with a sales shelf', function () {
    shelfSbcProduct('one');

    expect(app(BuildAssistantShelf::class)->execute('وين طلبي رقم 5000', 'ar'))->toBe([]);
});
