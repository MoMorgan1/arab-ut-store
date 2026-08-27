<?php

declare(strict_types=1);

use App\Support\Seo\StoreCanonicalUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Resolve the canonical payload the way the Blade layout does: by dispatching a
 * real request through the router so the resolved route carries its own name
 * and parameters.
 *
 * @return array{canonical: string, alternates: array<string, string>}|null
 */
function canonicalFor(string $uri): ?array
{
    $resolved = null;

    Route::getRoutes()->refreshNameLookups();

    $request = Request::create($uri);
    $route = Route::getRoutes()->match($request);
    $request->setRouteResolver(fn () => $route);

    $locale = str_starts_with(ltrim($uri, '/'), 'en') ? 'en' : 'ar';
    app()->setLocale($locale);

    $resolved = StoreCanonicalUrls::forRequest($request);

    return $resolved;
}

it('canonicalises the unprefixed Arabic home page to itself', function () {
    $seo = canonicalFor('/');

    expect($seo)->not->toBeNull()
        ->and($seo['canonical'])->toBe(url('/'))
        ->and($seo['alternates']['x-default'])->toBe($seo['alternates']['ar']);
});

it('points the explicit Arabic prefix back at the unprefixed URL', function () {
    // `/ar/sbc` and `/sbc` serve identical content; only one may be canonical.
    $prefixed = canonicalFor('/ar/sbc');
    $unprefixed = canonicalFor('/sbc');

    expect($prefixed)->not->toBeNull()
        ->and($prefixed['canonical'])->toBe($unprefixed['canonical'])
        ->and($prefixed['canonical'])->not->toContain('/ar/');
});

it('canonicalises English pages to the /en prefix', function () {
    $seo = canonicalFor('/en/sbc');

    expect($seo['canonical'])->toContain('/en/sbc')
        ->and($seo['alternates']['en'])->toBe($seo['canonical'])
        ->and($seo['alternates']['ar'])->not->toContain('/en/');
});

it('keeps the product slug in both hreflang alternates', function () {
    $seo = canonicalFor('/sbc/example-product');

    expect($seo)->not->toBeNull()
        ->and($seo['alternates']['ar'])->toContain('/sbc/example-product')
        ->and($seo['alternates']['en'])->toContain('/en/sbc/example-product');
});

it('emits no canonical for cart, auth, or account routes', function (string $uri) {
    expect(canonicalFor($uri))->toBeNull();
})->with(['/cart', '/login', '/en/cart', '/en/login']);

it('never leaks a route default into the canonical as a query string', function (string $uri) {
    // These routes carry `->defaults('service', ...)` and
    // `->defaults('storePage', ...)`. Route::parameters() returns those too, and
    // passing them to route() appends `?service=sbc`, which makes the canonical
    // disagree with the clean URL the sitemap submits.
    $seo = canonicalFor($uri);

    expect($seo)->not->toBeNull()
        ->and($seo['canonical'])->not->toContain('?')
        ->and($seo['alternates']['ar'])->not->toContain('?')
        ->and($seo['alternates']['en'])->not->toContain('?');
})->with(['/', '/sbc', '/objectives', '/privacy', '/terms', '/fut-champions', '/rivals', '/reviews']);

it('canonicalises each store page to its own exact URL', function () {
    // toBe, not toContain: a trailing query string would slip past containment.
    expect(canonicalFor('/sbc')['canonical'])->toBe(url('/sbc'))
        ->and(canonicalFor('/privacy')['canonical'])->toBe(url('/privacy'))
        ->and(canonicalFor('/en/sbc')['canonical'])->toBe(url('/en/sbc'));
});

it('keeps the product slug and nothing else in a product canonical', function () {
    $seo = canonicalFor('/sbc/example-product');

    expect($seo['canonical'])->toBe(url('/sbc/example-product'))
        ->and($seo['alternates']['en'])->toBe(url('/en/sbc/example-product'));
});
