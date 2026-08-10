<?php

use App\Security\CoinsCartFingerprint;

test('Coins cart fingerprints are keyed canonical hashes of the user and every request field', function () {
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
    $canonical = [
        'user_id' => 17,
        'platform' => 'playstation',
        'delivery' => 'fast',
        'quantity' => 100_000,
        'credentials' => $validated['credentials'],
    ];
    $expected = hash_hmac(
        'sha256',
        json_encode($canonical, JSON_THROW_ON_ERROR),
        'synthetic-application-key',
    );

    expect(CoinsCartFingerprint::generate(17, $validated, 'synthetic-application-key'))->toBe($expected)
        ->and(CoinsCartFingerprint::generate(18, $validated, 'synthetic-application-key'))->not->toBe($expected)
        ->and(CoinsCartFingerprint::generate(17, $validated, 'different-application-key'))->not->toBe($expected);
});
