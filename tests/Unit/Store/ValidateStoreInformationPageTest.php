<?php

use App\Actions\Store\ValidateStoreInformationPage;

function validInformationPageTranslation(): array
{
    return [
        'title' => 'Privacy Policy',
        'subtitle' => 'How we handle information.',
        'updated_label' => '12 August 2026',
        'blocks' => [
            ['type' => 'paragraph', 'content' => [['text' => 'Introduction.']]],
            ['type' => 'heading', 'level' => 2, 'text' => 'Details'],
            ['type' => 'list', 'ordered' => true, 'items' => [[['text' => 'Official guide', 'strong' => true, 'url' => 'https://help.ea.com/en/articles/security-and-rules/two-factor-authentication/']]]],
            ['type' => 'notice', 'tone' => 'shield', 'content' => [['text' => 'Keep codes safe.']]],
            ['type' => 'divider'],
        ],
    ];
}

function validInformationPageMeta(): array
{
    return [
        'home' => 'Home',
        'breadcrumb_label' => 'Breadcrumb',
        'updated_label' => 'Last updated',
        'support_title' => 'Have a question?',
        'support_subtitle' => 'Our team is ready to help',
        'support_action' => 'Contact us',
    ];
}

test('it validates and assembles the complete information page contract', function () {
    $page = (new ValidateStoreInformationPage)->validate(
        'privacy',
        validInformationPageTranslation(),
        validInformationPageMeta(),
        'https://wa.me/966537998099',
    );

    expect($page)
        ->toMatchArray([
            'key' => 'privacy',
            'title' => 'Privacy Policy',
            'subtitle' => 'How we handle information.',
            'breadcrumb' => [
                'label' => 'Breadcrumb',
                'home' => 'Home',
                'current' => 'Privacy Policy',
            ],
            'updated' => ['label' => 'Last updated', 'value' => '12 August 2026'],
            'support' => [
                'title' => 'Have a question?',
                'subtitle' => 'Our team is ready to help',
                'action' => 'Contact us',
                'url' => 'https://wa.me/966537998099',
            ],
        ])
        ->and($page['blocks'])->toHaveCount(5);
});

test('it rejects malformed information page contracts', function (Closure $mutate) {
    $translation = validInformationPageTranslation();
    $meta = validInformationPageMeta();
    $supportUrl = 'https://wa.me/966537998099';

    [$translation, $meta, $supportUrl] = $mutate($translation, $meta, $supportUrl);

    expect(fn () => (new ValidateStoreInformationPage)->validate('privacy', $translation, $meta, $supportUrl))
        ->toThrow(LogicException::class);
})->with([
    'missing title' => fn ($page, $meta, $url) => [array_diff_key($page, ['title' => true]), $meta, $url],
    'empty blocks' => fn ($page, $meta, $url) => [[...$page, 'blocks' => []], $meta, $url],
    'unknown block type' => fn ($page, $meta, $url) => [[...$page, 'blocks' => [['type' => 'quote']]], $meta, $url],
    'unsupported heading level' => fn ($page, $meta, $url) => [[...$page, 'blocks' => [['type' => 'heading', 'level' => 4, 'text' => 'Invalid']]], $meta, $url],
    'unsupported notice tone' => fn ($page, $meta, $url) => [[...$page, 'blocks' => [['type' => 'notice', 'tone' => 'success', 'content' => [['text' => 'Invalid']]]]], $meta, $url],
    'non-boolean ordered flag' => fn ($page, $meta, $url) => [[...$page, 'blocks' => [['type' => 'list', 'ordered' => 1, 'items' => [[['text' => 'Invalid']]]]]], $meta, $url],
    'empty content' => fn ($page, $meta, $url) => [[...$page, 'blocks' => [['type' => 'paragraph', 'content' => []]]], $meta, $url],
    'unexpected inline key' => fn ($page, $meta, $url) => [[...$page, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Invalid', 'html' => '<b>bad</b>']]]]], $meta, $url],
    'null inline emphasis' => fn ($page, $meta, $url) => [[...$page, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Invalid', 'strong' => null]]]]], $meta, $url],
    'null inline URL' => fn ($page, $meta, $url) => [[...$page, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Invalid', 'url' => null]]]]], $meta, $url],
    'insecure link' => fn ($page, $meta, $url) => [
        [...$page, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Invalid', 'url' => 'http://help.ea.com/example']]]]],
        $meta,
        $url,
    ],
    'unapproved external host' => fn ($page, $meta, $url) => [
        [...$page, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Invalid', 'url' => 'https://example.com/']]]]],
        $meta,
        $url,
    ],
    'missing metadata' => fn ($page, $meta, $url) => [$page, array_diff_key($meta, ['updated_label' => true]), $url],
    'missing updated label' => fn ($page, $meta, $url) => [array_diff_key($page, ['updated_label' => true]), array_diff_key($meta, ['updated_value' => true]), $url],
    'unapproved support host' => fn ($page, $meta, $url) => [$page, $meta, 'https://example.com/contact'],
]);

test('all ten localized information page contracts pass the server validator', function (string $locale, string $key) {
    $seedData = require dirname(__DIR__, 3)."/database/seeders/data/store_pages/{$locale}.php";
    $metaTranslations = require dirname(__DIR__, 3)."/lang/{$locale}/store_pages.php";

    $page = (new ValidateStoreInformationPage)->validate(
        $key,
        $seedData[$key],
        $metaTranslations['meta'],
        'https://wa.me/966537998099',
    );

    expect($page['key'])->toBe($key)
        ->and($page['blocks'])->not->toBeEmpty();
})->with([
    ['ar', 'privacy'],
    ['ar', 'returns'],
    ['ar', 'warranty'],
    ['ar', 'terms'],
    ['ar', 'ea_backup_codes'],
    ['en', 'privacy'],
    ['en', 'returns'],
    ['en', 'warranty'],
    ['en', 'terms'],
    ['en', 'ea_backup_codes'],
]);
