<?php

use App\Models\Review;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('the bilingual review pages expose only safe visible database projections', function (string $path, string $locale) {
    Review::create([
        'reviewer_name' => 'Public name',
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
    Http::fake();

    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/reviews', false)
            ->where('locale', $locale)
            ->has('reviews.items', 1)
            ->where('reviews.items.0.reviewerName', 'Public name')
            ->where('reviews.items.0.rating', 4)
            ->missing('reviews.items.0.source')
            ->missing('reviews.items.0.email'));

    Http::assertNothingSent();
})->with([
    'Arabic' => ['/reviews', 'ar'],
    'English' => ['/en/reviews', 'en'],
]);
