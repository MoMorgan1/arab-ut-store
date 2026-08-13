<?php

use App\Actions\Reviews\ImportStoreReviews;
use App\Models\OrderItem;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('it imports every rating while discarding private customer fields', function () {
    $count = app(ImportStoreReviews::class)->execute(reviewPayload([
        'id' => 'review-low-rating',
        'rating' => 2,
        'comment' => '<b>Delivery was slower than expected.</b>',
        'phone' => '+966500000000',
        'email' => 'private@example.test',
        'customer_name' => 'Private Full Name',
    ]));

    $review = Review::sole();

    expect($count)->toBe(1)
        ->and($review->rating)->toBe(2)
        ->and($review->reviewer_name)->toBe(trans('store.reviews.anonymous_customer'))
        ->and($review->body_en)->toBe('Delivery was slower than expected.')
        ->and(json_encode($review->getAttributes()))
        ->not->toContain('+966500000000', 'private@example.test', 'Private Full Name');
});

test('it is idempotent and derives verification only from a real order item link', function () {
    $orderItem = OrderItem::factory()->create();
    $payload = reviewPayload([
        'id' => 'review-linked',
        'rating' => 5,
        'comment' => 'Excellent service.',
        'public_name' => 'M. A.',
        'order_item_public_id' => $orderItem->public_id,
    ]);

    app(ImportStoreReviews::class)->execute($payload);
    app(ImportStoreReviews::class)->execute($payload);

    expect(Review::count())->toBe(1)
        ->and(Review::sole()->order_item_id)->toBe($orderItem->id)
        ->and(Review::sole()->reviewer_name)->toBe('M. A.');
});

test('a malformed refresh rolls back and retains the last good review set', function () {
    app(ImportStoreReviews::class)->execute(reviewPayload());

    expect(fn () => app(ImportStoreReviews::class)->execute([
        'reviews' => [['id' => 'broken', 'rating' => 9]],
    ]))->toThrow(ValidationException::class)
        ->and(Review::count())->toBe(1)
        ->and(Review::sole()->external_id)->toBe('review-1');
});

test('PII hidden inside public review text or display names is rejected before persistence', function (string $field, string $value) {
    expect(fn () => app(ImportStoreReviews::class)->execute(reviewPayload([
        $field => $value,
    ])))->toThrow(ValidationException::class)
        ->and(Review::count())->toBe(0);
})->with([
    'email in comment' => ['comment', 'Email me at private@example.test'],
    'phone in public name' => ['public_name', '+966500000000'],
]);

test('a complete snapshot hides source reviews that disappear without touching manual rows', function () {
    app(ImportStoreReviews::class)->execute(reviewPayload());
    Review::create([
        'reviewer_name' => 'Manual reviewer',
        'rating' => 4,
        'body_en' => 'Manual row',
        'source' => 'manual',
        'is_visible' => true,
        'published_at' => now(),
    ]);

    app(ImportStoreReviews::class)->execute(['reviews' => []]);

    expect(Review::where('source_key', 'n8n')->sole()->is_visible)->toBeFalse()
        ->and(Review::where('source', 'manual')->sole()->is_visible)->toBeTrue();
});

test('a complete Salla archive supports more than five hundred reviews and is idempotent', function () {
    $reviews = collect(range(1, 731))->map(fn (int $index): array => [
        'id' => "salla-review-{$index}",
        'rating' => (($index - 1) % 5) + 1,
        'comment' => "Public review {$index}",
        'locale' => 'ar',
        'public_name' => null,
        'published_at' => '2026-08-12T12:00:00Z',
        'is_visible' => true,
    ])->all();
    $payload = ['schemaVersion' => 1, 'reviews' => $reviews];

    $preview = app(ImportStoreReviews::class)->executeArchive($payload, false);

    expect($preview['count'])->toBe(731)
        ->and(array_sum($preview['ratings']))->toBe(731)
        ->and(Review::count())->toBe(0);

    app(ImportStoreReviews::class)->executeArchive($payload, true);
    app(ImportStoreReviews::class)->executeArchive($payload, true);

    expect(Review::count())->toBe(731)
        ->and(Review::where('source_key', 'salla-import')->count())->toBe(731)
        ->and(Review::where('rating', 1)->count())->toBeGreaterThan(0)
        ->and(Review::whereNotNull('order_item_id')->count())->toBe(0);
});

