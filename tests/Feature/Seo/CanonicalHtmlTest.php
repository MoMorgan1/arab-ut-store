<?php

declare(strict_types=1);

/**
 * These assert against the raw HTML response body, because the whole point of
 * rendering canonical/hreflang server-side is that crawlers which never execute
 * JavaScript still receive them.
 */
it('serves canonical and hreflang in the raw HTML of the Arabic home page', function () {
    $response = $this->get('/');

    $response->assertOk();

    $html = $response->getContent();

    expect($html)
        ->toContain('<link rel="canonical"')
        ->toContain('hreflang="ar"')
        ->toContain('hreflang="en"')
        ->toContain('hreflang="x-default"');
});

it('serves the English canonical in the raw HTML of the English home page', function () {
    $html = $this->get('/en')->assertOk()->getContent();

    expect($html)->toContain('<link rel="canonical" href="'.url('/en').'"');
});

it('does not advertise a canonical on the cart page', function () {
    $html = $this->get('/cart')->assertOk()->getContent();

    expect($html)->not->toContain('rel="canonical"');
});

it('serves the social preview tags a WhatsApp share needs in the raw HTML', function () {
    // The regression this guards: these tags used to be injected by React, so
    // scrapers that never run JavaScript saw a title and nothing else.
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('<meta name="description"')
        ->toContain('property="og:title"')
        ->toContain('property="og:description"')
        ->toContain('property="og:image"')
        ->toContain('name="twitter:card"')
        ->toContain('application/ld+json');
});

it('emits exactly one of each social tag so the head is not duplicated', function () {
    $html = $this->get('/')->assertOk()->getContent();

    foreach (['og:title', 'og:description', 'og:image', 'twitter:card'] as $tag) {
        expect(substr_count($html, '"'.$tag.'"'))->toBe(1, "duplicated {$tag}");
    }

    expect(substr_count($html, 'application/ld+json'))->toBe(1)
        ->and(substr_count($html, 'rel="canonical"'))->toBe(1);
});

it('keeps social metadata off non-store pages', function () {
    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)->not->toContain('property="og:title"');
});
