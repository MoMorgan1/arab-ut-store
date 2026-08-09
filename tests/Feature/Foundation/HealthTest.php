<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the public storefront responds successfully', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/home')
            ->where('locale', 'ar')
            ->where('direction', 'rtl')
            ->where('checkoutCurrency', 'SAR'));
});
