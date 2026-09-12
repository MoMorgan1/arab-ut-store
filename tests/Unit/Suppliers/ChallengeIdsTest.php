<?php

use App\Suppliers\ChallengeIds;

test('strips SBC- prefix case-insensitively', function () {
    $ids = [
        'SBC-1803b7a6-0000-0000-0000-00000064265f',
        'sbc-1803b7a6-1111-2222-3333-444444444444',
        'Sbc-1803b7a6-5555-6666-7777-888888888888',
    ];

    expect(ChallengeIds::normalize($ids))->toBe([
        '1803b7a6-0000-0000-0000-00000064265f',
        '1803b7a6-1111-2222-3333-444444444444',
        '1803b7a6-5555-6666-7777-888888888888',
    ]);
});

test('lowercases challenge ids', function () {
    $id = '1803B7A6-ABCD-4000-8000-123456789ABC';

    expect(ChallengeIds::normalize([$id]))->toBe([
        '1803b7a6-abcd-4000-8000-123456789abc',
    ]);
});

test('de-duplicates while preserving first-seen order', function () {
    $ids = [
        '1803b7a6-0000-0000-0000-000000000002',
        '1803b7a6-0000-0000-0000-000000000001',
        'SBC-1803B7A6-0000-0000-0000-000000000002',
        '1803b7a6-0000-0000-0000-000000000001',
    ];

    expect(ChallengeIds::normalize($ids))->toBe([
        '1803b7a6-0000-0000-0000-000000000002',
        '1803b7a6-0000-0000-0000-000000000001',
    ]);
});

test('keeps malformed id in permissive parser and drops it in strict normalizer', function () {
    $input = [
        '1803b7a6-0000-0000-0000-000000000001',
        'malformed-sbc-id',
        '1803b7a6-0000-0000-0000-000000000002',
    ];

    $parsed = ChallengeIds::parse($input);
    expect($parsed)->toBe([
        '1803b7a6-0000-0000-0000-000000000001',
        'malformed-sbc-id',
        '1803b7a6-0000-0000-0000-000000000002',
    ]);

    $normalized = ChallengeIds::normalize($input);
    expect($normalized)->toBe([
        '1803b7a6-0000-0000-0000-000000000001',
        '1803b7a6-0000-0000-0000-000000000002',
    ]);
});

test('parses comma-and-space mixed string input', function () {
    $stringInput = "SBC-1803b7a6-0000-0000-0000-000000000001,  1803b7a6-0000-0000-0000-000000000002 \t\n 1803b7a6-0000-0000-0000-000000000003,1803b7a6-0000-0000-0000-000000000004";

    expect(ChallengeIds::normalize($stringInput))->toBe([
        '1803b7a6-0000-0000-0000-000000000001',
        '1803b7a6-0000-0000-0000-000000000002',
        '1803b7a6-0000-0000-0000-000000000003',
        '1803b7a6-0000-0000-0000-000000000004',
    ]);
});

test('skips empty parts and trimmed tokens', function () {
    $input = ['', '   ', 'SBC-', '  1803b7a6-0000-0000-0000-000000000001  '];

    expect(ChallengeIds::normalize($input))->toBe([
        '1803b7a6-0000-0000-0000-000000000001',
    ]);
});
