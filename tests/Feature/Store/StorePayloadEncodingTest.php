<?php

/**
 * @return array{0: string, 1: array<string, mixed>} the raw payload and its decoded form
 */
function storePagePayload(string $html): array
{
    expect(preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $html, $matches))
        ->toBe(1);

    return [$matches[1], json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR)];
}

test('the storefront ships its Arabic payload as UTF-8, not escape sequences', function () {
    [$raw, $page] = storePagePayload($this->get('/')->assertOk()->getContent());

    // Escaped, every Arabic letter leaves the server as six bytes instead of
    // two, which roughly doubled the JSON the browser parses before first paint.
    expect($raw)->not->toMatch('/\\\\u0[46][0-9a-f]{2}/i')
        ->and($page['props']['ui']['brand'])->toBeString()->not->toBeEmpty();
});

test('the payload keeps its slashes escaped so it cannot close its own script tag', function () {
    [$raw] = storePagePayload($this->get('/')->assertOk()->getContent());

    // json_encode's default slash escaping is the only thing standing between a
    // "</script>" inside a translation string and a broken document, so
    // JSON_UNESCAPED_UNICODE must never be joined by JSON_UNESCAPED_SLASHES.
    expect($raw)->toContain('"homeUrl":"\/"')
        ->and($raw)->not->toContain('</script>');
});

test('the payload still parses as the page object Inertia expects', function () {
    [, $page] = storePagePayload($this->get('/')->assertOk()->getContent());

    expect($page)
        ->toHaveKeys(['component', 'props', 'url', 'version'])
        ->and($page['component'])->toBe('store/home');
});
