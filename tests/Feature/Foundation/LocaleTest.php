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
