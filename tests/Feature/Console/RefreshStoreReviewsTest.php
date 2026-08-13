<?php

use App\Models\Review;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('the review refresh fetches once and imports only the safe projection', function () {
    config()->set('services.n8n.reviews_url', 'https://reviews.example.test/storefront');
    Http::fake([
        'https://reviews.example.test/storefront' => Http::response([
            'reviews' => [[
                'id' => 'remote-1',
                'rating' => 3,
                'comment' => 'Good service.',
                'locale' => 'en',
                'public_name' => null,
                'order_item_public_id' => null,
                'published_at' => '2026-08-11T12:00:00Z',
                'is_visible' => true,
                'email' => 'secret@example.test',
            ]],
        ]),
    ]);

    $this->artisan('reviews:refresh')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://reviews.example.test/storefront');
    expect(Review::count())->toBe(1)
        ->and(json_encode(Review::sole()->getAttributes()))->not->toContain('secret@example.test');
});

test('an unavailable source fails without deleting the last good data', function () {
    Review::create([
        'reviewer_name' => 'Existing',
        'rating' => 5,
        'body_en' => 'Last good review',
        'source' => 'manual',
        'is_visible' => true,
        'published_at' => now(),
    ]);
    config()->set('services.n8n.reviews_url', 'https://reviews.example.test/storefront');
    Http::fake(['*' => Http::response([], 503)]);

    $this->artisan('reviews:refresh')->assertFailed();

    expect(Review::count())->toBe(1);
});

test('the legacy review refresh is not scheduled after the Salla archive migration', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'reviews:refresh'));

    expect($event)->toBeNull();
});
