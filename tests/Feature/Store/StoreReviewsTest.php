<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use App\Services\Reviews\StoreReviewReader;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('the bilingual review pages expose only safe visible database projections', function (string $path, string $locale) {
    Review::create([
        'reviewer_name' => 'Public name',
        'reviewer_location' => 'Cairo',
        'rating' => 4,
        'body_ar' => 'ØªØ¬Ø±Ø¨Ø© Ù…Ù…ØªØ§Ø²Ø©',
        'body_en' => 'Excellent experience',
        'source' => 'n8n',
        'source_key' => 'n8n',
        'external_id' => 'review-1',
        'content_hash' => hash('sha256', 'safe'),
        'is_visible' => true,
        'published_at' => now(),
    ]);
    Review::create([
        'reviewer_name' => 'Hidden',
        'rating' => 1,
        'body_en' => 'Hidden body',
        'source' => 'n8n',
        'source_key' => 'n8n',
        'external_id' => 'review-hidden',
        'content_hash' => hash('sha256', 'hidden'),
        'is_visible' => false,
        'published_at' => now(),
    ]);
    Review::create([
        'reviewer_name' => 'Published low rating',
        'rating' => 3,
        'body_en' => 'This row stays archived but must not be public.',
        'source' => 'salla-import',
        'source_key' => 'salla-import',
        'external_id' => 'review-published-low',
        'content_hash' => hash('sha256', 'published-low'),
        'is_visible' => true,
        'published_at' => now()->addSecond(),
    ]);
    Http::fake();

    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/reviews', false)
            ->where('locale', $locale)
            ->has('reviews.items', 1)
            ->where('reviews.items.0.reviewerName', 'Public name')
            ->where('reviews.items.0.reviewerLocation', 'Cairo')
            ->where('reviews.items.0.rating', 4)
            ->where('reviews.average', 4)
            ->where('reviews.count', 1)
            ->missing('reviews.items.0.source')
            ->missing('reviews.items.0.email'));

    Http::assertNothingSent();
})->with([
    'Arabic' => ['/reviews', 'ar'],
    'English' => ['/en/reviews', 'en'],
]);

test('the homepage prioritizes written reviews over newer rating-only entries', function () {
    foreach (range(1, 6) as $index) {
        Review::create([
            'reviewer_name' => "Rating only {$index}",
            'rating' => 5,
            'body_ar' => trans('store.reviews.rating_without_comment', locale: 'ar'),
            'source' => 'salla-import',
            'source_key' => 'salla-import',
            'external_id' => "rating-only-{$index}",
            'content_hash' => hash('sha256', "rating-only-{$index}"),
            'is_visible' => true,
            'published_at' => now()->subMinutes($index),
        ]);
    }

    foreach (['تفاصيل تجربة أولى', 'تفاصيل تجربة ثانية'] as $index => $body) {
        Review::create([
            'reviewer_name' => "Written review {$index}",
            'rating' => 5,
            'body_ar' => $body,
            'source' => 'salla-import',
            'source_key' => 'salla-import',
            'external_id' => "written-review-{$index}",
            'content_hash' => hash('sha256', "written-review-{$index}"),
            'is_visible' => true,
            'published_at' => now()->subDay()->subMinutes($index),
        ]);
    }

    $homepage = app(StoreReviewReader::class)->homepage('ar');

    expect(array_slice(array_column($homepage['items'], 'body'), 0, 2))
        ->toBe(['تفاصيل تجربة أولى', 'تفاصيل تجربة ثانية']);
});

function storeReviewsFixture(): void
{
    foreach ([
        ['a', 5, 'خدمة ممتازة', 'Excellent service', false, 3],
        ['b', 5, trans('store.reviews.rating_without_comment', locale: 'ar'), null, false, 2],
        ['c', 4, 'جيد جداً', 'Very good', true, 1],
        ['d', 4, 'سريع', 'Fast', false, 0],
    ] as [$key, $rating, $bodyAr, $bodyEn, $verified, $daysAgo]) {
        $review = Review::create([
            'reviewer_name' => "Reviewer {$key}",
            'rating' => $rating,
            'body_ar' => $bodyAr,
            'body_en' => $bodyEn,
            'source' => 'salla-import',
            'source_key' => 'salla-import',
            'external_id' => "fixture-{$key}",
            'content_hash' => hash('sha256', "fixture-{$key}"),
            'is_visible' => true,
            'published_at' => now()->subDays($daysAgo),
        ]);

        if ($verified) {
            $order = Order::factory()->create();
            $item = OrderItem::factory()->for($order)->create();
            $review->forceFill(['order_item_id' => $item->id])->save();
        }
    }
}

test('the summary carries the star distribution and the verified count', function () {
    storeReviewsFixture();

    $summary = app(StoreReviewReader::class)->homepage('ar');

    expect($summary['count'])->toBe(4)
        ->and($summary['average'])->toBe(4.5)
        ->and($summary['verifiedCount'])->toBe(1)
        ->and(array_column($summary['distribution'], 'percent', 'rating'))->toBe([5 => 50, 4 => 50, 3 => 0, 2 => 0, 1 => 0])
        ->and($summary['items'][0]['hasComment'])->toBeTrue();
});

test('the reviews page filters by rating, verified orders and comments, and sorts by rating', function () {
    storeReviewsFixture();

    $reader = app(StoreReviewReader::class);
    $names = fn (array $result): array => array_column($result['items'], 'reviewerName');

    expect($names($reader->paginate('ar', 1, ['rating' => '4'])))->toBe(['Reviewer d', 'Reviewer c'])
        ->and($names($reader->paginate('ar', 1, ['verified' => true])))->toBe(['Reviewer c'])
        ->and($names($reader->paginate('ar', 1, ['withComment' => true])))->toBe(['Reviewer d', 'Reviewer c', 'Reviewer a'])
        ->and($names($reader->paginate('ar', 1, ['sort' => 'highest'])))->toBe(['Reviewer b', 'Reviewer a', 'Reviewer d', 'Reviewer c'])
        ->and($reader->paginate('ar', 1, ['rating' => '4'])['count'])->toBe(4);
});

test('the reviews page accepts only the allow-listed filters and exposes them', function () {
    storeReviewsFixture();

    $this->get('/reviews?rating=4&verified=1&sort=highest')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.rating', '4')
            ->where('filters.verified', true)
            ->where('filters.withComment', false)
            ->where('filters.sort', 'highest')
            ->where('rateUrl', '/my-account/orders')
            ->has('reviews.items', 1)
            ->has('reviews.distribution', 5));

    $this->get('/reviews?rating=3')->assertSessionHasErrors('rating');
    $this->get('/en/reviews?sort=oldest')->assertSessionHasErrors('sort');
});
