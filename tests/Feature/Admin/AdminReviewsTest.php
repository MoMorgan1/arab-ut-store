<?php

use App\Enums\AdminPermission;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Review;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

function adminReviewsActor(UserRole $role = UserRole::Admin): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create(['role' => $role, 'password' => 'SecurePassword!12']);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

function adminTestReview(array $attributes = [], ?Order $order = null): Review
{
    return Review::query()->create([
        'order_id' => $order?->id,
        'reviewer_name' => 'سالم',
        'reviewer_location' => 'الرياض',
        'rating' => 5,
        'body_ar' => 'خدمة ممتازة.',
        'body_en' => null,
        'source' => 'customer',
        'is_visible' => true,
        'published_at' => now(),
        ...$attributes,
    ]);
}

function adminCompletedOrder(): Order
{
    return Order::factory()->for(User::factory()->create())->create([
        'status' => OrderStatus::Completed,
        'completed_at' => now(),
        'order_number' => 'AUT-REVIEW-01',
    ]);
}

function setReviewVisibility(User $actor, Review $review, bool $visible, ?bool $expected = null)
{
    return test()
        ->actingAs($actor)
        ->postJson(route('admin.reviews.visibility.store', ['publicId' => $review->public_id]), [
            'visible' => $visible,
            'expectedVisible' => $expected ?? ! $visible,
        ]);
}

it('lists reviews newest first with the order number and source', function (): void {
    $actor = adminReviewsActor();
    $order = adminCompletedOrder();
    adminTestReview(['reviewer_name' => 'قديم', 'created_at' => now()->subDays(2)]);
    adminTestReview([
        'reviewer_name' => 'جديد',
        'created_at' => now(),
        'source' => 'salla-import',
        'rating' => 4,
    ]);
    adminTestReview(['reviewer_name' => 'مرتبط', 'created_at' => now()->subDay()], $order);

    test()->actingAs($actor)
        ->get('/admin/reviews')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/reviews/index')
            ->where('pagination.total', 3)
            ->has('reviews', 3)
            ->where('reviews.0.reviewerName', 'جديد')
            ->where('reviews.0.source', 'archive')
            ->where('reviews.1.reviewerName', 'مرتبط')
            ->where('reviews.1.order.number', 'AUT-REVIEW-01')
            ->where('reviews.1.source', 'customer')
            ->where('reviews.2.reviewerName', 'قديم')
            ->where('reviews.2.order', null)
            ->where('visibilityUrlTemplate', '/admin/api/reviews/__ID__/visibility'));
});

it('filters the list by storefront state, rating, source and search', function (): void {
    $actor = adminReviewsActor();
    $order = adminCompletedOrder();
    adminTestReview(['reviewer_name' => 'ظاهر', 'rating' => 5], $order);
    adminTestReview([
        'reviewer_name' => 'مخفي',
        'rating' => 2,
        'is_visible' => false,
        'published_at' => null,
        'source' => 'salla-import',
    ]);

    test()->actingAs($actor)->get('/admin/reviews?status=hidden')->assertOk()
        ->assertInertia(fn ($page) => $page->has('reviews', 1)
            ->where('reviews.0.reviewerName', 'مخفي'));

    test()->actingAs($actor)->get('/admin/reviews?status=visible')->assertOk()
        ->assertInertia(fn ($page) => $page->has('reviews', 1)
            ->where('reviews.0.reviewerName', 'ظاهر'));

    test()->actingAs($actor)->get('/admin/reviews?rating=2')->assertOk()
        ->assertInertia(fn ($page) => $page->has('reviews', 1)
            ->where('reviews.0.rating', 2));

    test()->actingAs($actor)->get('/admin/reviews?source=customer')->assertOk()
        ->assertInertia(fn ($page) => $page->has('reviews', 1)
            ->where('reviews.0.source', 'customer'));

    test()->actingAs($actor)->get('/admin/reviews?search=AUT-REVIEW-01')->assertOk()
        ->assertInertia(fn ($page) => $page->has('reviews', 1)
            ->where('reviews.0.reviewerName', 'ظاهر'));

    test()->actingAs($actor)->get('/admin/reviews?search=مخفي')->assertOk()
        ->assertInertia(fn ($page) => $page->has('reviews', 1)
            ->where('reviews.0.reviewerName', 'مخفي'));

    test()->actingAs($actor)->get('/admin/reviews?unknown=1')
        ->assertRedirect()
        ->assertSessionHasErrors('query');
});

it('hides a visible review and records a staff audit entry', function (): void {
    $actor = adminReviewsActor();
    $review = adminTestReview();

    setReviewVisibility($actor, $review, visible: false)
        ->assertOk()
        ->assertJson(['visible' => false]);

    expect($review->fresh()->is_visible)->toBeFalse();

    $audit = StaffAuditLog::query()->latest('id')->firstOrFail();

    expect($audit->action)->toBe('reviews.visibility_changed')
        ->and($audit->metadata['previous_visible'])->toBeTrue()
        ->and($audit->metadata['new_visible'])->toBeFalse()
        ->and($audit->metadata['rating'])->toBe(5);
});

it('shows a hidden four star review again and stamps its publication date', function (): void {
    $actor = adminReviewsActor();
    $review = adminTestReview(['rating' => 4, 'is_visible' => false, 'published_at' => null]);

    setReviewVisibility($actor, $review, visible: true)
        ->assertOk()
        ->assertJson(['visible' => true]);

    $review->refresh();

    expect($review->is_visible)->toBeTrue()
        ->and($review->published_at)->not->toBeNull();
});

it('refuses to show a review below four stars', function (): void {
    $actor = adminReviewsActor();
    $review = adminTestReview(['rating' => 3, 'is_visible' => false, 'published_at' => null]);

    setReviewVisibility($actor, $review, visible: true)
        ->assertStatus(422)
        ->assertJsonValidationErrors('visible');

    expect($review->fresh()->is_visible)->toBeFalse()
        ->and($review->fresh()->published_at)->toBeNull();
});

it('returns 409 when the visibility moved underneath the caller', function (): void {
    $actor = adminReviewsActor();
    $review = adminTestReview();

    setReviewVisibility($actor, $review, visible: false)->assertOk();

    setReviewVisibility($actor, $review, visible: false, expected: true)
        ->assertStatus(409)
        ->assertJson(['current' => ['visible' => false]]);
});

it('rejects unknown fields in the visibility payload', function (): void {
    $actor = adminReviewsActor();
    $review = adminTestReview();

    test()->actingAs($actor)
        ->postJson(route('admin.reviews.visibility.store', ['publicId' => $review->public_id]), [
            'visible' => false,
            'expectedVisible' => true,
            'rating' => 1,
        ])
        ->assertStatus(422);
});

it('refuses the list to an actor without marketing.view', function (): void {
    $staff = adminReviewsActor(UserRole::Staff);

    test()->actingAs($staff)->get('/admin/reviews')->assertForbidden();
});

it('refuses a visibility change to an actor with marketing.view only', function (): void {
    $actor = adminReviewsActor();
    $review = adminTestReview();
    Gate::define(AdminPermission::MarketingManage->value, fn (): bool => false);

    test()->actingAs($actor)->get('/admin/reviews')->assertOk();
    setReviewVisibility($actor, $review, visible: false)->assertForbidden();

    expect($review->fresh()->is_visible)->toBeTrue();
});
