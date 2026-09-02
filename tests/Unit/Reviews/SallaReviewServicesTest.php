<?php

use App\Actions\Reviews\ImportStoreReviews;
use App\Models\Review;
use App\Services\Reviews\SallaProductServiceMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('the Salla product name maps to the store service by a fixed rule table', function (?string $name, ?string $expected) {
    expect(SallaProductServiceMap::resolve($name))->toBe($expected);
})->with([
    'coins listing' => ['كوينز سوني | إكس بوكس', 'coins'],
    'coins amount' => ['500 ألف كوينز سوني 4/5', 'coins'],
    'fut champions' => ['الفوت تشامبيون', 'fut_champions'],
    'fut rank' => ['رانك ثري سوني / بي سي', 'fut_champions'],
    'rivals' => ['الرايفلز', 'rivals'],
    'sbc icon' => ['تحدي أيكون +88 تشكيلة السنة', 'sbc'],
    'sbc upgrade' => ['ترقيات 81+', 'sbc'],
    'objectives' => ['مهام السوابس', 'objectives'],
    'objective single' => ['مهمة سيمونز', 'objectives'],
    'we buy coins is not the coins service' => ['نشتري منك الكوينز', null],
    'unknown product' => ['بطاقة هدايا', null],
    'blank' => ['', null],
    'null' => [null, null],
]);

test('raw Salla product reviews carry their service and store reviews carry none', function () {
    $projected = app(ImportStoreReviews::class)->projectSallaSource([
        'data' => [
            [
                'id' => 1,
                'type' => 'product',
                'rating' => 5,
                'content' => 'خدمة سريعة',
                'created_at' => '2026-08-12T12:00:00Z',
                'is_published' => true,
                'product' => ['id' => 99, 'name' => 'الرايفلز', 'thumbnail' => 'https://cdn.example/x.png'],
                'customer' => ['name' => 'Public Customer', 'city' => 'Riyadh', 'mobile' => '+966500000000'],
            ],
            [
                'id' => 2,
                'type' => 'store',
                'rating' => 5,
                'content' => 'أفضل متجر',
                'created_at' => '2026-08-12T12:00:00Z',
                'is_published' => true,
                'product' => null,
                'customer' => ['name' => 'Other Customer', 'city' => 'Jeddah'],
            ],
        ],
    ]);

    expect($projected['reviews'][0]['service'])->toBe('rivals')
        ->and($projected['reviews'][1]['service'])->toBeNull()
        ->and(json_encode($projected, JSON_UNESCAPED_UNICODE))
        ->not->toContain('+966500000000', 'thumbnail');
});

test('the archive persists the service and a re-run keeps an admin-hidden row hidden', function () {
    $import = app(ImportStoreReviews::class);
    $row = fn (?string $service): array => [
        'id' => 'salla:77',
        'rating' => 5,
        'comment' => 'تعليق رايفلز',
        'locale' => 'ar',
        'public_name' => 'Customer',
        'published_at' => '2026-08-12T12:00:00Z',
        'is_visible' => true,
        'service' => $service,
    ];

    $import->executeArchive(['schemaVersion' => 1, 'reviews' => [$row(null)]], true);

    $review = Review::query()->where('external_id', 'salla:77')->firstOrFail();
    expect($review->service_type)->toBeNull();

    $review->forceFill(['is_visible' => false])->save();

    $import->executeArchive(['schemaVersion' => 1, 'reviews' => [$row('rivals')]], true);

    $review->refresh();
    expect($review->service_type)->toBe('rivals')
        ->and($review->is_visible)->toBeFalse()
        ->and(Review::query()->where('external_id', 'salla:77')->count())->toBe(1);
});

test('the archive rejects a service outside the store services', function () {
    app(ImportStoreReviews::class)->executeArchive(['schemaVersion' => 1, 'reviews' => [[
        'id' => 'salla:78',
        'rating' => 5,
        'comment' => 'تعليق',
        'locale' => 'ar',
        'public_name' => null,
        'published_at' => '2026-08-12T12:00:00Z',
        'is_visible' => true,
        'service' => 'sell_coins',
    ]]], true);
})->throws(ValidationException::class);
