<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to the locale-correct sign in page', function (string $path, string $login): void {
    $this->get($path)->assertRedirect($login);
})->with([
    'Arabic account' => ['/my-account', '/login'],
    'English account' => ['/en/my-account', '/en/login'],
]);

test('active customers can open the canonical bilingual account overview', function (
    string $path,
    string $locale,
    string $title,
    string $accountUrl,
): void {
    $user = User::factory()->create();

    expect($user->is_active)->toBeTrue();

    $response = $this->actingAs($user)
        ->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/overview')
            ->where('locale', $locale)
            ->where('direction', $locale === 'ar' ? 'rtl' : 'ltr')
            ->where('accountUi.page_title', $title)
            ->where('storeShell.accountUrl', $accountUrl));

    expect($response->inertiaPage()['encryptHistory'] ?? false)->toBeTrue();
})->with([
    'Arabic account' => ['/my-account', 'ar', 'حسابي', '/my-account'],
    'English account' => ['/en/my-account', 'en', 'My Account', '/en/my-account'],
]);

test('the Arabic account has one canonical unprefixed URL', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/ar/my-account')
        ->assertNotFound();
});

test('inactive authenticated customers cannot open account pages', function (): void {
    $this->actingAs(User::factory()->create(['is_active' => false]))
        ->get('/my-account')
        ->assertForbidden();
});

test('the account rollout flag can disable every account destination', function (): void {
    config()->set('store.features.my_account_enabled', false);

    $this->actingAs(User::factory()->create())
        ->get('/my-account')
        ->assertNotFound();
});

test('the legacy dashboard sends customers to their preferred account locale', function (
    string $preferredLocale,
    string $accountUrl,
): void {
    $this->actingAs(User::factory()->create(['preferred_locale' => $preferredLocale]))
        ->get('/dashboard')
        ->assertRedirect($accountUrl);
})->with([
    'Arabic preference' => ['ar', '/my-account'],
    'English preference' => ['en', '/en/my-account'],
]);
