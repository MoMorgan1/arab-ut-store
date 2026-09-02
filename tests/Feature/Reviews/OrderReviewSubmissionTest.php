<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use App\Services\Reviews\StoreReviewReader;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function reviewableOrder(User $user, array $attributes = []): Order
{
    return Order::factory()->for($user)->create([
        'status' => OrderStatus::Completed,
        'completed_at' => now()->subDay(),
        'channel' => 'store',
        'locale' => 'ar',
        ...$attributes,
    ]);
}

function submitReview(User $user, Order $order, array $payload = [], string $prefix = '')
{
    return test()
        ->actingAs($user)
        ->post("{$prefix}/my-account/orders/{$order->public_id}/review", [
            'rating' => 5,
            ...$payload,
        ]);
}

it('stores a five star review from the order owner and publishes it', function (): void {
    $user = User::factory()->create(['first_name' => 'محمد']);
    $order = reviewableOrder($user);

    submitReview($user, $order, ['body' => 'خدمة سريعة وممتازة.'])
        ->assertRedirect("/my-account/orders/{$order->public_id}");

    $review = Review::query()->where('order_id', $order->id)->firstOrFail();

    expect($review->rating)->toBe(5)
        ->and($review->user_id)->toBe($user->id)
        ->and($review->reviewer_name)->toBe('محمد')
        ->and($review->source)->toBe('customer')
        ->and($review->body_ar)->toBe('خدمة سريعة وممتازة.')
        ->and($review->body_en)->toBeNull()
        ->and($review->is_visible)->toBeTrue()
        ->and($review->published_at)->not->toBeNull();
});

it('stores a two star review hidden and unpublished', function (): void {
    $user = User::factory()->create();
    $order = reviewableOrder($user);

    submitReview($user, $order, ['rating' => 2, 'body' => 'تأخر التنفيذ.'])->assertRedirect();

    $review = Review::query()->where('order_id', $order->id)->firstOrFail();

    expect($review->rating)->toBe(2)
        ->and($review->is_visible)->toBeFalse()
        ->and($review->published_at)->toBeNull();
});

it('ignores a posted is_visible and published_at on a low rating', function (): void {
    $user = User::factory()->create();
    $order = reviewableOrder($user);

    submitReview($user, $order, [
        'rating' => 1,
        'is_visible' => true,
        'published_at' => now()->toIso8601String(),
    ])->assertRedirect();

    $review = Review::query()->where('order_id', $order->id)->firstOrFail();

    expect($review->is_visible)->toBeFalse()
        ->and($review->published_at)->toBeNull();
});

it('stores the rating-only placeholder when no comment is written', function (): void {
    $user = User::factory()->create();
    $order = reviewableOrder($user, ['locale' => 'en']);

    submitReview($user, $order, ['rating' => 4])->assertRedirect();

    $review = Review::query()->where('order_id', $order->id)->firstOrFail();

    expect($review->body_en)->toBe(trans('store.reviews.rating_without_comment', [], 'en'))
        ->and($review->body_ar)->toBeNull();
});

it('falls back to the anonymous label when the customer has no first name', function (): void {
    $user = User::factory()->create(['first_name' => '']);
    $order = reviewableOrder($user);

    submitReview($user, $order)->assertRedirect();

    expect(Review::query()->where('order_id', $order->id)->value('reviewer_name'))
        ->toBe(trans('store.reviews.anonymous_customer', [], 'ar'));
});

it('accepts the English twin of the route', function (): void {
    $user = User::factory()->create();
    $order = reviewableOrder($user, ['locale' => 'en']);

    submitReview($user, $order, ['rating' => 5, 'body' => 'Fast and clean.'], '/en')
        ->assertRedirect("/en/my-account/orders/{$order->public_id}");

    expect(Review::query()->where('order_id', $order->id)->value('body_en'))
        ->toBe('Fast and clean.');
});

it('refuses a review from anyone but the order owner', function (): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $order = reviewableOrder($owner);

    submitReview($stranger, $order)->assertNotFound();

    expect(Review::query()->count())->toBe(0);
});

it('refuses a review on an imported Salla order', function (): void {
    $user = User::factory()->create();
    $order = reviewableOrder($user, ['channel' => 'salla_import']);

    submitReview($user, $order)->assertSessionHasErrors('rating');

    expect(Review::query()->count())->toBe(0);
});

