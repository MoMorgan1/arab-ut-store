<?php

use App\Models\FaqEntry;
use App\Services\Content\StoreFaqReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('home page renders seeded FAQ entries in both Arabic and English locales', function (string $path, string $locale): void {
    // Seed migration is run during RefreshDatabase
    expect(FaqEntry::query()->count())->toBe(4);

    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/home')
            ->where('locale', $locale)
            ->has('homeContent.faq', 4)
            ->has('homeContent.faq.0.id')
            ->has('homeContent.faq.0.question')
            ->has('homeContent.faq.0.answer'));
})->with([
    'arabic' => ['/', 'ar'],
    'english' => ['/en', 'en'],
]);

test('home page passes empty FAQ array when all entries are hidden', function (): void {
    FaqEntry::query()->update(['is_visible' => false]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/home')
            ->has('homeContent.faq', 0));
});

test('StoreFaqReader respects visibility, ordering, and English fallback', function (): void {
    DB::table('faq_entries')->delete();

    // Entry 2 (sort_order 20)
    FaqEntry::query()->create([
        'question_ar' => 'سؤال 2',
        'question_en' => 'Question 2',
        'answer_ar' => 'إجابة 2',
        'answer_en' => 'Answer 2',
        'sort_order' => 20,
        'is_visible' => true,
    ]);

    // Entry 1 (sort_order 10)
    FaqEntry::query()->create([
        'question_ar' => 'سؤال 1',
        'question_en' => '', // blank English -> should fallback to Arabic
        'answer_ar' => 'إجابة 1',
        'answer_en' => '   ', // whitespace English -> should fallback to Arabic
        'sort_order' => 10,
        'is_visible' => true,
    ]);

    // Hidden Entry (sort_order 5) -> should be excluded
    FaqEntry::query()->create([
        'question_ar' => 'سؤال مخفي',
        'question_en' => 'Hidden question',
        'answer_ar' => 'إجابة مخفية',
        'answer_en' => 'Hidden answer',
        'sort_order' => 5,
        'is_visible' => false,
    ]);

    $reader = app(StoreFaqReader::class);

    // In Arabic
    $arabicEntries = $reader->entries('ar');
    expect($arabicEntries)->toHaveCount(2)
        ->and($arabicEntries[0]['question'])->toBe('سؤال 1')
        ->and($arabicEntries[0]['answer'])->toBe('إجابة 1')
        ->and($arabicEntries[1]['question'])->toBe('سؤال 2')
        ->and($arabicEntries[1]['answer'])->toBe('إجابة 2');

    // In English
    $englishEntries = $reader->entries('en');
    expect($englishEntries)->toHaveCount(2)
        ->and($englishEntries[0]['question'])->toBe('سؤال 1') // fell back to Arabic
        ->and($englishEntries[0]['answer'])->toBe('إجابة 1') // fell back to Arabic
        ->and($englishEntries[1]['question'])->toBe('Question 2')
        ->and($englishEntries[1]['answer'])->toBe('Answer 2');
});
