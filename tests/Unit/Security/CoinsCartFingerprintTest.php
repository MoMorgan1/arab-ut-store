<?php

use App\Security\CoinsCartFingerprint;

test('authenticated fingerprints preserve the exact pre-guest canonical hash', function () {
    $validated = [
        'platform' => 'playstation',
        'delivery' => 'fast',
        'quantity' => 100_000,
        'credentials' => [
            'ea_email' => 'fingerprint-sentinel@example.test',
            'ea_password' => 'Fingerprint Password Sentinel',
            'backup_codes' => ['84000001', '84000002', '84000003', '84000004', '84000005'],
        ],
    ];
    expect(CoinsCartFingerprint::generate('user:17', $validated, 'synthetic-application-key'))
        ->toBe('7583127614832ff7841f94f039d3b876700d727d5516eeca5de25c50b1b91fba')
        ->and(CoinsCartFingerprint::generate('user:17', $validated, 'different-application-key'))
        ->not->toBe('7583127614832ff7841f94f039d3b876700d727d5516eeca5de25c50b1b91fba');
});

test('guest fingerprints use an explicit opaque-owner canonical branch', function () {
    $validated = [
        'platform' => 'playstation',
        'delivery' => 'fast',
        'quantity' => 100_000,
        'credentials' => [
            'ea_email' => 'fingerprint-sentinel@example.test',
            'ea_password' => 'Fingerprint Password Sentinel',
            'backup_codes' => ['84000001', '84000002', '84000003', '84000004', '84000005'],
        ],
    ];

    expect(CoinsCartFingerprint::generate(
        'guest:'.str_repeat('a', 64),
        $validated,
        'synthetic-application-key',
    ))->toBe('7dd090e9c33ac922cf2ebb9e5787565447a3af9f1c04fee46d21e586b3a865a7')
        ->and(fn () => CoinsCartFingerprint::generate(
            'guest:raw-session-id',
            $validated,
            'synthetic-application-key',
        ))->toThrow(InvalidArgumentException::class);
});