test('raw Salla records retain only the public review name and city', function () {
    $projected = app(ImportStoreReviews::class)->projectSallaSource([
        'data' => [[
            'id' => 8821,
            'rating' => 4,
            'content' => null,
            'created_at' => '2026-08-12T12:00:00Z',
            'is_published' => true,
            'customer' => [
                'name' => 'Public Customer',
                'city' => 'Riyadh',
                'mobile' => '+966500000000',
                'email' => 'private@example.test',
            ],
        ]],
    ]);

    expect($projected['reviews'])->toHaveCount(1)
        ->and($projected['reviews'][0]['id'])->toBe('salla:8821')
        ->and($projected['reviews'][0]['rating'])->toBe(4)
        ->and($projected['reviews'][0]['comment'])->toBe('تقييم بدون تعليق.')
        ->and($projected['reviews'][0]['public_name'])->toBe('Public Customer')
        ->and($projected['reviews'][0]['public_location'])->toBe('Riyadh')
        ->and(json_encode($projected, JSON_UNESCAPED_UNICODE))
        ->not->toContain('+966500000000', 'private@example.test');
});

test('the normalized source retains explicit public display name and location only', function () {
    $projected = app(ImportStoreReviews::class)->projectSallaSource([
        'reviews' => [[
            'id' => 'public-review',
            'rating' => 5,
            'comment' => 'Excellent service.',
            'locale' => 'en',
            'public_name' => 'Mohamed A.',
            'public_location' => 'Cairo',
            'published_at' => '2026-08-12T12:00:00Z',
            'is_visible' => true,
            'email' => 'private@example.test',
        ]],
    ]);

    expect($projected['reviews'][0]['public_name'])->toBe('Mohamed A.')
        ->and($projected['reviews'][0]['public_location'])->toBe('Cairo')
        ->and(json_encode($projected, JSON_UNESCAPED_UNICODE))
        ->not->toContain('private@example.test');
});

test('the Salla archive rejects private or duplicate records before changing the last good archive', function () {
    $valid = [
        'schemaVersion' => 1,
        'reviews' => [[
            'id' => 'salla-safe',
            'rating' => 5,
            'comment' => 'Excellent service.',
            'locale' => 'en',
            'public_name' => null,
            'published_at' => '2026-08-12T12:00:00Z',
            'is_visible' => true,
        ]],
    ];
    app(ImportStoreReviews::class)->executeArchive($valid, true);

    foreach ([
        'private field' => [array_merge($valid, [
            'reviews' => [array_merge($valid['reviews'][0], ['email' => 'private@example.test'])],
        ])],
        'duplicate identity' => [array_merge($valid, [
            'reviews' => [$valid['reviews'][0], $valid['reviews'][0]],
        ])],
        'private text' => [array_merge($valid, [
            'reviews' => [array_merge($valid['reviews'][0], ['comment' => 'Call +966500000000'])],
        ])],
    ] as [$payload]) {
        expect(fn () => app(ImportStoreReviews::class)->executeArchive($payload, true))
            ->toThrow(ValidationException::class);
    }

    expect(Review::count())->toBe(1)
        ->and(Review::sole()->external_id)->toBe('salla-safe');
});

test('the Salla archive reconciles only its own source', function () {
    app(ImportStoreReviews::class)->execute(reviewPayload());
    Review::create([
        'reviewer_name' => 'Manual reviewer',
        'rating' => 4,
        'body_en' => 'Manual row',
        'source' => 'manual',
        'is_visible' => true,
        'published_at' => now(),
    ]);

    app(ImportStoreReviews::class)->executeArchive([
        'schemaVersion' => 1,
        'reviews' => [[
            'id' => 'review-1',
            'rating' => 2,
            'comment' => 'Independent historical review.',
            'locale' => 'en',
            'public_name' => null,
            'published_at' => '2026-08-12T12:00:00Z',
            'is_visible' => true,
        ]],
    ], true);

    expect(Review::where('source_key', 'n8n')->sole()->is_visible)->toBeTrue()
        ->and(Review::where('source', 'manual')->sole()->is_visible)->toBeTrue()
        ->and(Review::where('source_key', 'salla-import')->sole()->rating)->toBe(2);
});

/** @param array<string, mixed> $review */
function reviewPayload(array $review = []): array
{
    return [
        'reviews' => [array_merge([
            'id' => 'review-1',
            'rating' => 5,
            'comment' => 'Fast and safe.',
            'locale' => 'en',
            'public_name' => null,
            'order_item_public_id' => null,
            'published_at' => '2026-08-11T12:00:00Z',
            'is_visible' => true,
        ], $review)],
    ];
}
