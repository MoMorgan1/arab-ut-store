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
