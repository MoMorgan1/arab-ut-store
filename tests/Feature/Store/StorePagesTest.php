<?php

use App\Actions\Store\ValidateStoreInformationPage;
use App\Models\StorePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('migration seeds five store pages and leaves existing table untouched', function (): void {
    expect(StorePage::query()->count())->toBe(5);

    // Running the migration directly again should be a no-op when table is not empty
    $migration = require database_path('migrations/2026_09_02_000003_create_store_pages.php');
    $migration->up();

    expect(StorePage::query()->count())->toBe(5);
});

test('every policy route renders identically to seed data for both locales', function (string $uri, string $key, string $locale): void {
    $seedData = require database_path("seeders/data/store_pages/{$locale}.php");
    $metaTranslations = require lang_path("{$locale}/store_pages.php");

    $expectedPage = (new ValidateStoreInformationPage)->validate(
        $key,
        $seedData[$key],
        [
            ...$metaTranslations['meta'],
            'updated_value' => $seedData[$key]['updated_label'],
        ],
        config('store.support.whatsapp_url'),
    );

    $this->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('store/simple-page')
            ->where('locale', $locale)
            ->where('page.key', $key)
            ->where('page.title', $expectedPage['title'])
            ->where('page.updated', $expectedPage['updated'])
            ->where('page.blocks', $expectedPage['blocks'])
            ->where('seo.title', $expectedPage['title']));
})->with([
    ['/privacy', 'privacy', 'ar'],
    ['/returns', 'returns', 'ar'],
    ['/warranty', 'warranty', 'ar'],
    ['/ea-backup-codes', 'ea_backup_codes', 'ar'],
    ['/terms', 'terms', 'ar'],
    ['/en/privacy', 'privacy', 'en'],
    ['/en/returns', 'returns', 'en'],
    ['/en/warranty', 'warranty', 'en'],
    ['/en/ea-backup-codes', 'ea_backup_codes', 'en'],
    ['/en/terms', 'terms', 'en'],
]);

test('modifying a store page changes the storefront on the next request', function (): void {
    $page = StorePage::query()->where('key', 'privacy')->firstOrFail();
    $page->title_ar = 'سياسة الخصوصية المعدلة';
    $page->save();

    $this->get('/privacy')
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('store/simple-page')
            ->where('page.title', 'سياسة الخصوصية المعدلة')
            ->where('seo.title', 'سياسة الخصوصية المعدلة'));
});