it('refuses a review on an order that is not completed', function (OrderStatus $status): void {
    $user = User::factory()->create();
    $order = reviewableOrder($user, ['status' => $status, 'completed_at' => null]);

    submitReview($user, $order)->assertSessionHasErrors('rating');

    expect(Review::query()->count())->toBe(0);
})->with([
    'cancelled' => [OrderStatus::Cancelled],
    'refunded' => [OrderStatus::Refunded],
]);

it('refuses a review from a banned account', function (): void {
    $user = User::factory()->create(['is_active' => false]);
    $order = reviewableOrder($user);

    submitReview($user, $order)->assertForbidden();

    expect(Review::query()->count())->toBe(0);
});

it('refuses a second review for the same order', function (): void {
    $user = User::factory()->create();
    $order = reviewableOrder($user);

    submitReview($user, $order)->assertRedirect();
    submitReview($user, $order, ['rating' => 3])->assertSessionHasErrors('rating');

    expect(Review::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('refuses a second review at the database level', function (): void {
    $user = User::factory()->create();
    $order = reviewableOrder($user);

    submitReview($user, $order)->assertRedirect();

    // The action's existence check is the first guard; this is the second one,
    // the guard that survives two submissions racing each other.
    expect(fn () => Review::query()->create([
        'user_id' => $user->id,
        'order_id' => $order->id,
        'reviewer_name' => 'Racer',
        'rating' => 5,
        'body_ar' => 'مرة ثانية.',
        'source' => 'customer',
        'is_visible' => true,
        'published_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('rejects a rating outside one to five and a comment beyond 600 characters', function (): void {
    $user = User::factory()->create();
    $order = reviewableOrder($user);

    submitReview($user, $order, ['rating' => 6])->assertSessionHasErrors('rating');
    submitReview($user, $order, ['body' => str_repeat('ا', 601)])->assertSessionHasErrors('body');

    expect(Review::query()->count())->toBe(0);
});

it('strips control characters from the comment before storing it', function (): void {
    $user = User::factory()->create();
    $order = reviewableOrder($user);

    submitReview($user, $order, ['body' => "خدمة\u{202E}ممتازة\u{0007}"])->assertRedirect();

    expect(Review::query()->where('order_id', $order->id)->value('body_ar'))
        ->toBe('خدمةممتازة');
});

it('shows the new review on the storefront as verified and never a hidden one', function (): void {
    $user = User::factory()->create(['first_name' => 'سالم']);
    $published = reviewableOrder($user);
    $hidden = reviewableOrder($user);

    submitReview($user, $published, ['rating' => 5, 'body' => 'ممتاز جدًا.'])->assertRedirect();
    submitReview($user, $hidden, ['rating' => 2, 'body' => 'غير راضٍ.'])->assertRedirect();

    $storefront = app(StoreReviewReader::class)->homepage('ar');

    expect($storefront['count'])->toBe(1)
        ->and($storefront['items'][0]['reviewerName'])->toBe('سالم')
        ->and($storefront['items'][0]['verified'])->toBeTrue()
        ->and($storefront['items'][0]['body'])->toBe('ممتاز جدًا.');
});

it('exposes the review slot on the order page only when the order can be reviewed', function (): void {
    $user = User::factory()->create();
    $open = Order::factory()->for($user)->create(['status' => OrderStatus::InProgress]);
    $imported = reviewableOrder($user, ['channel' => 'salla_import']);
    $reviewable = reviewableOrder($user);

    test()->actingAs($user)
        ->get("/my-account/orders/{$open->public_id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('order.review', null));

    test()->actingAs($user)
        ->get("/my-account/orders/{$imported->public_id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('order.review', null));

    test()->actingAs($user)
        ->get("/my-account/orders/{$reviewable->public_id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.review.url', "/my-account/orders/{$reviewable->public_id}/review")
            ->where('order.review.submitted', null));

    submitReview($user, $reviewable, ['rating' => 5, 'body' => 'شكرًا لكم.'])->assertRedirect();

    test()->actingAs($user)
        ->get("/my-account/orders/{$reviewable->public_id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.review.submitted.rating', 5)
            ->where('order.review.submitted.body', 'شكرًا لكم.')
            ->where('order.review.submitted.visible', true)
            ->where('order.review.submitted.publishedAt', fn ($value): bool => is_string($value)));
});
