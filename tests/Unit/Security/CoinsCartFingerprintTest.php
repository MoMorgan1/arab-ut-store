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
        ->toBe('ff29acecf3524c75ab9d0b87f4bec088a51bbfd9a1025c2813aa3da60d8b3287')
        ->and(CoinsCartFingerprint::generate('user:17', $validated, 'different-application-key'))
        ->not->toBe('ff29acecf3524c75ab9d0b87f4bec088a51bbfd9a1025c2813aa3da60d8b3287');
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
    ))->toBe('b5deb6967de6970b33226c833f30e5009220570395f1394d1d4a3ae2f9f1eeb7')
        ->and(fn () => CoinsCartFingerprint::generate(
            'guest:raw-session-id',
            $validated,
            'synthetic-application-key',
        ))->toThrow(InvalidArgumentException::class);
});

test('fulfillment confirmations and conditional balance are covered by new fingerprints', function () {
    $validated = [
        'platform' => 'playstation',
        'delivery' => 'fast',
        'quantity' => 100_000,
        'credentials' => [
            'ea_email' => 'fingerprint-sentinel@example.test',
            'ea_password' => 'Fingerprint Password Sentinel',
            'backup_codes' => ['84000001', '84000002', '84000003'],
            'current_balance' => 500_000,
            'companion_market_open' => true,
            'policy_accepted' => true,
        ],
    ];
    $original = CoinsCartFingerprint::generate(
        'user:17',
        $validated,
        'synthetic-application-key',
    );

    $validated['credentials']['current_balance'] = 600_000;

    expect(CoinsCartFingerprint::generate(
        'user:17',
        $validated,
        'synthetic-application-key',
    ))->not->toBe($original);
});
