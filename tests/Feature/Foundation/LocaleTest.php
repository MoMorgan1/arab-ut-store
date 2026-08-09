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
