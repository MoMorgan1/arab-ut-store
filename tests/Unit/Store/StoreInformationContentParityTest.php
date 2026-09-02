<?php

function informationPages(string $locale): array
{
    $data = require dirname(__DIR__, 3)."/database/seeders/data/store_pages/{$locale}.php";

    return [
        'pages' => $data,
        ...$data,
    ];
}

function informationPageText(array $page): string
{
    return json_encode($page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function headingsAtLevel(array $page, int $level): array
{
    return array_values(array_filter(
        $page['blocks'],
        fn (array $block): bool => ($block['type'] ?? null) === 'heading' && ($block['level'] ?? null) === $level,
    ));
}

test('English legal pages preserve the approved Arabic section structure', function (string $page, int $h2Count, int $h3Count) {
    $arabic = informationPages('ar')['pages'][$page];
    $english = informationPages('en')['pages'][$page];

    expect(headingsAtLevel($arabic, 2))->toHaveCount($h2Count)
        ->and(headingsAtLevel($english, 2))->toHaveCount($h2Count)
        ->and(headingsAtLevel($arabic, 3))->toHaveCount($h3Count)
        ->and(headingsAtLevel($english, 3))->toHaveCount($h3Count);
})->with([
    'returns' => ['returns', 7, 0],
    'warranty' => ['warranty', 2, 2],
    'terms' => ['terms', 10, 0],
]);

test('English returns retain every approved key number exclusion and remedy', function () {
    $text = informationPageText(informationPages('en')['pages']['returns']);

    expect($text)
        ->toContain('5%')
        ->toContain('48 hours')
        ->toContain('3 to 14 business days')
        ->toContain('Transfer Market Locked')
        ->toContain('On Hold')
        ->toContain('rank')
        ->toContain('gifts')
        ->toContain('EA Account password');
});

test('English warranty retains the approved periods exclusions and compensation remedies', function () {
    $text = informationPageText(informationPages('en')['pages']['warranty']);

    expect($text)
        ->toContain('192 hours (8 days)')
        ->toContain('72 hours')
        ->toContain('Transfer Market Ban')
        ->toContain('full value of your club')
        ->toContain('half their value')
        ->toContain('not covered by compensation');
});

test('English terms retain the approved store age liability and freelance certificate clauses', function () {
    $text = informationPageText(informationPages('en')['pages']['terms']);

    expect($text)
        ->toContain('Arab UT Store')
        ->toContain('FL-621205220')
        ->toContain('16')
        ->toContain('indirect, incidental, or consequential damages')
        ->toContain('does not exceed the amount actually paid for the order');
});

test('privacy truthfully documents the approved EA credential retention in both locales', function () {
    $arabic = informationPageText(informationPages('ar')['pages']['privacy']);
    $english = informationPageText(informationPages('en')['pages']['privacy']);

    expect($arabic)
        ->toContain('ثلاثة أكواد احتياطية')
        ->toContain('مشفرة أثناء التخزين')
        ->toContain('دون انتهاء تلقائي')
        ->toContain('مالك السلة المتحقق من هويته')
        ->toContain('موظفي التنفيذ المصرح لهم')
        ->toContain('السجلات والتحليلات والاستجابات القابلة للتخزين المؤقت')
        ->toContain('حذف عنصر السلة أو الحساب')
        ->and($english)
        ->toContain('exactly three backup codes')
        ->toContain('encrypted at rest')
        ->toContain('without automatic expiry')
        ->toContain('verified cart owner')
        ->toContain('authorized fulfillment staff')
        ->toContain('logs, analytics, and cacheable responses')
        ->toContain('cart item or account is deleted');
});

test('EA guidance keeps the official path and never asks customers to share credentials', function () {
    foreach (['ar', 'en'] as $locale) {
        $text = informationPageText(informationPages($locale)['pages']['ea_backup_codes']);

        expect($text)
            ->toContain('https://help.ea.com/en/articles/security-and-rules/two-factor-authentication/')
            ->not->toContain('مرة واحدة')
            ->not->toContain('used once')
            ->not->toContain('شارك')
            ->not->toContain('share');
    }
});
