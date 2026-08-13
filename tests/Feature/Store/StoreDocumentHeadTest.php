<?php

test('the storefront document uses Arab UT brand icons instead of Laravel defaults', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('/favicon-32x32.png?v=arab-ut-2026', false)
        ->assertSee('/apple-touch-icon.png?v=arab-ut-2026', false)
        ->assertDontSee('/favicon.ico', false)
        ->assertDontSee('/favicon.svg', false);
});
