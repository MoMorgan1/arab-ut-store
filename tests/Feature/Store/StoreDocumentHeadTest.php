<?php

test('the storefront document uses Arab UT brand icons instead of Laravel defaults', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('/favicon-32x32.png?v=arab-ut-2026', false)
        ->assertSee('/apple-touch-icon.png?v=arab-ut-2026', false)
        ->assertDontSee('/favicon.ico', false)
        ->assertDontSee('/favicon.svg', false);
});

test('the storefront document is installable and preloads its above-the-fold assets', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/site.webmanifest?v=arab-ut-2026-2">', false)
        ->assertSee('<meta name="apple-mobile-web-app-title" content="Arab UT">', false)
        ->assertSee('<link rel="preload" href="/fonts/thmanyah/thmanyahsans-Regular.woff2" as="font" type="font/woff2" crossorigin>', false)
        ->assertSee('<link rel="preload" href="/fonts/thmanyah/thmanyahsans-Bold.woff2" as="font" type="font/woff2" crossorigin>', false)
        ->assertSee('<link rel="preload" href="/fonts/thmanyah/thmanyahsans-Black.woff2" as="font" type="font/woff2" crossorigin>', false)
        ->assertSee('<link rel="preload" href="/fonts/thmanyah/thmanyahserifdisplay-Bold.woff2" as="font" type="font/woff2" crossorigin>', false)
        ->assertSee('<link rel="preload" href="/images/store/hero/background.avif" as="image" type="image/avif" media="(hover: hover) and (pointer: fine)">', false)
        ->assertSee('<link rel="preload" href="/images/store/hero/background-mobile.avif" as="image" type="image/avif" media="(max-width: 40rem)">', false);
});

test('only the home page preloads the hero backdrop', function () {
    $this->get('/cart')
        ->assertOk()
        ->assertSee('/site.webmanifest', false)
        ->assertDontSee('/images/store/hero/background.avif', false);
});

test('the admin and account shells stay out of the storefront manifest', function () {
    $this->get('/login')
        ->assertOk()
        ->assertDontSee('/site.webmanifest', false)
        ->assertDontSee('thmanyahsans-Regular.woff2', false);
});

test('the web manifest and its icons are served from the public root', function () {
    $manifest = json_decode((string) file_get_contents(public_path('site.webmanifest')), true, 512, JSON_THROW_ON_ERROR);

    expect($manifest['name'])->toBe('Arab UT')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['theme_color'])->toBe('#0d0b08')
        ->and($manifest['dir'])->toBe('rtl')
        ->and(array_column($manifest['icons'], 'sizes'))->toBe(['192x192', '512x512']);

    foreach (['icon-192.png', 'icon-512.png', 'images/store/hero/background.avif', 'images/store/hero/background-mobile.avif', 'images/store/stadium.avif', 'images/store/stadium-mobile.avif'] as $asset) {
        expect(file_exists(public_path($asset)))->toBeTrue($asset);
    }
});
