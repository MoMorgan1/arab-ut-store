<?php

test('the storefront document uses Arab UT brand icons instead of Laravel defaults', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('/favicon-32x32.png', false)
        ->assertSee('/apple-touch-icon.png', false)
        ->assertDontSee('/favicon.svg', false);
});
