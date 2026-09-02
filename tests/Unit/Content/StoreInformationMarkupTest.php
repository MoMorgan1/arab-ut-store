<?php

use App\Services\Content\StoreInformationMarkup;

test('toParts(toMarkers($parts)) === $parts for every block on all seeded Arabic and English pages', function (string $locale, string $pageKey) {
    $file = dirname(__DIR__, 2)."/../database/seeders/data/store_pages/{$locale}.php";
    $data = require $file;
    $page = $data[$pageKey];

    foreach ($page['blocks'] as $blockIndex => $block) {
        if ($block['type'] === 'paragraph' || $block['type'] === 'notice') {
            $parts = $block['content'];
            $markers = StoreInformationMarkup::toMarkers($parts);
            $parsed = StoreInformationMarkup::toParts($markers);
            expect($parsed)->toBe($parts, "Failed on {$locale}.{$pageKey} block #{$blockIndex}");
        } elseif ($block['type'] === 'list') {
            foreach ($block['items'] as $itemIndex => $item) {
                $markers = StoreInformationMarkup::toMarkers($item);
                $parsed = StoreInformationMarkup::toParts($markers);
                expect($parsed)->toBe($item, "Failed on {$locale}.{$pageKey} list block #{$blockIndex} item #{$itemIndex}");
            }
        }
    }
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

test('blocksToEditor and blocksFromEditor round-trip every seeded page', function (string $locale, string $pageKey) {
    $file = dirname(__DIR__, 2)."/../database/seeders/data/store_pages/{$locale}.php";
    $data = require $file;
    $blocks = $data[$pageKey]['blocks'];

    $editorBlocks = StoreInformationMarkup::blocksToEditor($blocks);
    $restored = StoreInformationMarkup::blocksFromEditor($editorBlocks);

    expect($restored)->toBe($blocks, "Failed blocks round-trip on {$locale}.{$pageKey}");
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

test('it round-trips literal asterisks and square brackets via escapes', function () {
    $parts = [
        ['text' => 'Price is 5 * 10 = 50 and note [brackets] here.'],
        ['text' => 'bold * with bracket [', 'strong' => true],
        ['text' => 'link with [bracket', 'url' => 'https://help.ea.com'],
    ];

    $markers = StoreInformationMarkup::toMarkers($parts);
    expect($markers)->toContain('\*')
        ->and($markers)->toContain('\[');

    $roundTripped = StoreInformationMarkup::toParts($markers);
    expect($roundTripped)->toBe($parts);
});

test('it preserves whitespace character for character inside parts', function () {
    $parts = [
        ['text' => '  Leading and trailing spaces  ', 'strong' => true],
        ['text' => "  \n  newlines and tabs \t "],
    ];

    $markers = StoreInformationMarkup::toMarkers($parts);
    $parsed = StoreInformationMarkup::toParts($markers);

    expect($parsed)->toBe($parts);
});

test('it merges adjacent plain parts into one', function () {
    $parts = [
        ['text' => 'Hello '],
        ['text' => 'World!'],
    ];

    $markers = StoreInformationMarkup::toMarkers($parts);
    expect($markers)->toBe('Hello World!');

    $parsed = StoreInformationMarkup::toParts($markers);
    expect($parsed)->toBe([
        ['text' => 'Hello World!'],
    ]);
});

test('it rejects a part that is both bold and a link in toMarkers', function () {
    $parts = [
        ['text' => 'Both', 'strong' => true, 'url' => 'https://help.ea.com'],
    ];

    expect(fn () => StoreInformationMarkup::toMarkers($parts))
        ->toThrow(InvalidArgumentException::class, 'A part cannot be both bold and a link.');
});

test('it rejects a part that is both bold and a link in toParts', function () {
    expect(fn () => StoreInformationMarkup::toParts('**[link](https://help.ea.com)**'))
        ->toThrow(InvalidArgumentException::class, 'A part cannot be both bold and a link.');

    expect(fn () => StoreInformationMarkup::toParts('[**bold label**](https://help.ea.com)'))
        ->toThrow(InvalidArgumentException::class, 'A part cannot be both bold and a link.');
});

test('it rejects empty blocks on editor conversion', function () {
    expect(fn () => StoreInformationMarkup::blocksFromEditor([
        ['type' => 'paragraph', 'text' => '   '],
    ]))->toThrow(InvalidArgumentException::class, 'cannot be empty');

    expect(fn () => StoreInformationMarkup::blocksFromEditor([
        ['type' => 'heading', 'level' => 2, 'text' => ''],
    ]))->toThrow(InvalidArgumentException::class, 'cannot be empty');

    expect(fn () => StoreInformationMarkup::blocksFromEditor([
        ['type' => 'notice', 'tone' => 'info', 'text' => '   '],
    ]))->toThrow(InvalidArgumentException::class, 'cannot be empty');

    expect(fn () => StoreInformationMarkup::blocksFromEditor([
        ['type' => 'list', 'ordered' => false, 'text' => "item 1\n   \nitem 2"],
    ]))->toThrow(InvalidArgumentException::class, 'cannot be empty');
});

test('it rejects invalid heading level or notice tone', function () {
    expect(fn () => StoreInformationMarkup::blocksFromEditor([
        ['type' => 'heading', 'level' => 4, 'text' => 'Heading'],
    ]))->toThrow(InvalidArgumentException::class, 'heading level must be 2 or 3.');

    expect(fn () => StoreInformationMarkup::blocksFromEditor([
        ['type' => 'notice', 'tone' => 'invalid', 'text' => 'Notice'],
    ]))->toThrow(InvalidArgumentException::class, 'notice tone must be info, shield, or warning.');
});
