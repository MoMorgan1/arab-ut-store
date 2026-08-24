<?php

use Inertia\Testing\AssertableInertia as Assert;

test('Arabic is the default storefront locale and document direction', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('lang="ar"', false)
        ->assertSee('dir="rtl"', false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'ar')
            ->where('direction', 'rtl')
            ->where('displayCurrency', 'SAR'));
});

test('the English storefront route uses left-to-right metadata', function () {
    $this->get('/en')
        ->assertOk()
        ->assertSee('lang="en"', false)
        ->assertSee('dir="ltr"', false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('direction', 'ltr'));
});

test('the storefront exposes the exact localized wordmark in the :locale locale', function (string $path, string $locale, string $brand) {
    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', $locale)
            ->where('ui.brand', $brand));
})->with([
    'Arabic' => ['/', 'ar', 'عرب التيميت'],
    'English' => ['/en', 'en', 'Arab UT'],
]);

test('the storefront shares the configured display currency list', function () {
    config()->set('store.display_currencies', ['SAR', 'CAD']);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('displayCurrencies', ['SAR', 'CAD']));
});

test('the default storefront currency list includes the Gulf currencies', function () {
    expect(config('store.display_currencies'))->toBe([
        'SAR', 'AED', 'KWD', 'BHD', 'OMR', 'QAR', 'USD', 'EUR', 'GBP', 'EGP',
    ]);
});

test('unsupported locale prefixes return not found', function () {
    $this->get('/fr')->assertNotFound();
});

test('a supported display currency persists without changing the SAR checkout currency', function () {
    $this->get('/?currency=USD')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('displayCurrency', 'USD')
            ->where('checkoutCurrency', 'SAR'));

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('displayCurrency', 'USD')
            ->where('checkoutCurrency', 'SAR'));
});

test('an unsupported display currency keeps the default preference', function () {
    $this->get('/?currency=JPY')
        ->assertOk()
        ->assertSessionHas('display_currency', 'SAR')
        ->assertInertia(fn (Assert $page) => $page
            ->where('displayCurrency', 'SAR')
            ->where('checkoutCurrency', 'SAR'));
});

test('an unsupported display currency never overwrites a persisted preference', function () {
    $this->withSession(['display_currency' => 'EUR'])
        ->get('/en?currency=JPY')
        ->assertOk()
        ->assertSessionHas('display_currency', 'EUR')
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('displayCurrency', 'EUR')
            ->where('checkoutCurrency', 'SAR'));
});

test('a stale display currency preference is replaced by the configured default', function () {
    config()->set('store.display_currencies', ['SAR', 'CAD']);
    config()->set('store.default_display_currency', 'CAD');

    $this->withSession(['display_currency' => 'JPY'])
        ->get('/')
        ->assertOk()
        ->assertSessionHas('display_currency', 'CAD')
        ->assertInertia(fn (Assert $page) => $page
            ->where('displayCurrency', 'CAD')
            ->where('displayCurrencies', ['SAR', 'CAD'])
            ->where('checkoutCurrency', 'SAR'));
});

test('a rejected currency on either quote endpoint cannot mutate the display preference', function (string $path) {
    $this->withSession(['display_currency' => 'EUR'])
        ->getJson($path.'?platform=pc&quantity=50000&currency=USD')
        ->assertUnprocessable()
        ->assertSessionHas('display_currency', 'EUR');
})->with(['/coins/quote', '/en/coins/quote']);
