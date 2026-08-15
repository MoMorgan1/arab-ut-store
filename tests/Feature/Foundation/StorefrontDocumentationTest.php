<?php

test('the storefront integration docs use the canonical domain and expose every deployment key', function () {
    $blueprint = file_get_contents(base_path('docs/product/v1-blueprint.md'));
    $discovery = file_get_contents(base_path('docs/product/discovery-record.md'));
    $contract = file_get_contents(base_path('docs/api/n8n-catalog-v1.md'));
    $environment = file_get_contents(base_path('.env.example'));

    expect($blueprint)->toContain('store.arab-ut.com')->not->toContain('shop.arab-ut.com')
        ->and($discovery)->toContain('store.arab-ut.com')->not->toContain('shop.arab-ut.com')
        ->and($contract)
        ->toContain('X-ArabUT-Key', 'X-ArabUT-Timestamp', 'X-ArabUT-Event', 'X-ArabUT-Signature')
        ->and($environment)
        ->toContain('N8N_CATALOG_KEY=', 'N8N_CATALOG_SECRET=', 'N8N_CATALOG_MEDIA_HOSTS=', 'N8N_REVIEWS_URL=')
        ->and(config('services.n8n'))->toHaveKeys(['catalog_key', 'catalog_secret', 'catalog_media_hosts', 'reviews_url']);
});

test('the operational docs define the one-time last-good review archive', function () {
    $runbook = file_get_contents(base_path('docs/operations/storefront-runbook.md'));

    expect($runbook)
        ->toContain('reviews:import-salla-archive')
        ->toContain('reviews:refresh` is not scheduled')
        ->toContain('last-good');
});
