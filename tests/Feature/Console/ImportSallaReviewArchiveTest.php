<?php

use App\Models\Review;
use Illuminate\Support\Facades\Http;

test('the one-time archive command previews counts then applies without exposing review content', function () {
    $path = tempnam(sys_get_temp_dir(), 'arabut-review-archive-');
    file_put_contents($path, json_encode([
        'schemaVersion' => 1,
        'reviews' => [
            [
                'id' => 'salla-one',
                'rating' => 1,
                'comment' => 'Private output sentinel must stay hidden.',
                'locale' => 'en',
                'public_name' => null,
                'published_at' => '2026-08-12T12:00:00Z',
                'is_visible' => true,
            ],
            [
                'id' => 'salla-five',
                'rating' => 5,
                'comment' => 'Second hidden output sentinel.',
                'locale' => 'en',
                'public_name' => null,
                'published_at' => '2026-08-12T12:01:00Z',
                'is_visible' => true,
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $this->artisan('reviews:import-salla-archive', ['path' => $path])
            ->expectsOutputToContain('count=2')
            ->doesntExpectOutputToContain('Private output sentinel')
            ->assertSuccessful();
        expect(Review::count())->toBe(0);

        $this->artisan('reviews:import-salla-archive', [
            'path' => $path,
            '--apply' => true,
        ])->expectsOutputToContain('count=2')->assertSuccessful();

        expect(Review::count())->toBe(2)
            ->and(Review::where('source_key', 'salla-import')->count())->toBe(2);
    } finally {
        @unlink($path);
    }
});

test('the archive command fails closed for missing or malformed files', function () {
    $missing = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arabut-missing-reviews.json';
    $path = tempnam(sys_get_temp_dir(), 'arabut-review-bad-');
    file_put_contents($path, '{broken');

    try {
        $this->artisan('reviews:import-salla-archive', ['path' => $missing])
            ->assertFailed();
        $this->artisan('reviews:import-salla-archive', ['path' => $path])
            ->assertFailed();
        expect(Review::count())->toBe(0);
    } finally {
        @unlink($path);
    }
});

test('the archive command projects the configured Salla source without customer data', function () {
    config()->set('services.n8n.reviews_url', 'https://reviews.example.test/archive');
    Http::fake([
        'https://reviews.example.test/archive' => Http::response(['data' => [
            [
                'id' => 991,
                'rating' => '1',
                'content' => 'A published low rating.',
                'is_published' => true,
                'created_at' => '2026-08-12 12:00:00',
                'customer' => [
                    'name' => 'Private Person',
                    'mobile' => '+966500000000',
                    'email' => 'private@example.test',
                ],
                'order_id' => 123456789,
            ],
            [
                'id' => 992,
                'rating' => 5,
                'content' => 'غير منشور',
                'is_published' => false,
                'created_at' => '2026-08-12 12:00:00',
            ],
        ]]),
    ]);

    $this->artisan('reviews:import-salla-archive', [
        '--from-config' => true,
        '--apply' => true,
    ])->expectsOutputToContain('count=1')->assertSuccessful();

    $attributes = json_encode(Review::sole()->getAttributes(), JSON_THROW_ON_ERROR);

    expect(Review::sole()->rating)->toBe(1)
        ->and(Review::sole()->reviewer_name)->toBe(trans('store.reviews.anonymous_customer'))
        ->and($attributes)->not->toContain(
            'Private Person',
            '+966500000000',
            'private@example.test',
            '123456789',
        );
});
